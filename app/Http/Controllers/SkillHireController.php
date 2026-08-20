<?php

namespace App\Http\Controllers;

use App\Models\SkillHireCard;
use App\Models\SkillRespondent;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Kanban de Contratação/Onboarding. Contratar um candidato cria um card aqui e
 * muda a classificação para ERPSERV; ao concluir, cria o usuário no Minutor.
 * Gate de rota: competencias.manage.
 */
class SkillHireController extends Controller
{
    /** Quadro completo agrupado por coluna. */
    public function index(): JsonResponse
    {
        $cards = SkillHireCard::query()
            ->with(['respondent:id,name,email,phone,classification,data', 'createdUser:id,name,email'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($c) => $this->card($c));

        $buckets = collect(SkillHireCard::BUCKETS)->map(fn ($label, $key) => [
            'key' => $key,
            'label' => $label,
            'cards' => $cards->where('bucket', $key)->values(),
        ])->values();

        $modalidades = collect(SkillHireCard::MODALIDADES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values();
        $recursos = collect(SkillHireCard::RECURSOS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values();

        return response()->json(['buckets' => $buckets, 'modalidades' => $modalidades, 'recursos' => $recursos]);
    }

    /** Contrata um candidato: cria o card + classificação ERPSERV. */
    public function hire(Request $request): JsonResponse
    {
        $data = $request->validate(['respondent_id' => 'required|exists:skill_respondents,id']);
        $respondent = SkillRespondent::findOrFail($data['respondent_id']);

        // Idempotente: se já há card em aberto p/ esse respondente, devolve ele.
        $existing = SkillHireCard::where('respondent_id', $respondent->id)
            ->where('bucket', '!=', 'finalizado')->first();
        if ($existing) {
            $respondent->update(['classification' => 'erpserv']);

            return response()->json($this->card($existing->load('respondent', 'createdUser')), 200);
        }

        $card = SkillHireCard::create([
            'respondent_id' => $respondent->id,
            'bucket' => 'aguardando_assinatura',
            'title' => 'Nova Contratação — ' . $respondent->name,
            'priority' => 'alta',
            'checklist' => array_map(fn ($l) => ['label' => $l, 'done' => false], SkillHireCard::DEFAULT_CHECKLIST),
            'form' => SkillHireCard::defaultForm($respondent),
            'created_by' => $request->user()?->id,
        ]);

        $respondent->update(['classification' => 'erpserv']);

        return response()->json($this->card($card->load('respondent', 'createdUser')), 201);
    }

    /** Contratação AVULSA — incluir direto pela rotina (sem candidato do Banco de Competências). */
    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'title'      => 'required|string|max:200',   // nome da pessoa / contratação
            'cargo'      => 'nullable|string|max:120',
            'modalidade' => ['nullable', Rule::in(array_keys(SkillHireCard::MODALIDADES))],
            // Script de passagem (opcional no ato da inclusão — pode completar depois no card).
            'form'                    => 'sometimes|array',
            'form.contato'            => 'nullable|string|max:120',
            'form.contratacao_fixa'   => 'nullable|in:sim,nao',
            'form.consultant_type'    => 'nullable|in:horista,banco_de_horas,fixo',
            'form.valor'              => 'nullable|string|max:60',
            'form.recursos'           => 'nullable|array',
            'form.recursos.*'         => ['string', Rule::in(array_keys(SkillHireCard::RECURSOS))],
            'form.email_criado'       => 'nullable|in:sim,nao',
            'form.incluir_whatsapp'   => 'nullable|in:sim,nao',
            'form.whatsapp_date'      => 'nullable|date',
            'form.start_date'             => 'nullable|date',   // data de início
            'form.data_primeiro_contato'  => 'nullable|date',   // fixa a ação no Meu Dia do administrativo
            'form.observacao'         => 'nullable|string',
        ]);
        // Mescla o script informado sobre o formulário padrão (mantém as demais chaves).
        $form = array_merge(SkillHireCard::defaultForm(null), $v['form'] ?? []);
        if (($form['incluir_whatsapp'] ?? '') !== 'sim') $form['whatsapp_date'] = '';

        $card = SkillHireCard::create([
            'respondent_id' => null,
            'bucket'        => 'aguardando_assinatura',
            'title'         => trim($v['title']),
            'cargo'         => $v['cargo'] ?? null,
            'modalidade'    => $v['modalidade'] ?? null,
            'priority'      => 'alta',
            'checklist'     => array_map(fn ($l) => ['label' => $l, 'done' => false], SkillHireCard::DEFAULT_CHECKLIST),
            'form'          => $form,
            'created_by'    => $request->user()?->id,
        ]);

        // Script: "não esquecer de atribuir a tarefa para a Jeniffer" → cria a tarefa automaticamente
        // (aparece nas Minhas Tarefas dela). Não bloqueia a inclusão se a Jeniffer não existir.
        $jeniffer = User::where('enabled', true)
            ->where(fn ($q) => $q->where('name', 'ilike', '%jenif%')->orWhere('name', 'ilike', '%jennif%'))
            ->orderBy('id')->first();
        if ($jeniffer) {
            Task::create([
                'user_id'     => $request->user()?->id,
                'created_by'  => $request->user()?->id,
                'assigned_to' => $jeniffer->id,
                'title'       => 'Passagem de contratação: ' . trim($v['title']),
                'description' => 'Nova contratação incluída pela rotina. Providenciar assinatura, recursos e onboarding.',
                'due_date'    => now()->addDays(2)->toDateString(),
                'completed'   => false,
                'priority'    => 'alta',
                'entity_type' => 'skill_hire',   // vincula ao card → concluir = abrir o card
                'entity_id'   => $card->id,
            ]);
        }

        // Avisa o administrativo (e-mail via Central de Workflows + pop-up in-app).
        // Não bloqueia a inclusão. O pop-up recorrente / atraso fica a cargo do
        // command diário `contratacao:notify-administrativo`.
        \App\Services\HireNotifier::onCreated($card);

        return response()->json($this->card($card->load('createdUser')), 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->card(SkillHireCard::with('respondent', 'createdUser')->findOrFail($id)));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $card = SkillHireCard::findOrFail($id);
        $data = $request->validate([
            'title' => 'sometimes|string|max:160',
            'cargo' => 'nullable|string|max:120',
            'modalidade' => ['nullable', Rule::in(array_keys(SkillHireCard::MODALIDADES))],
            'priority' => ['sometimes', Rule::in(['baixa', 'media', 'alta', 'urgente'])],
            'notes' => 'nullable|string',
            'checklist' => 'sometimes|array',
            'checklist.*.label' => 'required|string|max:200',
            'checklist.*.done' => 'boolean',
            'form' => 'sometimes|array',
            'form.contato' => 'nullable|string|max:120',
            'form.email' => 'nullable|email|max:255',
            'form.perfil' => 'nullable|in:consultor,coordenador',
            'form.coordinator_type' => 'nullable|in:projetos,sustentacao',
            'form.contratacao_fixa' => 'nullable|in:sim,nao',
            'form.consultant_type' => 'nullable|in:horista,banco_de_horas,fixo',
            'form.valor' => 'nullable|string|max:60',
            'form.start_date' => 'nullable|date',
            'form.data_primeiro_contato' => 'nullable|date',
            'form._hire_new_at' => 'nullable|string|max:10',        // tracking de recorrência (interno)
            'form._first_contact_at' => 'nullable|string|max:10',   // tracking de recorrência (interno)
            'form.tem_garantia' => 'nullable|in:sim,nao',
            'form.guaranteed_hours' => 'nullable|string|max:20',
            'form.empresa' => 'nullable|in:erpserv,bizify',
            'form.cpf' => 'nullable|string|max:20',
            'form.nascimento' => 'nullable|string|max:10',
            'form.matricula' => 'nullable|string|max:30',
            'form.recursos' => 'nullable|array',
            'form.recursos.*' => ['string', Rule::in(array_keys(SkillHireCard::RECURSOS))],
            'form.email_criado' => 'nullable|in:sim,nao',
            'form.incluir_whatsapp' => 'nullable|in:sim,nao',
            'form.whatsapp_date' => 'nullable|date',
            'form.cep' => 'nullable|string|max:9',
            'form.logradouro' => 'nullable|string|max:200',
            'form.numero' => 'nullable|string|max:20',
            'form.complemento' => 'nullable|string|max:120',
            'form.bairro' => 'nullable|string|max:120',
            'form.cidade' => 'nullable|string|max:100',
            'form.estado' => 'nullable|string|max:2',
            'form.observacao' => 'nullable|string',
        ]);
        $card->fill($data)->save();

        return response()->json($this->card($card->fresh('respondent', 'createdUser')));
    }

    public function move(Request $request, int $id): JsonResponse
    {
        $card = SkillHireCard::findOrFail($id);
        $data = $request->validate(['bucket' => ['required', Rule::in(array_keys(SkillHireCard::BUCKETS))]]);

        // Mover manualmente para Finalizado conclui (cria usuário).
        if ($data['bucket'] === 'finalizado') {
            return $this->complete($id);
        }
        $from = $card->bucket;
        $card->update(['bucket' => $data['bucket']]);
        // Toda movimentação avisa o solicitante (workflow hire.movement + pop-up).
        \App\Services\HireNotifier::onMoved($card, $from, $data['bucket'], $request->user()?->name);

        return response()->json($this->card($card->fresh('respondent', 'createdUser')));
    }

    /** Conclui a contratação: cria o usuário no Minutor + vincula ao respondente. */
    public function complete(int $id): JsonResponse
    {
        $card = SkillHireCard::with('respondent')->findOrFail($id);
        $respondent = $card->respondent;
        $from = $card->bucket;

        if ($card->created_user_id) {
            $card->update(['bucket' => 'finalizado', 'completed_at' => $card->completed_at ?? now()]);
            \App\Services\HireNotifier::onMoved($card, $from, 'finalizado', auth()->user()?->name);

            return response()->json($this->card($card->fresh('respondent', 'createdUser')));
        }

        // Reusa o usuário já vinculado ao respondente, se houver; senão cria pelo
        // FLUXO OFICIAL (UserController@store): senha temporária + e-mail de boas-vindas.
        $user = ($respondent && $respondent->user_id) ? User::find($respondent->user_id) : null;
        if (! $user) {
            $form = is_array($card->form) ? $card->form : [];

            // Perfil: consultor ou coordenador (+ tipo de coordenação).
            $perfil = ($form['perfil'] ?? 'consultor') === 'coordenador' ? 'coordenador' : 'consultor';
            // E-mail informado no card vira o e-mail do usuário; senão gera automático.
            $email = filled($form['email'] ?? null) ? strtolower(trim($form['email'])) : $this->uniqueEmail($respondent?->email, $respondent?->name ?? $card->title);
            $payload = [
                'name' => $respondent?->name ?? $card->title,
                'email' => $email,
                'type' => $perfil,
                'enabled' => true,
            ];
            if ($perfil === 'coordenador' && in_array($form['coordinator_type'] ?? '', ['projetos', 'sustentacao'], true)) {
                $payload['coordinator_type'] = $form['coordinator_type'];
            }
            // Empresa base da folha (ERPSERV/Bizify) → is_bizify.
            if (($form['empresa'] ?? '') === 'bizify') {
                $payload['is_bizify'] = true;
            }
            // Modalidade → tipo de contrato; Cargo → cargo (signature.role).
            if (in_array($card->modalidade, ['pj', 'cooperado', 'clt'], true)) {
                $payload['contract_type'] = $card->modalidade;
            }
            if (filled($card->cargo)) {
                $payload['signature'] = ['role' => $card->cargo];
            }
            // Tipo de consultor (igual ao cadastro) → rate_type + valor.
            $ct = $form['consultant_type'] ?? '';
            $valor = $this->parseMoney($form['valor'] ?? null);
            if (in_array($ct, ['horista', 'banco_de_horas', 'fixo'], true)) {
                $payload['consultant_type'] = $ct;
                $payload['rate_type'] = $ct === 'horista' ? 'hourly' : 'monthly';
                if ($valor !== null) {
                    $payload['hourly_rate'] = $valor;
                }
                // Garantia de horas — só faz sentido no horista (mesmo campo do cadastro).
                if ($ct === 'horista') {
                    $garantia = $this->parseMoney($form['guaranteed_hours'] ?? null);
                    if (($form['tem_garantia'] ?? '') === 'sim' && $garantia !== null) {
                        $payload['guaranteed_hours'] = $garantia;
                    }
                }
            }
            // Data de início (proporcional) → bank_hours_start_date.
            if (filled($form['start_date'] ?? null)) {
                $payload['bank_hours_start_date'] = $form['start_date'];
            }
            // Folha: CPF, nascimento, matrícula.
            if (filled($form['cpf'] ?? null)) {
                $payload['cpf'] = $form['cpf'];
            }
            if (filled($form['nascimento'] ?? null)) {
                $payload['birth_date'] = $form['nascimento'];
            }
            if (filled($form['matricula'] ?? null)) {
                $payload['matricula'] = $form['matricula'];
            }

            $req = Request::create('/api/v1/users', 'POST', $payload);
            $resp = app(UserController::class)->store($req);
            if ($resp->getStatusCode() !== 201) {
                return response()->json([
                    'error' => 'Falha ao criar o usuário no fluxo oficial.',
                    'detail' => $resp->getData(true),
                ], 422);
            }
            $user = User::find($resp->getData(true)['id']);

            // Telefone + endereço (CEP/logradouro/…): fora do store() oficial.
            $extra = array_filter([
                'phone' => $respondent?->phone,
                'cep' => $form['cep'] ?? null,
                'address_street' => $form['logradouro'] ?? null,
                'address_number' => $form['numero'] ?? null,
                'address_complement' => $form['complemento'] ?? null,
                'neighborhood' => $form['bairro'] ?? null,
                'city' => $form['cidade'] ?? null,
                'state' => $form['estado'] ?? null,
            ], fn ($v) => filled($v));
            if ($extra && $user) {
                $user->forceFill($extra)->save();
            }
            $respondent?->update(['user_id' => $user->id]);
        }

        $card->update([
            'bucket' => 'finalizado',
            'completed_at' => now(),
            'created_user_id' => $user->id,
        ]);

        // Contratação concluída → conclui a tarefa vinculada (Passagem de contratação).
        Task::where('entity_type', 'skill_hire')->where('entity_id', $card->id)
            ->where('completed', false)
            ->update(['completed' => true, 'completed_at' => now(), 'completed_by' => auth()->id()]);

        // Movimentação para Finalizado avisa o solicitante.
        \App\Services\HireNotifier::onMoved($card, $from, 'finalizado', auth()->user()?->name);

        return response()->json($this->card($card->fresh('respondent', 'createdUser')));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function uniqueEmail(?string $email, string $name): string
    {
        $base = $email ?: (Str::slug($name, '.') . '@erpserv.com.br');
        if (! User::where('email', $base)->exists()) {
            return $base;
        }
        [$local, $domain] = array_pad(explode('@', $base, 2), 2, 'erpserv.com.br');
        $i = 1;
        do {
            $candidate = $local . '+' . $i . '@' . $domain;
            $i++;
        } while (User::where('email', $candidate)->exists());

        return $candidate;
    }

    /** Converte "R$ 1.234,56" / "120" em float; null se não parsear. */
    private function parseMoney(?string $raw): ?float
    {
        if (! filled($raw)) {
            return null;
        }
        $s = preg_replace('/[^\d,.]/', '', $raw);
        if ($s === '') {
            return null;
        }
        // Formato BR: milhar com ponto, decimal com vírgula.
        if (str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    private function card(SkillHireCard $c): array
    {
        $checklist = $c->checklist ?? [];
        $done = collect($checklist)->where('done', true)->count();

        return [
            'id' => $c->id,
            'bucket' => $c->bucket,
            'title' => $c->title,
            'cargo' => $c->cargo,
            'modalidade' => $c->modalidade,
            'priority' => $c->priority,
            'checklist' => $checklist,
            'checklist_done' => $done,
            'checklist_total' => count($checklist),
            'notes' => $c->notes,
            'form' => array_merge(SkillHireCard::defaultForm($c->respondent), is_array($c->form) ? $c->form : []),
            'respondent_id' => $c->respondent_id,
            'respondent_name' => $c->respondent?->name,
            'respondent_phone' => $c->respondent?->phone,
            'created_user' => $c->createdUser ? ['id' => $c->createdUser->id, 'name' => $c->createdUser->name, 'email' => $c->createdUser->email] : null,
            'completed_at' => $c->completed_at,
        ];
    }
}
