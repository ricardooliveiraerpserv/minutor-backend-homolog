<?php

namespace App\Services;

use App\Models\ConsultantSkill;
use App\Models\Skill;
use App\Models\SkillLevel;
use App\Models\SkillMatrixVersion;
use App\Models\SkillMatrixVersionItem;
use App\Models\SkillRespondent;
use App\Models\SkillSubmission;
use App\Models\SkillSubmissionAnswer;
use App\Models\SkillSurvey;
use App\Models\SkillSurveyInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Motor do Banco de Competências: matriz única + versão, respondentes das três
 * origens, submissão imutável com autosave/retomada, e derivação da matriz viva
 * (consultant_skills) a partir de uma avaliação interna.
 */
class SkillSurveyService
{
    /** Token estável da pesquisa PERENE de auto-avaliação (singleton). */
    public const SELF_SURVEY_TOKEN = 'AUTOAVAL';

    /**
     * Schema dos campos cadastrais por tipo de pesquisa — o front usa para
     * renderizar os dados ANTES da matriz. A matriz é a mesma para todos.
     */
    public const CADASTRAL_SCHEMA = [
        'internal' => [
            ['key' => 'name',            'label' => 'Nome',              'type' => 'text',  'readonly' => true],
            ['key' => 'email',           'label' => 'E-mail',            'type' => 'email', 'readonly' => true],
            ['key' => 'cpf',             'label' => 'CPF',               'type' => 'text'],
            ['key' => 'data_nascimento', 'label' => 'Data de nascimento', 'type' => 'date'],
            ['key' => 'cep',             'label' => 'CEP',               'type' => 'cep'],
            ['key' => 'logradouro',      'label' => 'Logradouro',        'type' => 'text'],
            ['key' => 'numero',          'label' => 'Número',            'type' => 'text'],
            ['key' => 'complemento',     'label' => 'Complemento',       'type' => 'text'],
            ['key' => 'bairro',          'label' => 'Bairro',            'type' => 'text'],
            ['key' => 'cidade',          'label' => 'Cidade',            'type' => 'text'],
            ['key' => 'estado',          'label' => 'Estado (UF)',       'type' => 'text'],
        ],
        'partner' => [
            ['key' => 'empresa',         'label' => 'Empresa Parceira',    'type' => 'text',   'required' => true],
            ['key' => 'name',            'label' => 'Nome',                'type' => 'text',   'required' => true],
            ['key' => 'email',           'label' => 'E-mail',              'type' => 'email',  'required' => true],
            ['key' => 'phone',           'label' => 'Telefone',            'type' => 'text',   'required' => true],
            ['key' => 'disponibilidade', 'label' => 'Disponibilidade',     'type' => 'select', 'options' => ['Imediata', 'Em 15 dias', 'Em 30 dias', 'A combinar'], 'required' => true],
            ['key' => 'modalidade',      'label' => 'Modalidade de atuação', 'type' => 'select', 'options' => ['Remoto', 'Presencial', 'Ambos'], 'required' => true],
        ],
        'candidate' => [
            ['key' => 'name',               'label' => 'Nome completo',      'type' => 'text',  'required' => true],
            ['key' => 'phone',              'label' => 'Celular',            'type' => 'phone', 'required' => true],
            ['key' => 'email',              'label' => 'E-mail',             'type' => 'email', 'required' => true],
            ['key' => 'cpf',                'label' => 'CPF',                'type' => 'cpf',   'required' => true],
            ['key' => 'cep',                'label' => 'CEP',                'type' => 'cep',   'required' => true],
            ['key' => 'logradouro',         'label' => 'Endereço',           'type' => 'text',  'required' => true, 'auto' => true],
            ['key' => 'bairro',             'label' => 'Bairro',             'type' => 'text',  'required' => true, 'auto' => true],
            ['key' => 'cidade',             'label' => 'Cidade',             'type' => 'text',  'required' => true, 'auto' => true],
            ['key' => 'estado',             'label' => 'Estado (UF)',        'type' => 'text',  'required' => true, 'auto' => true],
            ['key' => 'numero',             'label' => 'Número',             'type' => 'text',  'required' => true],
            ['key' => 'complemento',        'label' => 'Complemento',        'type' => 'text',  'require_unless' => 'sem_complemento'],
            ['key' => 'sem_complemento',    'label' => 'Sem complemento',    'type' => 'boolean'],
            ['key' => 'linkedin',           'label' => 'LinkedIn',           'type' => 'text'],
            ['key' => 'curriculo',          'label' => 'Currículo (anexar PDF/DOC)', 'type' => 'file', 'required' => true],
            ['key' => 'valor_tipo',         'label' => 'Você trabalha com',  'type' => 'select', 'options' => ['Valor Hora', 'Valor Fixo'], 'required' => true],
            ['key' => 'valor',              'label' => 'Valor praticado',    'type' => 'money', 'required' => true],
            ['key' => 'disponibilidade',    'label' => 'Disponibilidade',    'type' => 'select', 'options' => ['Imediata', 'Em 15 dias', 'Em 30 dias', 'A combinar'], 'required' => true],
            ['key' => 'modalidade',         'label' => 'Modalidade de atuação', 'type' => 'select', 'options' => ['Remoto', 'Presencial', 'Ambos'], 'required' => true],
        ],
    ];

    /** Ordem das categorias no wizard/snapshot. */
    public const CATEGORY_ORDER = ['Protheus', 'App TOTVS', 'Linguagens', 'Backend', 'Frontend', 'Banco de Dados', 'Infraestrutura', 'Ferramentas'];

    /** @var array<string, array> cache por request */
    private array $schemaCache = [];

    /**
     * Schema cadastral EFETIVO de um tipo — lê a config do banco
     * (skill_form_configs, editável pelo admin) com fallback pro DEFAULT do código.
     */
    public function schemaFor(string $type): array
    {
        if (isset($this->schemaCache[$type])) {
            return $this->schemaCache[$type];
        }
        $fields = null;
        try {
            $fields = \App\Models\SkillFormConfig::where('type', $type)->value('fields');
        } catch (\Throwable $e) {
            $fields = null; // tabela pode não existir ainda → fallback
        }

        $schema = (is_array($fields) && $fields) ? $fields : (self::CADASTRAL_SCHEMA[$type] ?? []);

        return $this->schemaCache[$type] = $this->normalizeSchema($schema);
    }

    /**
     * Invariantes do schema (protege contra configs salvos com flags incoerentes):
     *  - checkbox (boolean) NUNCA é "obrigatório" no sentido de precisar de valor;
     *  - campo com require_unless é condicional → não é obrigatório fixo (a condição manda).
     */
    private function normalizeSchema(array $fields): array
    {
        return array_map(function ($f) {
            if (($f['type'] ?? '') === 'boolean') {
                unset($f['required']);
            }
            if (! empty($f['require_unless'])) {
                $f['required'] = false;
            }

            return $f;
        }, $fields);
    }

    /** Todos os schemas efetivos (p/ meta da tela de Nova Pesquisa). */
    public function allSchemas(): array
    {
        return [
            'internal' => $this->schemaFor('internal'),
            'partner' => $this->schemaFor('partner'),
            'candidate' => $this->schemaFor('candidate'),
        ];
    }

    /** Versão ativa da matriz (ou a mais recente). */
    public function activeVersion(): ?SkillMatrixVersion
    {
        return SkillMatrixVersion::active()->latest('number')->first()
            ?? SkillMatrixVersion::latest('number')->first();
    }

    /**
     * Publica uma NOVA versão da matriz: congela as competências atuais em
     * skill_matrix_version_items e arquiva a versão ativa anterior. As respostas
     * já enviadas permanecem vinculadas à versão que usaram (imutável).
     */
    public function publishVersion(?string $label = null): SkillMatrixVersion
    {
        return DB::transaction(function () use ($label) {
            $order = array_flip(self::CATEGORY_ORDER);
            $skills = Skill::orderBy('category')->orderBy('name')->get()
                ->sortBy(fn ($s) => sprintf('%02d_%s', $order[$s->category] ?? 99, $s->name))
                ->values();

            $version = SkillMatrixVersion::create([
                'number' => (int) (SkillMatrixVersion::max('number') ?? 0) + 1,
                'label' => $label ?: 'Matriz ' . date('Y'),
                'status' => SkillMatrixVersion::STATUS_ACTIVE,
                'skills_count' => $skills->count(),
                'published_at' => now(),
            ]);

            $sort = 0;
            foreach ($skills as $s) {
                SkillMatrixVersionItem::create([
                    'matrix_version_id' => $version->id,
                    'skill_id' => $s->id,
                    'category' => $s->category,
                    'name' => $s->name,
                    'skill_type' => $s->type,
                    'sort_order' => $sort++,
                ]);
            }

            SkillMatrixVersion::where('id', '!=', $version->id)
                ->where('status', SkillMatrixVersion::STATUS_ACTIVE)
                ->update(['status' => SkillMatrixVersion::STATUS_ARCHIVED]);

            return $version;
        });
    }

    /**
     * Payload da matriz de uma versão: competências agrupadas por categoria
     * (na ordem do wizard) + os níveis disponíveis (inclui "Nenhum conhecimento").
     */
    public function matrixPayload(SkillMatrixVersion $version): array
    {
        $items = $version->items()->get();
        $sections = $items
            ->groupBy('category')
            ->map(fn ($group, $category) => [
                'category' => $category,
                'count' => $group->count(),
                'items' => $group->map(fn ($it) => [
                    'id' => $it->id,
                    'skill_id' => $it->skill_id,
                    'name' => $it->name,
                    'category' => $it->category,
                    'sort_order' => $it->sort_order,
                ])->values(),
            ])
            ->values();

        return [
            'version' => [
                'id' => $version->id,
                'number' => $version->number,
                'label' => $version->label,
                'skills_count' => $version->skills_count,
            ],
            'sections' => $sections,
            'total_items' => $items->count(),
            'levels' => SkillLevel::orderBy('weight')->get(['id', 'name', 'weight']),
        ];
    }

    /** Normaliza texto (colapsa espaços/quebras, corta) — dados de `users` podem vir sujos. */
    public function clean(?string $value, int $max): ?string
    {
        $v = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** Respondente interno (colaborador) — find or create pelo user_id. */
    public function internalRespondent(User $user): SkillRespondent
    {
        return SkillRespondent::firstOrCreate(
            ['type' => SkillRespondent::TYPE_INTERNAL, 'user_id' => $user->id],
            ['name' => $this->clean($user->name, 160) ?? 'Colaborador', 'email' => $this->clean($user->email, 190)]
        );
    }

    /** Dados cadastrais pré-preenchidos do colaborador (do próprio cadastro de usuário). */
    public function internalCadastral(User $user): array
    {
        $birth = $user->birth_date;
        $birth = $birth instanceof \DateTimeInterface ? $birth->format('Y-m-d') : ($birth ? (string) $birth : null);

        return [
            'name' => $this->clean($user->name, 160),
            'email' => $this->clean($user->email, 190),
            'cpf' => $user->cpf,
            'data_nascimento' => $birth,
            'cep' => $user->cep,
            'logradouro' => $user->address_street,
            'numero' => $user->address_number,
            'complemento' => $user->address_complement,
            'bairro' => $user->neighborhood,
            'cidade' => $user->city,
            'estado' => $user->state,
        ];
    }

    /**
     * Mapa campo do formulário (cadastral) → coluna do cadastro de usuário. Só os
     * campos que persistem no `users`; os demais (gestor, etc.) ficam só na submissão.
     */
    public const USER_CADASTRAL_MAP = [
        'cpf'             => 'cpf',
        'data_nascimento' => 'birth_date',
        'cep'             => 'cep',
        'logradouro'      => 'address_street',
        'numero'          => 'address_number',
        'complemento'     => 'address_complement',
        'bairro'          => 'neighborhood',
        'cidade'          => 'city',
        'estado'          => 'state',
    ];

    /**
     * Grava os dados cadastrais informados na avaliação INTERNA no cadastro do
     * usuário (CPF, data de nascimento, CEP e endereço). Só sobrescreve com valores
     * preenchidos (não zera o que já existe).
     */
    protected function persistUserCadastral(SkillSubmission $submission): void
    {
        $respondent = $submission->respondent;
        if (! $respondent || $respondent->type !== SkillRespondent::TYPE_INTERNAL || ! $respondent->user_id) {
            return;
        }
        $cad = $submission->cadastral;
        if (! is_array($cad) || empty($cad)) {
            return;
        }
        $user = User::find($respondent->user_id);
        if (! $user) {
            return;
        }

        $update = [];
        foreach (self::USER_CADASTRAL_MAP as $field => $col) {
            if (! array_key_exists($field, $cad)) {
                continue;
            }
            $val = $cad[$field];
            if ($val === null || $val === '') {
                continue;
            }
            if ($field === 'cpf' || $field === 'cep') {
                $val = preg_replace('/\D+/', '', (string) $val);
            }
            if ($field === 'data_nascimento') {
                try {
                    $val = \Illuminate\Support\Carbon::parse((string) $val)->toDateString();
                } catch (\Throwable) {
                    continue;
                }
            }
            $update[$col] = $val;
        }

        if ($update) {
            $user->forceFill($update)->save();
        }
    }

    /** Sanitiza o cadastral conforme o schema (phone/cep viram só dígitos). */
    public function sanitizeCadastral(string $type, array $cadastral): array
    {
        foreach ($this->schemaFor($type) as $f) {
            $k = $f['key'];
            if (! array_key_exists($k, $cadastral) || $cadastral[$k] === null) {
                continue;
            }
            if (in_array($f['type'], ['phone', 'cep', 'cpf'], true)) {
                $cadastral[$k] = preg_replace('/\D+/', '', (string) $cadastral[$k]);
            }
        }

        return $cadastral;
    }

    /** Regras de validação do cadastral por tipo (formato de celular/e-mail/CEP/select). */
    public function cadastralRules(string $type): array
    {
        $rules = [];
        foreach ($this->schemaFor($type) as $f) {
            $key = 'cadastral.' . $f['key'];
            $r = [! empty($f['required']) ? 'required' : 'nullable'];
            switch ($f['type']) {
                case 'phone':  $r[] = 'regex:/^\d{2}9\d{8}$/'; break; // celular BR: DDD + 9 + 8 dígitos
                case 'email':  $r[] = 'email:rfc'; break;
                case 'cep':    $r[] = 'regex:/^\d{8}$/'; break;
                case 'cpf':
                    $r[] = function ($attr, $value, $fail) {
                        if ($value !== null && $value !== '' && ! self::validCpf((string) $value)) {
                            $fail('CPF inválido.');
                        }
                    };
                    break;
                case 'money':  $r[] = 'string'; break;
                case 'file':   $r[] = 'array'; break; // ref {name, path, size} pós-upload
                case 'select':
                    if (! empty($f['options'])) {
                        $r[] = Rule::in($f['options']);
                    }
                    break;
            }
            $rules[$key] = $r;
        }

        return $rules;
    }

    /** Valida CPF pelos dígitos verificadores (formato válido, não consulta a Receita). */
    public static function validCpf(?string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', (string) $cpf);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }

    /**
     * Inicia ou retoma a submissão em andamento de um respondente numa pesquisa.
     * Uma submissão in_progress por (survey, respondent). Não recria após envio.
     */
    public function startOrResume(
        SkillSurvey $survey,
        SkillRespondent $respondent,
        ?SkillSurveyInvite $invite = null,
        array $cadastral = [],
        array $meta = []
    ): SkillSubmission {
        $existing = SkillSubmission::where('survey_id', $survey->id)
            ->where('respondent_id', $respondent->id)
            ->orderByDesc('id')
            ->first();

        // Já enviou → devolve a submissão enviada (imutável).
        if ($existing && $existing->isSubmitted()) {
            return $existing;
        }
        if ($existing) {
            return $existing;
        }

        $submission = SkillSubmission::create([
            'survey_id' => $survey->id,
            'respondent_id' => $respondent->id,
            'matrix_version_id' => $survey->matrix_version_id,
            'invite_id' => $invite?->id,
            'status' => SkillSubmission::STATUS_IN_PROGRESS,
            'cadastral' => $cadastral ?: null,
            'progress' => ['current_step' => 0, 'answered' => 0],
            'started_at' => now(),
            'ip' => $meta['ip'] ?? null,
            'user_agent' => isset($meta['user_agent']) ? substr((string) $meta['user_agent'], 0, 255) : null,
        ]);

        // Interno com histórico → pré-preenche com a última avaliação (experiência
        // de ATUALIZAÇÃO tanto na auto-avaliação quanto em campanhas). Primeira vez
        // (sem submissão anterior) começa em branco.
        if ($respondent->type === SkillRespondent::TYPE_INTERNAL && $respondent->user_id) {
            $this->prefillFromLastSubmission($submission);
            $submission = $submission->fresh();
        }

        if ($invite) {
            $invite->forceFill([
                'status' => SkillSurveyInvite::STATUS_STARTED,
                'started_at' => $invite->started_at ?? now(),
                'last_access_at' => now(),
                'submission_id' => $submission->id,
            ])->save();
        }

        return $submission;
    }

    /**
     * Pesquisa PERENE de auto-avaliação (singleton). Sempre aberta e apontando
     * para a versão ATIVA da matriz. É por ela que o colaborador atualiza as
     * próprias competências a qualquer momento (fora de campanha).
     */
    public function selfAssessmentSurvey(): SkillSurvey
    {
        $version = $this->activeVersion();

        $survey = SkillSurvey::firstOrCreate(
            ['public_token' => self::SELF_SURVEY_TOKEN],
            [
                'type' => SkillSurvey::TYPE_INTERNAL,
                'title' => 'Auto-avaliação de Competências',
                'description' => 'Mantenha suas competências sempre atualizadas.',
                'matrix_version_id' => $version?->id,
                'status' => SkillSurvey::STATUS_OPEN,
                'allow_public' => false,
            ]
        );

        // Mantém a pesquisa sempre aberta e na versão ativa da matriz.
        $dirty = false;
        if ($version && $survey->matrix_version_id !== $version->id) {
            $survey->matrix_version_id = $version->id;
            $dirty = true;
        }
        if ($survey->status !== SkillSurvey::STATUS_OPEN) {
            $survey->status = SkillSurvey::STATUS_OPEN;
            $dirty = true;
        }
        if ($dirty) {
            $survey->save();
        }

        return $survey->loadMissing('matrixVersion');
    }

    /**
     * Abre a auto-avaliação para o colaborador atualizar as próprias competências.
     * Retoma um rascunho em andamento; caso contrário cria uma NOVA submissão de
     * atualização já pré-preenchida com o perfil atual (última submissão enviada),
     * para o colaborador editar apenas o que mudou.
     *
     * @return array{0: SkillSurvey, 1: SkillSubmission, 2: SkillSurveyInvite}
     */
    public function openSelfUpdate(User $user, array $meta = []): array
    {
        $survey = $this->selfAssessmentSurvey();
        abort_if(! $survey->matrix_version_id, 422, 'A matriz de competências ainda não foi publicada.');

        $respondent = $this->internalRespondent($user);

        $invite = SkillSurveyInvite::firstOrCreate(
            ['survey_id' => $survey->id, 'user_id' => $user->id],
            [
                'respondent_id' => $respondent->id,
                'email' => $this->clean($user->email, 190),
                'name' => $this->clean($user->name, 160),
                'status' => SkillSurveyInvite::STATUS_OPENED,
                'opened_at' => now(),
            ]
        );

        $latest = SkillSubmission::where('survey_id', $survey->id)
            ->where('respondent_id', $respondent->id)
            ->orderByDesc('id')
            ->first();

        // Rascunho em andamento → retoma.
        if ($latest && ! $latest->isSubmitted()) {
            $invite->forceFill(['last_access_at' => now(), 'submission_id' => $latest->id])->save();

            return [$survey, $latest, $invite];
        }

        // Nova submissão de atualização, pré-preenchida com o perfil atual.
        $submission = SkillSubmission::create([
            'survey_id' => $survey->id,
            'respondent_id' => $respondent->id,
            'matrix_version_id' => $survey->matrix_version_id,
            'invite_id' => $invite->id,
            'status' => SkillSubmission::STATUS_IN_PROGRESS,
            'cadastral' => null,
            'progress' => ['current_step' => 0, 'answered' => 0],
            'started_at' => now(),
            'ip' => $meta['ip'] ?? null,
            'user_agent' => isset($meta['user_agent']) ? substr((string) $meta['user_agent'], 0, 255) : null,
        ]);

        $this->prefillFromLastSubmission($submission);

        $invite->forceFill([
            'status' => SkillSurveyInvite::STATUS_STARTED,
            'started_at' => $invite->started_at ?? now(),
            'last_access_at' => now(),
            'submission_id' => $submission->id,
        ])->save();

        return [$survey, $submission->fresh(), $invite];
    }

    /**
     * Copia as respostas da última submissão ENVIADA do respondente para a nova
     * submissão (casando por skill_id, robusto a mudança de versão de matriz), de
     * modo que a atualização já venha com os níveis atuais preenchidos.
     */
    protected function prefillFromLastSubmission(SkillSubmission $submission): void
    {
        $prior = SkillSubmission::where('respondent_id', $submission->respondent_id)
            ->where('status', SkillSubmission::STATUS_SUBMITTED)
            ->where('id', '!=', $submission->id)
            ->orderByDesc('submitted_at')
            ->first();
        if (! $prior) {
            return;
        }

        // Reaproveita os dados cadastrais (cargo/equipe/gestor) da última avaliação.
        if (! empty($prior->cadastral) && is_array($prior->cadastral)) {
            $submission->cadastral = $prior->cadastral;
            $submission->save();
        }

        $priorBySkill = SkillSubmissionAnswer::where('submission_id', $prior->id)
            ->whereNotNull('skill_id')
            ->whereNotNull('level_id')
            ->get()
            ->keyBy('skill_id');
        if ($priorBySkill->isEmpty()) {
            return;
        }

        $items = SkillMatrixVersionItem::where('matrix_version_id', $submission->matrix_version_id)->get();
        foreach ($items as $it) {
            $prev = $priorBySkill->get($it->skill_id);
            if (! $prev) {
                continue;
            }
            SkillSubmissionAnswer::updateOrCreate(
                ['submission_id' => $submission->id, 'matrix_version_item_id' => $it->id],
                [
                    'skill_id' => $it->skill_id,
                    'level_id' => $prev->level_id,
                    'level_weight' => $prev->level_weight,
                    'years_experience' => $prev->years_experience,
                    'atuacao' => $prev->atuacao,
                    'notes' => null,
                ]
            );
        }

        $answered = SkillSubmissionAnswer::where('submission_id', $submission->id)
            ->whereNotNull('level_id')->count();
        $submission->progress = array_merge($submission->progress ?? [], ['answered' => $answered]);
        $submission->save();
    }

    /**
     * Autosave: grava respostas parciais + estado do wizard. Bloqueado após
     * o envio (submissão imutável).
     *
     * @param  array{cadastral?: array, current_step?: int, answers?: array<int, array>}  $payload
     */
    public function autosave(SkillSubmission $submission, array $payload): SkillSubmission
    {
        if ($submission->isSubmitted()) {
            throw ValidationException::withMessages(['submission' => 'Avaliação já enviada — não pode ser alterada.']);
        }

        DB::transaction(function () use ($submission, $payload) {
            if (array_key_exists('cadastral', $payload) && is_array($payload['cadastral'])) {
                $submission->cadastral = array_merge($submission->cadastral ?? [], $payload['cadastral']);
            }

            $levelWeights = SkillLevel::pluck('weight', 'id');
            foreach ($payload['answers'] ?? [] as $ans) {
                if (empty($ans['item_id'])) {
                    continue;
                }
                $levelId = $ans['level_id'] ?? null;
                SkillSubmissionAnswer::updateOrCreate(
                    ['submission_id' => $submission->id, 'matrix_version_item_id' => $ans['item_id']],
                    [
                        'skill_id' => $ans['skill_id'] ?? null,
                        'level_id' => $levelId,
                        'level_weight' => $levelId ? ($levelWeights[$levelId] ?? null) : null,
                        'years_experience' => $ans['years_experience'] ?? null,
                        'atuacao' => $ans['atuacao'] ?? null,
                        'notes' => $ans['notes'] ?? null,
                    ]
                );
            }

            $answered = SkillSubmissionAnswer::where('submission_id', $submission->id)
                ->whereNotNull('level_id')->count();
            $submission->progress = array_merge($submission->progress ?? [], [
                'current_step' => $payload['current_step'] ?? ($submission->progress['current_step'] ?? 0),
                'answered' => $answered,
                'saved_at' => now()->toISOString(),
            ]);
            if (! $submission->started_at) {
                $submission->started_at = now();
            }
            $submission->save();
        });

        if ($submission->invite_id && ($invite = $submission->invite)) {
            $invite->forceFill(['last_access_at' => now()])->save();
            if ($invite->status === SkillSurveyInvite::STATUS_PENDING || $invite->status === SkillSurveyInvite::STATUS_SENT || $invite->status === SkillSurveyInvite::STATUS_OPENED) {
                $invite->forceFill(['status' => SkillSurveyInvite::STATUS_STARTED, 'started_at' => $invite->started_at ?? now()])->save();
            }
        }

        return $submission->fresh('answers');
    }

    /**
     * Envio final: exige TODAS as competências respondidas (nenhuma em branco —
     * "Nenhum conhecimento" é resposta válida). Congela a submissão e deriva a
     * matriz viva quando o respondente é interno.
     */
    public function submit(SkillSubmission $submission): SkillSubmission
    {
        if ($submission->isSubmitted()) {
            return $submission;
        }

        $totalItems = $submission->matrixVersion->items()->count();
        $answered = SkillSubmissionAnswer::where('submission_id', $submission->id)
            ->whereNotNull('level_id')->count();

        if ($answered < $totalItems) {
            $pending = $this->pendingByCategory($submission);
            throw ValidationException::withMessages([
                'answers' => "Existem {$totalItems} competências e {$answered} respondidas. Responda todas antes de enviar.",
                'pending' => $pending,
            ]);
        }

        DB::transaction(function () use ($submission) {
            $submission->forceFill([
                'status' => SkillSubmission::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ])->save();

            if ($submission->invite_id && ($invite = $submission->invite)) {
                $invite->forceFill([
                    'status' => SkillSurveyInvite::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'submission_id' => $submission->id,
                ])->save();
            }

            $this->deriveConsultantSkills($submission);
            $this->persistUserCadastral($submission);
        });

        return $submission->fresh();
    }

    /** Contagem de pendências por categoria (para a tela de Revisão). */
    public function pendingByCategory(SkillSubmission $submission): array
    {
        $answeredItemIds = SkillSubmissionAnswer::where('submission_id', $submission->id)
            ->whereNotNull('level_id')->pluck('matrix_version_item_id')->all();

        return $submission->matrixVersion->items()
            ->get()
            ->groupBy('category')
            ->map(function ($group, $category) use ($answeredItemIds) {
                $pending = $group->reject(fn ($it) => in_array($it->id, $answeredItemIds, true))->count();
                return ['category' => $category, 'total' => $group->count(), 'pending' => $pending];
            })
            ->filter(fn ($row) => $row['pending'] > 0)
            ->values()
            ->all();
    }

    /**
     * Deriva a matriz viva (consultant_skills) da submissão INTERNA. Só grava
     * níveis positivos (com conhecimento); "Nenhum conhecimento" não rebaixa nem
     * apaga registros anteriores. O histórico completo permanece nas submissões.
     */
    protected function deriveConsultantSkills(SkillSubmission $submission): void
    {
        $respondent = $submission->respondent;
        if (! $respondent || $respondent->type !== SkillRespondent::TYPE_INTERNAL || ! $respondent->user_id) {
            return;
        }

        $answers = SkillSubmissionAnswer::where('submission_id', $submission->id)
            ->whereNotNull('skill_id')
            ->whereNotNull('level_id')
            ->where('level_weight', '>', 0)
            ->get();

        foreach ($answers as $ans) {
            ConsultantSkill::updateOrCreate(
                ['consultant_id' => $respondent->user_id, 'skill_id' => $ans->skill_id],
                [
                    'level_id' => $ans->level_id,
                    'years_experience' => $ans->years_experience,
                    'atuacao_types' => $ans->atuacao,
                    'source' => 'user_input',
                    'confidence' => 'medium',
                ]
            );
        }
    }

    /**
     * Cria convites para uma lista de colaboradores internos (idempotente).
     * $notify=false pula o e-mail da notificação padrão (campanhas notificam via
     * SkillCampaignNotifier — pop-up + workflow da Central).
     */
    public function inviteInternalUsers(SkillSurvey $survey, array $userIds, bool $notify = true): int
    {
        $created = 0;
        foreach (array_unique($userIds) as $userId) {
            $user = User::find($userId);
            if (! $user) {
                continue;
            }
            $respondent = $this->internalRespondent($user);
            $invite = SkillSurveyInvite::firstOrCreate(
                ['survey_id' => $survey->id, 'user_id' => $user->id],
                [
                    'respondent_id' => $respondent->id,
                    'email' => $this->clean($user->email, 190),
                    'name' => $this->clean($user->name, 160),
                    'status' => SkillSurveyInvite::STATUS_SENT,
                    'sent_at' => now(),
                ]
            );
            if ($invite->wasRecentlyCreated) {
                $created++;
                // Avisa o colaborador por e-mail com link direto para responder (assíncrono).
                if ($notify) {
                    $user->notify(new \App\Notifications\SkillSurveyInviteNotification($survey));
                }
            }
        }

        return $created;
    }
}
