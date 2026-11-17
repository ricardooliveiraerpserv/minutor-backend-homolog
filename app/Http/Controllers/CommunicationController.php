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
            'customers' => $this->scopeCustomersToCompany(Customer::query())->orderBy('name')->limit(2000)->get(['id', 'name']),
            'hd_profiles' => $this->hdProfiles(),
        ]]);
    }

    /** Empresa ativa (multi-empresa ligado) ou null. */
    private function activeCompanyId(): ?int
    {
        if (!config('multiempresa.scoping_enabled')) return null;
        return app(\App\Services\CompanyContext::class)->id();
    }

    private function erpservId(): int
    {
        return (int) (\App\Models\Company::where('slug', 'erpserv')->value('id') ?: 1);
    }

    /** Clientes (customers) da empresa: company_scope erpserv|bizify|ambos (sem valor = ERPSERV). */
    private function scopeCustomersToCompany($q, ?int $cid = null)
    {
        $cid ??= $this->activeCompanyId();
        if (!$cid) return $q;
        if ($cid === $this->erpservId()) {
            return $q->where(fn ($w) => $w->whereIn('company_scope', ['erpserv', 'ambos'])->orWhereNull('company_scope'));
        }
        return $q->whereIn('company_scope', ['bizify', 'ambos']);
    }

    /** Usuários-cliente vinculados à empresa (company_user); sem vínculo = ERPSERV. */
    private function scopeUsersToCompany($q, ?int $cid = null)
    {
        $cid ??= $this->activeCompanyId();
        if (!$cid) return $q;
        $erp = $this->erpservId();
        return $q->where(function ($w) use ($cid, $erp) {
            $w->whereHas('companies', fn ($c) => $c->where('companies.id', $cid));
            if ($cid === $erp) $w->orWhereDoesntHave('companies');
        });
    }

    /**
     * Perfis de Acesso do Help Desk de CLIENTE, agrupados por nome (ERPSERV e BIZIFY têm cópias
     * com o mesmo nome) → [{key, name, ids[], users}] — o FE escolhe por grupo e envia os ids.
     */
    private function hdProfiles(): array
    {
        // Perfis agrupados por NOME (ids das 2 empresas): o cliente "ambos" tem UM perfil só.
        // A contagem é só dos usuários-cliente da empresa ativa.
        $rows = \App\Models\HelpDeskAccessProfile::withoutGlobalScopes()
            ->whereNull('deleted_at')->where('kind', 'cliente')->where('enabled', true)
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'name']);
        $counts = $this->scopeUsersToCompany(User::where('type', 'cliente'))->where('enabled', true)->whereNotNull('helpdesk_access_profile_id')
            ->selectRaw('helpdesk_access_profile_id, count(*) n')->groupBy('helpdesk_access_profile_id')
            ->pluck('n', 'helpdesk_access_profile_id');
        $out = [];
        foreach ($rows as $p) {
            $name = trim(preg_replace('/^Cliente\s*[—-]\s*/u', '', $p->name));
            $key  = mb_strtolower($name);
            $out[$key] ??= ['key' => $key, 'name' => $name, 'ids' => [], 'users' => 0];
            $out[$key]['ids'][]  = $p->id;
            $out[$key]['users'] += (int) ($counts[$p->id] ?? 0);
        }
        return array_values($out);
    }

    /** Usuários (contatos) dos clientes selecionados — carregados automaticamente. */
    /** Remetentes que o usuário pode escolher nos formulários de envio (publicações e comunicações). */
    public function senderOptions(Request $request): JsonResponse
    {
        return response()->json(['data' => \App\Services\SenderMailbox::options($request->user())]);
    }

    public function customerUsers(Request $request): JsonResponse
    {
        $this->authorizeManager($request);
        $ids = array_filter((array) $request->query('customer_ids', []));
        if (empty($ids)) return response()->json(['data' => []]);
        $rows = User::where('type', 'cliente')->where('enabled', true)->whereIn('customer_id', $ids)->whereNotNull('email')
            ->orderBy('name')->get(['id', 'name', 'email', 'customer_id', 'helpdesk_access_profile_id']);
        return response()->json(['data' => $rows]);
    }

    /** Validação da estrutura por tipo. CTA é obrigatório em Marketing. */
    private function validateStructured(Request $request, bool $enforce = true): array
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

        // Regras de completude só valem no ENVIO. Na prévia ($enforce=false) renderiza o que houver
        // (permite visualizar modelos ainda incompletos, ex.: marketing sem benefícios/CTA).
        if ($enforce) {
            if ($v['tipo_comunicacao'] === 'marketing') {
                if ($txt('intro') === '' && $txt('problema') === '') abort(422, 'Informe a introdução ou o cenário.');
                if (count($benefits) < 1) abort(422, 'Marketing exige ao menos 1 benefício.');
                if (empty($s['cta']['label']) || empty($s['cta']['url'])) abort(422, 'O botão (CTA) é obrigatório em comunicações de Marketing.');
            } elseif ($txt('content') === '') {
                abort(422, 'Informe o conteúdo da comunicação.');
            }
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
        $v = $this->validateStructured($request, false);
        $structure = $this->resolveSignature($v['structure'], $u);
        if ($request->filled('video_upload_id') || $request->boolean('has_video')) $structure['video'] = true;
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
            'hd_profile_ids'   => 'nullable|array',
            'hd_profile_ids.*' => 'integer',
            'video_upload_id'  => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
            'video_name'       => 'nullable|string|max:255',
        ]);
        // Vídeo: confere o upload ANTES de mandar o e-mail (o e-mail já anuncia o vídeo).
        $videoUid = (string) ($extra['video_upload_id'] ?? '');
        $videoTmp = $videoUid !== '' ? \Illuminate\Support\Facades\Storage::disk('local')->path(\App\Models\AppNotification::VIDEO_DIR . '/_tmp/' . $videoUid . '.part') : null;
        abort_if($videoTmp && (!file_exists($videoTmp) || filesize($videoTmp) === 0), 422, 'Upload do vídeo não encontrado — envie o arquivo novamente.');

        if (($extra['all_customers'] ?? false) && !($extra['confirm'] ?? false)) {
            abort(422, 'Envio para TODOS os clientes exige confirmação.');
        }

        $emails = $this->resolveEmails($request);
        abort_if(empty($emails), 422, 'Nenhum destinatário válido.');
        abort_unless(GraphMailSender::enabled(), 422, 'Envio de e-mail não está configurado no servidor.');

        // Remetente ESCOLHIDO no formulário (padrão: o próprio e-mail de quem envia). Se a Microsoft
        // recusar o envio em nome da pessoa, SenderMailbox cai no não-responder.
        $from = \App\Services\SenderMailbox::resolve($u, $request->input('from_email'));
        abort_unless($from, 422, 'Nenhuma conta de envio configurada.');

        // Assinatura padrão (remetente ou empresa) injetada na estrutura.
        $structure = $this->resolveSignature($v['structure'], $u);
        if ($videoTmp) $structure['video'] = true;

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
        [$ok, $err] = \App\Services\SenderMailbox::sendAs($from, [$from], [], $v['title'], $html, [], $inline, false, $emails);
        abort_if(!$ok, 422, 'Falha no envio: ' . $err);
        $usedFrom = \App\Services\SenderMailbox::$lastUsed; $fellBack = \App\Services\SenderMailbox::$lastFellBack;

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
            'hd_profile_ids'        => array_values(array_map('intval', (array) ($extra['hd_profile_ids'] ?? []))) ?: null,
            'recipients_count'      => count($emails),
            'sent_by'               => $u->id,
            'from_email'            => $usedFrom,
        ]);

        if ($videoTmp) $this->attachVideo($comm, $videoTmp, (string) ($extra['video_name'] ?? 'video.mp4'));

        return response()->json(['data' => ['sent' => count($emails), 'communication_id' => $comm->id, 'from' => $usedFrom, 'fell_back' => $fellBack]], 201);
    }

    /**
     * REENVIAR / ENVIAR PARA NOVAS PESSOAS uma comunicação já enviada.
     * - mode=all:   reenvia o e-mail a TODOS os destinatários atuais.
     * - mode=pick:  reenvia só aos e-mails escolhidos (que já são destinatários).
     * - mode=new:   inclui novas pessoas (contatos/usuários e e-mails externos) — passam a ver no
     *               Minutor (aba Comunicados) e recebem o e-mail. Ninguém que já recebeu é reenviado.
     */
    public function resend(Request $request, Communication $communication): JsonResponse
    {
        $u = $this->authorizeManager($request);
        $v = $request->validate([
            'mode'              => 'required|in:all,pick,new',
            'emails'            => 'nullable|array', 'emails.*' => 'email',
            'user_ids'          => 'nullable|array', 'user_ids.*' => 'integer|exists:users,id',
            'external_emails'   => 'nullable|array', 'external_emails.*' => 'email',
            'confirm'           => 'nullable|boolean',
        ]);
        abort_unless(GraphMailSender::enabled(), 422, 'Envio de e-mail não está configurado no servidor.');
        $c = $communication;
        $current = $this->resolveEmails(Request::create('/', 'POST', [
            'all_customers' => (bool) $c->all_customers, 'user_ids' => (array) $c->user_ids,
            'external_emails' => (array) $c->external_emails, 'hd_profile_ids' => (array) ($c->hd_profile_ids ?? []),
        ]));

        if ($v['mode'] === 'all') {
            abort_if(count($current) > 50 && !($v['confirm'] ?? false), 422, 'Reenvio para ' . count($current) . ' destinatários exige confirmação.');
            $to = $current;
        } elseif ($v['mode'] === 'pick') {
            $want = array_map(fn ($e) => mb_strtolower(trim($e)), (array) ($v['emails'] ?? []));
            $to = array_values(array_intersect($current, $want));
        } else {
            $newUsers = array_values(array_unique(array_map('intval', (array) ($v['user_ids'] ?? []))));
            $newExt = array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim($e)), (array) ($v['external_emails'] ?? []))));
            $cand = $this->resolveEmails(Request::create('/', 'POST', ['user_ids' => $newUsers, 'external_emails' => $newExt]));
            $to = array_values(array_diff($cand, $current)); // só quem ainda NÃO recebia
            $c->forceFill([
                'user_ids'         => array_values(array_unique(array_merge((array) $c->user_ids, $newUsers))),
                'external_emails'  => array_values(array_unique(array_merge((array) $c->external_emails, $newExt))),
                'recipients_count' => (int) $c->recipients_count + count($to),
            ])->save();
        }
        abort_if(empty($to), 422, $v['mode'] === 'new' ? 'Essas pessoas já recebem esta comunicação.' : 'Nenhum destinatário válido.');

        [$ok, $err] = $this->mailCommunication($c, $to);
        abort_if(!$ok, 422, 'Falha no envio: ' . $err);
        return response()->json(['data' => ['sent' => count($to)]]);
    }

    /** Monta o e-mail da comunicação (mesmo template do envio original) e envia em CCO. */
    private function mailCommunication(Communication $c, array $emails): array
    {
        $from = \App\Services\SenderMailbox::forRecord($c->from_email);
        if (!$from) return [false, 'Nenhuma conta de envio configurada.'];
        $structure = (array) $c->structure;
        $html = CommunicationRenderer::render($c->tipo_comunicacao, $c->title, $structure);
        [$html, $imgAtts] = HelpDeskMailComposer::inlineImages($html);
        $assets = array_filter([HelpDeskMailFooter::inlineWhiteLogo(), HelpDeskMailFooter::inlineLogo()]);
        if (is_array($structure['signature'] ?? null)) {
            $assets = array_merge($assets, \App\Services\SignatureRenderer::inlineAssets());
        }
        return \App\Services\SenderMailbox::sendAs($from, [$from], [], $c->title, $html, [], array_merge($assets, $imgAtts), false, $emails);
    }

    /** Move o upload (partes já montadas) p/ o destino final + faststart. */
    private function attachVideo(Communication $c, string $src, string $name): void
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $name = mb_substr($name, 0, 255);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'mp4';
        if (!in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true)) $ext = 'mp4';
        $rel = \App\Models\AppNotification::VIDEO_DIR . '/comm_' . $c->id . '_' . \Illuminate\Support\Str::random(8) . '.' . $ext;
        $dest = $disk->path($rel);
        if (!@rename($src, $dest)) { copy($src, $dest); @unlink($src); }
        if (in_array($ext, ['mp4', 'm4v', 'mov'], true)) {
            $tmp = $dest . '.fs';
            try { if (\App\Support\Mp4FastStart::process($dest, $tmp)) rename($tmp, $dest); } catch (\Throwable) {}
            if (file_exists($tmp)) @unlink($tmp);
        }
        @chmod($dest, 0644);
        $c->forceFill(['video_path' => $rel, 'video_name' => $name, 'video_size' => filesize($dest)])->save();
    }

    /** Stream do vídeo (link ASSINADO — <video> não manda Bearer). */
    public function video(Request $request, int $communication)
    {
        $path = Communication::whereKey($communication)->value('video_path');
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (!$path || !$disk->exists($path)) abort(404);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return response()->file($disk->path($path), [
            'Content-Type'  => match ($ext) { 'webm' => 'video/webm', 'mov' => 'video/quicktime', default => 'video/mp4' },
            'Cache-Control' => 'private, max-age=21600',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /** Histórico de envios. */
    public function index(Request $request): JsonResponse
    {
        $u = $this->authorizeManager($request);
        $theme = $request->query('theme') === 'dark' ? 'dark' : 'light';
        // Não-admin (coordenador/administrativo) só vê os comunicados que ELE enviou.
        $rows = Communication::with('sentBy:id,name')
            ->when(!$u->isAdmin(), fn ($q) => $q->where('sent_by', $u->id))
            ->orderByDesc('id')->limit(200)->get();
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
            'video_url'    => $c->video_url,
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
            'video_url'  => $c->video_url,
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
            'video_url'  => $c->video_url,
        ]);
        return response()->json(['data' => $data]);
    }

    /** LOG de um comunicado (admin): destinatários (inclui clientes) × leu/quando. Mesmo formato do log de notificações. */
    public function log(Request $request, Communication $communication): JsonResponse
    {
        $u = $this->authorizeManager($request);
        abort_if(!$u->isAdmin() && (int) $communication->sent_by !== (int) $u->id, 403, 'Você só pode ver o log dos comunicados que enviou.');

        $userIds = collect();
        if ($communication->all_customers) {
            $userIds = $this->scopeUsersToCompany(User::where('type', 'cliente'), $communication->company_id ? (int) $communication->company_id : null)->pluck('id');
        } else {
            if (!empty($communication->customer_ids)) {
                $userIds = $userIds->merge(User::whereIn('customer_id', (array) $communication->customer_ids)->pluck('id'));
            }
            if (!empty($communication->user_ids)) {
                $userIds = $userIds->merge((array) $communication->user_ids);
            }
        }
        $prof = array_filter((array) ($communication->hd_profile_ids ?? []));
        if ($prof) {
            // Público amplo filtrado por Perfil do HD; os escolhidos nominalmente continuam.
            $explicit = collect((array) ($communication->user_ids ?? []));
            $userIds = User::whereIn('id', $userIds->unique()->values())
                ->where(fn ($w) => $w->whereIn('helpdesk_access_profile_id', $prof)->orWhereIn('id', $explicit))->pluck('id');
        }
        // Mesma regra do ENVIO: só ativos e com e-mail (antes o log contava inativos → total inflado).
        $users = User::whereIn('id', $userIds->unique()->values())->where('enabled', true)->whereNotNull('email')
            ->get(['id', 'name', 'email', 'type', 'customer_id']);
        $custNames = \App\Models\Customer::withoutGlobalScopes()->whereIn('id', $users->pluck('customer_id')->filter()->unique()->values())->pluck('name', 'id');
        $reads = CommunicationRead::where('communication_id', $communication->id)->get()->keyBy('user_id');

        $rows = $users->map(fn (User $u) => [
            'user_id'      => $u->id,
            'user_name'    => $u->name,
            'user_email'   => $u->email,
            'customer'     => $u->customer_id ? $custNames->get($u->customer_id) : null,
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
        // "Ver como" (impersonação): não registra leitura/aceite em nome do cliente.
        if (($request->user()?->currentAccessToken()?->name ?? null) === 'impersonation') return response()->json(['ok' => true, 'impersonating' => true]);
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
        // Badge do menu (/me/badges, cache 15s) reflete a leitura na hora.
        \Illuminate\Support\Facades\Cache::forget("badges:{$u->id}");
        return response()->json(['data' => ['marked' => $ids->count()]]);
    }

    public function ack(Request $request): JsonResponse
    {
        // "Ver como" (impersonação): não registra leitura/aceite em nome do cliente.
        if (($request->user()?->currentAccessToken()?->name ?? null) === 'impersonation') return response()->json(['ok' => true, 'impersonating' => true]);
        $u = $this->client($request);
        $v = $request->validate(['id' => 'required|integer']);
        $exists = Communication::visible()->forClient($u)->whereKey($v['id'])->exists();
        abort_unless($exists, 404);

        $read = CommunicationRead::firstOrCreate(
            ['communication_id' => $v['id'], 'user_id' => $u->id],
            ['read_at' => now()],
        );
        if (!$read->acknowledged_at) { $read->acknowledged_at = now(); $read->save(); }

        return response()->json(['data' => ['acknowledged' => true]]);
    }

    /** Resolve a lista final de e-mails (clientes + usuários + externos), em minúsculo e únicos. */
    private function resolveEmails(Request $request): array
    {
        $all       = $request->boolean('all_customers');
        $userIds   = array_filter((array) $request->input('user_ids', []));
        $external  = (array) $request->input('external_emails', []);
        $prof      = array_values(array_filter(array_map('intval', (array) $request->input('hd_profile_ids', []))));

        // user_ids = contatos escolhidos (o FE carrega os usuários do cliente e deixa selecionar).
        // customer_ids vai só p/ o histórico. all_customers = todos os contatos de cliente.
        // Usuários inativos (enabled=false) NUNCA recebem comunicado (regra: inativo não é notificado).
        $emails = collect();
        if ($all) {
            // TODOS = todos os clientes DA EMPRESA ATIVA (ERPSERV ≠ BIZIFY).
            $emails = $emails->merge($this->scopeUsersToCompany(User::where('type', 'cliente'))->where('enabled', true)->whereNotNull('email')
                ->when($prof, fn ($q) => $q->whereIn('helpdesk_access_profile_id', $prof))->pluck('email'));
        } elseif ($userIds) {
            // Contatos escolhidos: o FE já marca só os do(s) perfil(is) — reforço no servidor.
            $emails = $emails->merge(User::whereIn('id', $userIds)->where('enabled', true)->whereNotNull('email')
                ->when($prof, fn ($q) => $q->where(fn ($w) => $w->where('type', '!=', 'cliente')->orWhereIn('helpdesk_access_profile_id', $prof)))->pluck('email'));
        }
        $emails = $emails->merge($external);

        return $emails->map(fn ($e) => mb_strtolower(trim((string) $e)))->filter()->unique()->values()->all();
    }
}
