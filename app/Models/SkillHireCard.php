<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Card do kanban de Contratação (onboarding do contratado).
 */
class SkillHireCard extends Model
{
    /** Colunas do quadro (na ordem). */
    public const BUCKETS = [
        'aguardando_assinatura' => 'Aguardando contrato',
        'em_andamento' => 'Em andamento',
        'finalizado' => 'Finalizado',
        'pausado' => 'Pausados',
    ];

    /** Checklist padrão de onboarding (do processo atual da ERPSERV). */
    public const DEFAULT_CHECKLIST = [
        'Enviar formulário para cadastro',
        'Entrar em contato com o contratado',
        'Realizar cadastro no Keruak',
        'Realizar cadastro no Flash (se necessário)',
        'Realizar cadastro no Artia (se necessário)',
        'Criar e enviar contrato para assinatura',
        'Solicitar e-mail (se necessário)',
        'Incluir no WhatsApp e enviar boas-vindas',
    ];

    /** Modalidades de contratação (mapeiam p/ contract_type do usuário). */
    public const MODALIDADES = [
        'pj' => 'PJ',
        'cooperado' => 'Cooperado',
        'clt' => 'CLT',
        'a_definir' => 'Definir com o candidato',
    ];

    /** Recursos a provisionar no onboarding (checkboxes do script). */
    public const RECURSOS = [
        'flash' => 'Cartão Flash',
        'headset' => 'Headset',
        'notebook' => 'Notebook',
        'mouse' => 'Mouse',
    ];

    protected $fillable = [
        'respondent_id', 'bucket', 'title', 'cargo', 'modalidade', 'priority', 'checklist', 'notes', 'form',
        'created_by', 'created_user_id', 'completed_at',
    ];

    protected $casts = [
        'checklist' => 'array',
        'form' => 'array',
        'completed_at' => 'datetime',
    ];

    /** Estrutura padrão do formulário de passagem, pré-preenchida com o respondente. */
    public static function defaultForm(?SkillRespondent $r = null): array
    {
        $data = $r && is_array($r->data) ? $r->data : [];

        return [
            'contato' => (string) ($data['contato'] ?? $data['telefone'] ?? $data['phone'] ?? ''),  // contato do contratado (item 1 do script)
            'email' => $r ? Str::slug($r->name, '.') . '@erpserv.com.br' : '',  // e-mail que será cadastrado no usuário
            'perfil' => 'consultor',             // consultor | coordenador
            'coordinator_type' => '',            // projetos | sustentacao (só se coordenador)
            'contratacao_fixa' => '',            // sim | nao
            'consultant_type' => '',             // horista | banco_de_horas | fixo (igual ao cadastro)
            'valor' => (string) ($data['valor'] ?? ''),
            'start_date' => '',                  // data de início (= bank_hours_start_date, proporcional)
            'data_primeiro_contato' => '',       // data do 1º contato → fixa a ação no Meu Dia do administrativo; atraso se passar
            'tem_garantia' => '',                // sim | nao (só se horista)
            'guaranteed_hours' => '',            // horas garantidas
            'empresa' => 'erpserv',              // erpserv | bizify (base da folha → is_bizify)
            'recursos' => [],                    // chaves de RECURSOS
            'email_criado' => '',                // sim | nao (e-mail corporativo já criado?)
            'incluir_whatsapp' => '',            // sim | nao
            'whatsapp_date' => '',               // data em que pode ser incluído no WhatsApp (se sim)
            'cpf' => (string) ($data['cpf'] ?? ''),
            'nascimento' => (string) ($data['nascimento'] ?? $data['data_nascimento'] ?? ''),
            'matricula' => '',
            'cep' => (string) ($data['cep'] ?? ''),
            'logradouro' => (string) ($data['logradouro'] ?? ''),
            'numero' => (string) ($data['numero'] ?? ''),
            'complemento' => (string) ($data['complemento'] ?? ''),
            'bairro' => (string) ($data['bairro'] ?? ''),
            'cidade' => (string) ($data['cidade'] ?? ''),
            'estado' => (string) ($data['estado'] ?? ''),
            'observacao' => '',
        ];
    }

    public function respondent(): BelongsTo
    {
        return $this->belongsTo(SkillRespondent::class, 'respondent_id');
    }

    public function createdUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }
}
