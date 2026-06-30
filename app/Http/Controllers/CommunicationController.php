<?php

namespace App\Http\Controllers;

use App\Models\Communication;
use App\Models\CommunicationRead;
use App\Models\Customer;
use App\Models\User;
use App\Services\CommunicationRenderer;
use App\Services\GraphMailSender;
use App\Services\HelpDeskMailComposer;
use App\Services\HelpDeskMailFooter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Central de Comunicação EXTERNA (clientes) — separada da Central de Atividades interna. */
class CommunicationController extends Controller
{
    private const MANAGERS = ['admin', 'coordenador', 'administrativo'];

    private function authorizeManager(Request $request): User
    {
        $u = $request->user();
        abort_unless($u && in_array($u->type, self::MANAGERS, true), 403, 'Sem acesso à Central de Comunicação.');
        return $u;
    }

    /** Metadados: clientes p/ seleção + tipos. */
    public function meta(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        return response()->json(['data' => [
            'tipos'     => [['id' => 'aviso', 'name' => 'Aviso'], ['id' => 'formal', 'name' => 'Comunicação formal'], ['id' => 'marketing', 'name' => 'Marketing']],
            'customers' => Customer::orderBy('name')->limit(2000)->get(['id', 'name']),
        ]]);
    }

    /** Usuários (contatos) dos clientes selecionados — carregados automaticamente. */
    public function customerUsers(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        $ids = array_filter((array) $request->query('customer_ids', []));
        if (empty($ids)) return response()->json(['data' => []]);
        $rows = User::where('type', 'cliente')->whereIn('customer_id', $ids)->whereNotNull('email')
            ->orderBy('name')->get(['id', 'name', 'email', 'customer_id']);
        return response()->json(['data' => $rows]);
    }

    /** Validação da estrutura por tipo. CTA é obrigatório em Marketing. */
    private function validateStructured(Request $request): array
    {
        $v = $request->validate([
            'tipo_comunicacao'        => 'required|in:' . implode(',', Communication::TIPOS),
            'title'                   => 'required|string|max:250',
            'structure'               => 'required|array',
            'structure.badge'         => 'nullable|string|max:40',
            'structure.subtitle'      => 'nullable|string|max:300',
            'structure.intro'         => 'nullable|string',
            'structure.problema'      => 'nullable|string',
            'structure.autoridade'    => 'nullable|string',
            'structure.content'       => 'nullable|string',
            'structure.greeting'      => 'nullable|string|max:120',
            'structure.prazo'         => 'nullable|string|max:200',
            'structure.acao_esperada' => 'nullable|string',
            'structure.contato'       => 'nullable|string|max:200',
            'structure.datahora'      => 'nullable|string|max:120',
            'structure.observacao'    => 'nullable|string|max:300',
            'structure.benefits'      => 'nullable|array|max:20',
            'structure.benefits.*'    => 'nullable|string',
            'structure.cta'           => 'nullable|array',
            'structure.cta.label'     => 'nullable|string|max:80',
            'structure.cta.url'       => 'nullable|url',
            'structure.signature'     => 'nullable|array',
            'structure.signature.enabled' => 'nullable|boolean',
            'structure.signature.name'    => 'nullable|string|max:120',
            'structure.signature.role'    => 'nullable|string|max:120',
            'structure.signature.phone'   => 'nullable|string|max:60',
            'structure.signature.email'   => 'nullable|string|max:160',
            'structure.signature.site'    => 'nullable|string|max:160',
            'structure.signature.photo'   => 'nullable|string',
        ]);

        $s = $v['structure'];
        $txt = fn (string $k) => trim(strip_tags((string) ($s[$k] ?? '')));
        $benefits = array_filter((array) ($s['benefits'] ?? []), fn ($b) => trim(strip_tags((string) $b)) !== '');

        if ($v['tipo_comunicacao'] === 'marketing') {
            if ($txt('intro') === '' && $txt('problema') === '') abort(422, 'Informe a introdução ou o cenário.');
            if (count($benefits) < 1) abort(422, 'Marketing exige ao menos 1 benefício.');
            if (empty($s['cta']['label']) || empty($s['cta']['url'])) abort(422, 'O botão (CTA) é obrigatório em comunicações de Marketing.');
        } elseif ($txt('content') === '') {
            abort(422, 'Informe o conteúdo da comunicação.');
        }
        return $v;
    }

    /**
     * Resolve a assinatura a injetar na estrutura: se "incluir assinatura" estiver ativo,
     * usa a do remetente logado (ou o fallback da empresa); senão, nenhuma.
     */
    private function resolveSignature(array $structure, User $sender): array
    {
        $enabled = (bool) ($structure['signature']['enabled'] ?? false);
        $structure['signature'] = $enabled ? \App\Services\SignatureRenderer::resolveFor($sender) : null;
        return $structure;
    }

    /** Prévia do e-mail institucional a partir da estrutura (template visual fixo por tipo). */
    public function preview(Request $request): JsonResponse
    {
        $u = $this->authorizeManager($request);
        $v = $this->validateStructured($request);
        $structure = $this->resolveSignature($v['structure'], $u);
        $html = CommunicationRenderer::render($v['tipo_comunicacao'], $v['title'], $structure, HelpDeskMailFooter::whiteLogoDataUri());
        $count = count($this->resolveEmails($request));
        return response()->json(['data' => ['html' => $html, 'recipients' => $count]]);
    }

    /** Envia a comunicação (BCC) + grava no histórico. Envio em massa (todos) exige confirmação. */
    public function send(Request $request): JsonResponse
    {
        $u = $this->authorizeManager($request);
        $v = $this->validateStructured($request);
        $extra = $request->validate([
            'customer_ids'     => 'nullable|array',
            'customer_ids.*'   => 'integer|exists:customers,id',
            'user_ids'         => 'nullable|array',
            'user_ids.*'       => 'integer|exists:users,id',
            'external_emails'  => 'nullable|array',
            'external_emails.*' => 'email',
            'all_customers'    => 'nullable|boolean',
            'expires_at'       => 'required|date',
            'confirm'          => 'nullable|boolean',
            'lista_distribuicao_id' => 'nullable|integer|exists:distribution_lists,id',
        ]);

        if (($extra['all_customers'] ?? false) && !($extra['confirm'] ?? false)) {
            abort(422, 'Envio para TODOS os clientes exige confirmação.');
        }

        $emails = $this->resolveEmails($request);
        abort_if(empty($emails), 422, 'Nenhum destinatário válido.');
        abort_unless(GraphMailSender::enabled(), 422, 'Envio de e-mail não está configurado no servidor.');

        $from = \App\Models\HelpDeskEmailAccount::where('provider', 'microsoft365')->where('enabled', true)->orderBy('id')->value('email');
        abort_unless($from, 422, 'Nenhuma conta de envio configurada.');

        // Assinatura padrão (remetente ou empresa) injetada na estrutura.
        $structure = $this->resolveSignature($v['structure'], $u);

        // E-mail completo (template visual fixo por tipo) + fragmento p/ a aba Comunicados.
        $bodyInner = CommunicationRenderer::body($v['tipo_comunicacao'], $structure);
        $html = CommunicationRenderer::render($v['tipo_comunicacao'], $v['title'], $structure);
        [$html, $imgAtts] = HelpDeskMailComposer::inlineImages($html);
        // Logo branco (cabeçalho) + logo escuro (assinatura) inline via CID; ícones da assinatura quando houver.
        $assets = array_filter([HelpDeskMailFooter::inlineWhiteLogo(), HelpDeskMailFooter::inlineLogo()]);
        if (is_array($structure['signature'] ?? null)) {
            $assets = array_merge($assets, \App\Services\SignatureRenderer::inlineAssets());
        }
        $inline = array_merge($assets, $imgAtts);
        [$ok, $err] = GraphMailSender::sendAs($from, [$from], [], $v['title'], $html, [], $inline, false, $emails);
        abort_if(!$ok, 422, 'Falha no envio: ' . $err);

        $comm = Communication::create([
            'tipo_comunicacao'      => $v['tipo_comunicacao'],
            'title'                 => $v['title'],
            'message'               => $bodyInner,           // corpo renderizado (p/ aba Comunicados e histórico)
            'structure'             => $structure,            // estrutura + assinatura resolvida
            'customer_ids'          => array_values((array) ($extra['customer_ids'] ?? [])),
            'user_ids'              => array_values((array) ($extra['user_ids'] ?? [])),
            'external_emails'       => array_values((array) ($extra['external_emails'] ?? [])),
            'all_customers'         => (bool) ($extra['all_customers'] ?? false),
            'expires_at'            => $extra['expires_at'] ?? null,
            'lista_distribuicao_id' => $extra['lista_distribuicao_id'] ?? null,
            'recipients_count'      => count($emails),
            'sent_by'               => $u->id,
        ]);

        return response()->json(['data' => ['sent' => count($emails), 'communication_id' => $comm->id]], 201);
    }

    /** Histórico de envios. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        $theme = $request->query('theme') === 'dark' ? 'dark' : 'light';
        $rows = Communication::with('sentBy:id,name')->orderByDesc('id')->limit(200)->get();
        $custNames = Customer::pluck('name', 'id');
        $data = $rows->map(fn (Communication $c) => [
            'id'           => $c->id,
            'tipo'         => $c->tipo_comunicacao,
            'title'        => $c->title,
            'message'      => is_array($c->structure) && $c->structure
                ? CommunicationRenderer::body($c->tipo_comunicacao, $c->structure, $theme)
                : (string) ($c->message ?? ''),
            'customers'    => $c->all_customers ? ['Todos os clientes'] : collect($c->customer_ids ?? [])->map(fn ($id) => $custNames[$id] ?? "#$id")->values(),
            'recipients'   => $c->recipients_count,
            'sent_by'      => $c->sentBy?->name,
            'expires_at'   => $c->expires_at?->toIso8601String(),
            'created_at'   => $c->created_at?->toIso8601String(),
        ]);
        return response()->json(['data' => $data]);
    }

    /** Garante que o usuário logado é cliente; retorna-o. */
    private function client(Request $request): User
    {
        $u = $request->user();
        abort_unless($u && $u->type === 'cliente', 403, 'Apenas clientes têm comunicados.');
        return $u;
    }

    /** Publicações RECEBIDAS pelo usuário logado (cliente: portal; interno: endereçadas a ele). */
    public function mine(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);
        // Tema do app (claro/escuro) p/ re-renderizar o corpo com cores legíveis.
        $theme = $request->query('theme') === 'dark' ? 'dark' : 'light';

        $readIds = CommunicationRead::where('user_id', $u->id)->pluck('communication_id')->flip();

        $base = Communication::visible()->with('sentBy:id,name');
        if ($u->type === 'cliente') {
            $base->forClient($u);   // cliente: all_customers / customer_id / user_ids / e-mail
        } else {
            // Interno: só o que foi ENDEREÇADO diretamente a ele (não "todos os clientes").
            $base->where(function ($w) use ($u) {
                $w->whereJsonContains('user_ids', $u->id);
                if ($u->email) $w->orWhereJsonContains('external_emails', mb_strtolower(trim($u->email)));
            });
        }
        // "Recebidas" nunca inclui o que o PRÓPRIO usuário criou (isso fica em "Criadas").
        $rows = $base->where(fn ($q) => $q->whereNull('sent_by')->orWhere('sent_by', '!=', $u->id))
            ->orderByDesc('id')->limit(200)->get();
        $data = $rows->map(fn (Communication $c) => [
            'id'         => $c->id,
            'tipo'       => $c->tipo_comunicacao,
            'title'      => $c->title,
            // Re-renderiza pela estrutura (fonte de verdade) no tema atual; fallback ao message salvo.
            'message'    => is_array($c->structure) && $c->structure
                ? CommunicationRenderer::body($c->tipo_comunicacao, $c->structure, $theme)
                : (string) ($c->message ?? ''),
            'sent_by'    => $c->sentBy?->name,
            'read'       => $readIds->has($c->id),
            'expires_at' => $c->expires_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ]);
        return response()->json(['data' => $data]);
    }

    /** MURAL — todas as publicações que o usuário pode LER (broadcast + endereçadas, inclui as próprias). */
    public function feed(Request $request): JsonResponse
    {
        $u = $request->user();
        abort_unless($u, 401);
        $theme = $request->query('theme') === 'dark' ? 'dark' : 'light';

        $base = Communication::visible()->with('sentBy:id,name');
        // Cliente: só as suas. Interno (staff): mural COMPLETO de todas as publicações institucionais.
        if ($u->type === 'cliente') {
            $base->forClient($u);
        }
        $rows = $base->orderByDesc('id')->limit(200)->get();
        $data = $rows->map(fn (Communication $c) => [
            'id'         => $c->id,
            'tipo'       => $c->tipo_comunicacao,
            'title'      => $c->title,
            'message'    => is_array($c->structure) && $c->structure
                ? CommunicationRenderer::body($c->tipo_comunicacao, $c->structure, $theme)
                : (string) ($c->message ?? ''),
            'sent_by'    => $c->sentBy?->name,
            'expires_at' => $c->expires_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ]);
        return response()->json(['data' => $data]);
    }

    /** LOG de um comunicado (admin): destinatários (inclui clientes) × leu/quando. Mesmo formato do log de notificações. */
    public function log(Request $request, Communication $communication): JsonResponse
    {
        $this->authorizeManager($request);

        $userIds = collect();
        if ($communication->all_customers) {
            $userIds = User::where('type', 'cliente')->pluck('id');
        } else {
            if (!empty($communication->customer_ids)) {
                $userIds = $userIds->merge(User::whereIn('customer_id', (array) $communication->customer_ids)->pluck('id'));
            }
            if (!empty($communication->user_ids)) {
                $userIds = $userIds->merge((array) $communication->user_ids);
            }
        }
        $users = User::whereIn('id', $userIds->unique()->values())->get(['id', 'name', 'email', 'type']);
        $reads = CommunicationRead::where('communication_id', $communication->id)->get()->keyBy('user_id');

        $rows = $users->map(fn (User $u) => [
            'user_id'      => $u->id,
            'user_name'    => $u->name,
            'user_email'   => $u->email,
            'is_client'    => $u->type === 'cliente',
            'viewed_at'    => $reads->get($u->id)?->read_at?->toIso8601String(),
            'response'     => null,
            'responded_at' => null,
        ]);
        // E-mails avulsos (externos, sem usuário) — também são "clientes".
        $ext = collect($communication->external_emails ?? [])->map(fn ($e) => [
            'user_id' => null, 'user_name' => $e, 'user_email' => $e, 'is_client' => true,
            'viewed_at' => null, 'response' => null, 'responded_at' => null,
        ]);
        $all = $rows->concat($ext)->sortBy('user_name')->values();

        return response()->json(['data' => [
            'recipients' => $all,
            'summary'    => [
                'total'     => $all->count(),
                'viewed'    => $all->whereNotNull('viewed_at')->count(),
                'responded' => 0,
                'actions'   => [],
                'by_action' => [],
            ],
        ]]);
    }

    /** Comunicados ainda não lidos pelo cliente — alimenta o popup de prévia. */
    public function unread(Request $request): JsonResponse
    {
        $u = $this->client($request);

        $readIds = CommunicationRead::where('user_id', $u->id)->pluck('communication_id');
        $rows = Communication::visible()->forClient($u)->whereNotIn('id', $readIds)
            ->orderByDesc('id')->limit(20)->get(['id', 'tipo_comunicacao', 'title', 'message', 'created_at']);

        $items = $rows->map(fn (Communication $c) => [
            'id'         => $c->id,
            'tipo'       => $c->tipo_comunicacao,
            'title'      => $c->title,
            'excerpt'    => \Illuminate\Support\Str::limit(trim(html_entity_decode(strip_tags($c->message))), 180),
            'created_at' => $c->created_at?->toIso8601String(),
        ]);
        return response()->json(['data' => ['count' => $items->count(), 'items' => $items]]);
    }

    /** Marca como lidos os comunicados do cliente (todos os visíveis, ou um subconjunto por ids). */
    public function markRead(Request $request): JsonResponse
    {
        $u = $this->client($request);
        $v = $request->validate(['ids' => 'nullable|array', 'ids.*' => 'integer']);

        $q = Communication::visible()->forClient($u);
        if (!empty($v['ids'])) $q->whereIn('id', $v['ids']);
        $ids = $q->pluck('id');

        $now = now();
        foreach ($ids as $id) {
            CommunicationRead::firstOrCreate(
                ['communication_id' => $id, 'user_id' => $u->id],
                ['read_at' => $now],
            );
        }
        return response()->json(['data' => ['marked' => $ids->count()]]);
    }

    /** Resolve a lista final de e-mails (clientes + usuários + externos), em minúsculo e únicos. */
    private function resolveEmails(Request $request): array
    {
        $all       = $request->boolean('all_customers');
        $userIds   = array_filter((array) $request->input('user_ids', []));
        $external  = (array) $request->input('external_emails', []);

        // user_ids = contatos escolhidos (o FE carrega os usuários do cliente e deixa selecionar).
        // customer_ids vai só p/ o histórico. all_customers = todos os contatos de cliente.
        $emails = collect();
        if ($all) {
            $emails = $emails->merge(User::where('type', 'cliente')->whereNotNull('email')->pluck('email'));
        } elseif ($userIds) {
            $emails = $emails->merge(User::whereIn('id', $userIds)->whereNotNull('email')->pluck('email'));
        }
        $emails = $emails->merge($external);

        return $emails->map(fn ($e) => mb_strtolower(trim((string) $e)))->filter()->unique()->values()->all();
    }
}
