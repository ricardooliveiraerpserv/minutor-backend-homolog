<?php

namespace App\Services\Clicksign;

use App\Models\ClicksignEnvelope;
use App\Models\ClicksignRequirement;
use App\Models\ClicksignSigner;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Integração Clicksign API v3 (Envelopes). Fase 4.2 = ENVIO para assinatura.
 *
 * Fluxo v3: Envelope → Document → Signers → Requirements → Activate → Notify.
 * Auth: header Authorization com o access_token; JSON:API (application/vnd.api+json).
 *
 * STUB de desenvolvimento: sem token configurado (enabled()=false), o serviço SIMULA as respostas
 * v3 (ids/sign_urls sintéticos) para validar persistência/status/auditoria no Replica sem conta real.
 * Com token, executa as chamadas HTTP v3 reais. Webhooks/captura/cancelamento = fases posteriores.
 */
class ClicksignService
{
    private const BASE = [
        'sandbox'    => 'https://sandbox.clicksign.com',
        'production' => 'https://app.clicksign.com',
    ];
    private const JSONAPI = 'application/vnd.api+json';

    public static function enabled(): bool
    {
        return !empty(config('services.clicksign.token'));
    }

    public function usandoStub(): bool
    {
        return !self::enabled();
    }

    public function environment(): string
    {
        return config('services.clicksign.env', 'sandbox') === 'production' ? 'production' : 'sandbox';
    }

    public function baseUrl(): string
    {
        return self::BASE[$this->environment()];
    }

    private function http()
    {
        return Http::withHeaders([
            'Authorization' => (string) config('services.clicksign.token'),
            'Accept'        => self::JSONAPI,
            'Content-Type'  => self::JSONAPI,
        ])->baseUrl($this->baseUrl() . '/api/v3')->timeout(30);
    }

    private function post(string $path, array $body): array
    {
        $r = $this->http()->post($path, $body);
        if (!$r->successful()) {
            throw new \RuntimeException("Clicksign v3 POST {$path} falhou (HTTP {$r->status()}): " . $r->body());
        }
        return (array) $r->json();
    }

    private function patch(string $path, array $body): array
    {
        $r = $this->http()->patch($path, $body);
        if (!$r->successful()) {
            throw new \RuntimeException("Clicksign v3 PATCH {$path} falhou (HTTP {$r->status()}): " . $r->body());
        }
        return (array) $r->json();
    }

    /**
     * ENVIO completo para assinatura (orquestra o ciclo v3 + persiste tudo). Idempotência de
     * "1 ativo por contrato" é garantida pelo índice parcial + checagem no controller.
     *
     * @param array<int,array{name:string,email?:string,documentation?:string,sign_order?:int,action?:string,auth?:string,role?:string,communicate_by?:string}> $signers
     * @param array{deadline_at?:string,subject?:string,motivo_envio?:string} $opts
     */
    public function enviar(Contract $contract, array $signers, array $opts, User $actor): ClicksignEnvelope
    {
        $doc = $contract->contractDocument;
        if (!$doc || !$doc->attachment_id) {
            throw new \RuntimeException('Contrato sem documento oficial gerado.');
        }
        if (empty($signers)) {
            throw new \RuntimeException('Informe ao menos um signatário.');
        }
        $stub = $this->usandoStub();
        $b64  = $this->pdfBase64($doc);
        $subject = $opts['subject'] ?? ('Assinatura — ' . ($contract->project_code_preview ?: ('Contrato ' . $contract->id)));

        // 1) Envelope
        $envAttrs = ['name' => $subject, 'locale' => 'pt-BR', 'auto_close' => true, 'default_subject' => $subject];
        if (!empty($opts['deadline_at'])) $envAttrs['deadline_at'] = $opts['deadline_at'];
        $envResp = $stub
            ? ['data' => ['id' => 'stub-env-' . Str::lower(Str::random(12)), 'attributes' => ['status' => 'draft']]]
            : $this->post('/envelopes', ['data' => ['type' => 'envelopes', 'attributes' => $envAttrs]]);
        $clEnvId = $envResp['data']['id'] ?? null;

        // 2) Documento
        $docResp = $stub
            ? ['data' => ['id' => 'stub-doc-' . Str::lower(Str::random(10))]]
            : $this->post("/envelopes/{$clEnvId}/documents", ['data' => ['type' => 'documents', 'attributes' => [
                'filename' => 'contrato-' . ($contract->project_code_preview ?: $contract->id) . '.pdf',
                'content_base64' => 'data:application/pdf;base64,' . $b64,
            ]]]);
        $clDocId = $docResp['data']['id'] ?? null;

        return DB::transaction(function () use ($contract, $doc, $signers, $opts, $actor, $stub, $clEnvId, $clDocId, $subject) {
            $envelope = ClicksignEnvelope::create([
                'contract_id'           => $contract->id,
                'document_id'           => $doc->id,
                'document_version'      => $doc->versao,
                'is_active'             => true,
                'environment'           => $this->environment(),
                'clicksign_envelope_id' => $clEnvId,
                'clicksign_document_id' => $clDocId,
                'status'                => ClicksignEnvelope::STATUS_DRAFT,
                'motivo_envio'          => $opts['motivo_envio'] ?? 'inicial',
                'default_subject'       => $subject,
                'locale'                => 'pt-BR',
                'deadline_at'           => $opts['deadline_at'] ?? null,
                'sent_by'               => $actor->id,
            ]);

            // 3) + 4) Signers + Requirements (na ordem informada — preparado p/ sequencial).
            $ordered = collect($signers)->sortBy(fn ($s) => (int) ($s['sign_order'] ?? 1))->values();
            foreach ($ordered as $i => $s) {
                $order = (int) ($s['sign_order'] ?? ($i + 1));
                $sigResp = $stub
                    ? ['data' => ['id' => 'stub-sig-' . Str::lower(Str::random(10)), 'attributes' => ['url' => $this->baseUrl() . '/sign/stub-' . Str::lower(Str::random(16))]]]
                    : $this->post("/envelopes/{$clEnvId}/signers", ['data' => ['type' => 'signers', 'attributes' => array_filter([
                        'name'              => $s['name'],
                        'email'             => $s['email'] ?? null,
                        'has_documentation' => !empty($s['documentation']),
                        'documentation'     => $s['documentation'] ?? null,
                        'communicate_by'    => $s['communicate_by'] ?? 'email',
                    ], fn ($v) => $v !== null)]]);
                $clSignerId = $sigResp['data']['id'] ?? null;
                $signUrl    = $sigResp['data']['attributes']['url'] ?? null;

                $signer = ClicksignSigner::create([
                    'envelope_id'         => $envelope->id,
                    'clicksign_signer_id' => $clSignerId,
                    'name'                => $s['name'],
                    'email'               => $s['email'] ?? null,
                    'documentation'       => $s['documentation'] ?? null,
                    'communicate_by'      => $s['communicate_by'] ?? 'email',
                    'sign_order'          => $order,
                    'sign_url'            => $signUrl,
                    'status'              => ClicksignSigner::STATUS_PENDING,
                ]);

                $action = $s['action'] ?? 'sign';
                $auth   = $s['auth'] ?? 'email';
                $reqResp = $stub
                    ? ['data' => ['id' => 'stub-req-' . Str::lower(Str::random(8))]]
                    : $this->post("/envelopes/{$clEnvId}/requirements", ['data' => [
                        'type' => 'requirements',
                        'attributes' => ['action' => $action, 'auth' => $auth, 'role' => $s['role'] ?? 'contractor'],
                        'relationships' => [
                            'document' => ['data' => ['type' => 'documents', 'id' => $clDocId]],
                            'signer'   => ['data' => ['type' => 'signers', 'id' => $clSignerId]],
                        ],
                    ]]);
                ClicksignRequirement::create([
                    'envelope_id'              => $envelope->id,
                    'signer_id'                => $signer->id,
                    'clicksign_requirement_id' => $reqResp['data']['id'] ?? null,
                    'action'                   => $action,
                    'auth'                     => $auth,
                    'role'                     => $s['role'] ?? 'contractor',
                ]);
            }

            // 5) Activate (running) + 6) Notify
            if (!$stub) {
                $this->patch("/envelopes/{$clEnvId}", ['data' => ['type' => 'envelopes', 'id' => $clEnvId, 'attributes' => ['status' => 'running']]]);
                try { $this->post("/envelopes/{$clEnvId}/notifications", ['data' => ['type' => 'notifications', 'attributes' => ['message' => $subject]]]); }
                catch (\Throwable $e) { Log::warning('Clicksign notify falhou (não-fatal): ' . $e->getMessage()); }
            } else {
                Log::info('[clicksign][stub] envelope ativado', ['contract' => $contract->id, 'envelope' => $clEnvId, 'signers' => $ordered->count()]);
            }
            $envelope->update(['status' => ClicksignEnvelope::STATUS_RUNNING, 'sent_at' => now()]);

            return $envelope->fresh(['signers', 'requirements']);
        });
    }

    /**
     * Fase 4.4 — baixa os artefatos do envelope CONCLUÍDO: PDF assinado, certificado e evidências.
     * Cada chamada re-obtém URLs FRESCAS (links Clicksign são temporários → retry pega URL nova).
     *
     * @return array{signed:?array,certificate:?array,evidences:?array} cada item: ['filename'=>,'bytes'=>,'mime'=>]
     */
    public function baixarAssinado(ClicksignEnvelope $envelope): array
    {
        $codigo = $envelope->contract?->project_code_preview ?: ('contrato-' . $envelope->contract_id);

        if ($this->usandoStub()) {
            // STUB: reusa o PDF do próprio Document como "assinado" + sintetiza certificado/evidências.
            $doc = \App\Models\Document::find($envelope->document_id);
            $pdf = $doc && $doc->attachment ? app(\App\Attachments\Storage\StorageProvider::class)->get($doc->attachment->storage_path) : '%PDF-1.4 stub';
            return [
                'signed'      => ['filename' => "{$codigo}-assinado.pdf", 'bytes' => $pdf, 'mime' => 'application/pdf'],
                'certificate' => ['filename' => "{$codigo}-certificado.pdf", 'bytes' => "%PDF-1.4\n% certificado de assinatura (stub) {$codigo}\n", 'mime' => 'application/pdf'],
                'evidences'   => ['filename' => "{$codigo}-evidencias.pdf", 'bytes' => "%PDF-1.4\n% log de evidencias (stub) envelope=" . $envelope->clicksign_envelope_id . " signers=" . $envelope->signers->pluck('name')->implode(',') . "\n", 'mime' => 'application/pdf'],
            ];
        }

        $envId = $envelope->clicksign_envelope_id;
        // v3: obtém o documento do envelope (com URLs frescas) e baixa cada artefato.
        $meta = (array) $this->http()->get("/envelopes/{$envId}/documents/{$envelope->clicksign_document_id}")->json('data.attributes', []);
        $out = ['signed' => null, 'certificate' => null, 'evidences' => null];
        $grab = function (?string $url, string $filename, string $mime) {
            if (!$url) return null;
            $r = Http::timeout(60)->get($url);
            if (!$r->successful()) throw new \RuntimeException("Clicksign download falhou (HTTP {$r->status()})");
            return ['filename' => $filename, 'bytes' => $r->body(), 'mime' => $mime];
        };
        $out['signed']      = $grab($meta['signed_file_url'] ?? ($meta['download_url'] ?? null), "{$codigo}-assinado.pdf", 'application/pdf');
        $out['certificate'] = $grab($meta['certificate_url'] ?? null, "{$codigo}-certificado.pdf", 'application/pdf');
        $out['evidences']   = $grab($meta['evidences_url'] ?? ($meta['log_url'] ?? null), "{$codigo}-evidencias.pdf", 'application/pdf');
        if (!$out['signed']) throw new \RuntimeException('Clicksign: URL do PDF assinado indisponível (possível expiração).');
        return $out;
    }

    /**
     * P-E.2.0 — envia a PROPOSTA para assinatura (Clicksign), reusando o fluxo de envelopes.
     * $signers: [['name','email','crm_proposal_participant_id','documentation'?,'sign_order'?]].
     */
    /** CPF válido → formatado "XXX.XXX.XXX-XX"; inválido/ausente → null (Clicksign rejeita CPF malformado/inválido). */
    private function cpfValido(?string $cpf): ?string
    {
        $d = preg_replace('/\D/', '', (string) $cpf);
        if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) return null;
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) $sum += (int) $d[$i] * (($t + 1) - $i);
            $dig = ((10 * $sum) % 11) % 10;
            if ((int) $d[$t] !== $dig) return null;
        }
        return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2);
    }

    public function enviarProposta(\App\Models\CrmProposal $p, array $signers, array $opts, User $actor): ClicksignEnvelope
    {
        $doc = $p->loadMissing('document')->document;
        if (!$doc || !$doc->attachment_id) throw new \RuntimeException('Proposta sem PDF gerado — gere o documento antes de enviar à assinatura.');
        if (empty($signers)) throw new \RuntimeException('Defina ao menos um assinante.');

        $stub = $this->usandoStub();
        // Validação ANTES de tocar o Clicksign (evita envelope órfão): o Clicksign exige nome completo (nome+sobrenome).
        if (!$stub) {
            $semNome = collect($signers)->filter(fn ($s) => count(array_filter(preg_split('/\s+/', trim((string) ($s['name'] ?? ''))))) < 2)
                ->map(fn ($s) => trim((string) ($s['name'] ?? '?')) . ' (' . ($s['email'] ?? '') . ')')->all();
            if (!empty($semNome)) {
                throw new \RuntimeException('Assinante(s) sem nome completo (nome e sobrenome): ' . implode('; ', $semNome) . '. Ajuste o nome desse assinante na seção Assinatura antes de enviar ao Clicksign.');
            }
        }
        $b64  = $this->pdfBase64($doc);
        $subject = $opts['subject'] ?? ('Assinatura — Proposta ' . ($p->codigo ?: $p->id));

        $envAttrs = ['name' => $subject, 'locale' => 'pt-BR', 'auto_close' => true, 'default_subject' => $subject];
        if (!empty($opts['deadline_at'])) $envAttrs['deadline_at'] = $opts['deadline_at'];
        $envResp = $stub
            ? ['data' => ['id' => 'stub-env-' . Str::lower(Str::random(12))]]
            : $this->post('/envelopes', ['data' => ['type' => 'envelopes', 'attributes' => $envAttrs]]);
        $clEnvId = $envResp['data']['id'] ?? null;

        $docResp = $stub
            ? ['data' => ['id' => 'stub-doc-' . Str::lower(Str::random(10))]]
            : $this->post("/envelopes/{$clEnvId}/documents", ['data' => ['type' => 'documents', 'attributes' => [
                'filename' => 'proposta-' . ($p->codigo ?: $p->id) . '.pdf',
                'content_base64' => 'data:application/pdf;base64,' . $b64,
            ]]]);
        $clDocId = $docResp['data']['id'] ?? null;

        return DB::transaction(function () use ($p, $doc, $signers, $opts, $actor, $stub, $clEnvId, $clDocId, $subject) {
            // só 1 envelope ativo por proposta
            ClicksignEnvelope::where('crm_proposal_id', $p->id)->where('is_active', true)->update(['is_active' => false]);
            $envelope = ClicksignEnvelope::create([
                'crm_proposal_id'       => $p->id,
                'document_id'           => $doc->id,
                'document_version'      => $p->versao,
                'is_active'             => true,
                'environment'           => $this->environment(),
                'clicksign_envelope_id' => $clEnvId,
                'clicksign_document_id' => $clDocId,
                'status'                => ClicksignEnvelope::STATUS_DRAFT,
                'motivo_envio'          => $opts['motivo_envio'] ?? 'inicial',
                'default_subject'       => $subject,
                'locale'                => 'pt-BR',
                'deadline_at'           => $opts['deadline_at'] ?? null,
                'sent_by'               => $actor->id,
            ]);

            $ordered = collect($signers)->sortBy(fn ($s) => (int) ($s['sign_order'] ?? 1))->values();
            foreach ($ordered as $i => $s) {
                $order = (int) ($s['sign_order'] ?? ($i + 1));
                $sigResp = $stub
                    ? ['data' => ['id' => 'stub-sig-' . Str::lower(Str::random(10)), 'attributes' => ['url' => $this->baseUrl() . '/sign/stub-' . Str::lower(Str::random(16))]]]
                    : (function () use ($clEnvId, $s) {
                        $cpf = $this->cpfValido($s['documentation'] ?? null); // formata se válido; null se inválido/ausente
                        return $this->post("/envelopes/{$clEnvId}/signers", ['data' => ['type' => 'signers', 'attributes' => array_filter([
                            'name' => trim((string) $s['name']), 'email' => $s['email'] ?? null,
                            'has_documentation' => $cpf !== null, 'documentation' => $cpf,
                        // v3: notificações por evento (substitui o `communicate_by` da v2, que não existe na v3).
                        // signature_request por e-mail = leva o token que autentica o signatário (validade jurídica),
                        // mesmo assinando embedado no Minutor. Sem lembretes; recibo final por e-mail.
                            'communicate_events' => [
                                'document_signed'    => 'email',
                                'signature_request'  => 'email',
                                'signature_reminder' => 'none',
                            ],
                        ], fn ($v) => $v !== null)]]);
                    })();
                $clSignerId = $sigResp['data']['id'] ?? null;
                // Sem o Widget Embedded (plano pago): NÃO usamos iframe. O Clicksign envia o link de assinatura
                // por e-mail (communicate_events.signature_request='email') e o assinante conclui na plataforma Clicksign.
                $signUrl = $stub ? ($sigResp['data']['attributes']['url'] ?? null) : null;

                $signer = ClicksignSigner::create([
                    'envelope_id' => $envelope->id, 'crm_proposal_participant_id' => $s['crm_proposal_participant_id'] ?? null,
                    'clicksign_signer_id' => $clSignerId, 'name' => $s['name'], 'email' => $s['email'] ?? null,
                    'documentation' => $s['documentation'] ?? null, 'communicate_by' => 'email',
                    'sign_order' => $order, 'sign_url' => $signUrl, 'status' => ClicksignSigner::STATUS_PENDING,
                ]);

                // v3 exige DOIS requisitos: (1) qualificação (action=agree, role=sign) e (2) autenticação (action=provide_evidence, auth=email).
                $rels = ['document' => ['data' => ['type' => 'documents', 'id' => $clDocId]], 'signer' => ['data' => ['type' => 'signers', 'id' => $clSignerId]]];
                if ($stub) {
                    $reqResp = ['data' => ['id' => 'stub-req-' . Str::lower(Str::random(8))]];
                } else {
                    $this->post("/envelopes/{$clEnvId}/requirements", ['data' => ['type' => 'requirements', 'attributes' => ['action' => 'agree', 'role' => 'sign'], 'relationships' => $rels]]);
                    $reqResp = $this->post("/envelopes/{$clEnvId}/requirements", ['data' => ['type' => 'requirements', 'attributes' => ['action' => 'provide_evidence', 'auth' => 'email'], 'relationships' => $rels]]);
                }
                ClicksignRequirement::create([
                    'envelope_id' => $envelope->id, 'signer_id' => $signer->id,
                    'clicksign_requirement_id' => $reqResp['data']['id'] ?? null, 'action' => 'sign', 'auth' => 'email', 'role' => 'sign',
                ]);
            }

            if (!$stub) {
                $this->patch("/envelopes/{$clEnvId}", ['data' => ['type' => 'envelopes', 'id' => $clEnvId, 'attributes' => ['status' => 'running']]]);
                try { $this->post("/envelopes/{$clEnvId}/notifications", ['data' => ['type' => 'notifications', 'attributes' => ['message' => $subject]]]); }
                catch (\Throwable $e) { Log::warning('Clicksign notify (proposta) falhou: ' . $e->getMessage()); }
            } else {
                Log::info('[clicksign][stub] envelope da proposta ativado', ['proposta' => $p->id, 'envelope' => $clEnvId, 'signers' => $ordered->count()]);
            }
            $envelope->update(['status' => ClicksignEnvelope::STATUS_RUNNING, 'sent_at' => now()]);
            return $envelope->fresh(['signers']);
        });
    }

    /** P-E.2.1 — reenvia a notificação de assinatura (cliente perdeu o e-mail/link). */
    public function reenviarNotificacao(ClicksignEnvelope $envelope, ?string $message = null): void
    {
        if ($this->usandoStub()) { Log::info('[clicksign][stub] reenvio de notificação', ['envelope' => $envelope->clicksign_envelope_id]); return; }
        $this->post("/envelopes/{$envelope->clicksign_envelope_id}/notifications", ['data' => ['type' => 'notifications', 'attributes' => ['message' => $message ?: $envelope->default_subject]]]);
    }

    /**
     * P-E.2.4 — consulta o estado atual do envelope no Clicksign (status + assinaturas), para sincronizar
     * sem depender de webhook (ex.: ambientes sem URL pública). Retorna ['status'=>..,'signers'=>[{id,status,signed_at}]].
     */
    public function statusEnvelope(ClicksignEnvelope $envelope): array
    {
        if ($this->usandoStub()) return ['status' => $envelope->status, 'signers' => [], 'stub' => true];
        $envId = $envelope->clicksign_envelope_id;
        $env = (array) $this->http()->get("/envelopes/{$envId}")->json('data.attributes', []);
        // v3 NÃO expõe o status de assinatura nos atributos do signer — vem nos EVENTOS (name='sign').
        $events = (array) $this->http()->get("/envelopes/{$envId}/events")->json('data', []);
        $signers = [];
        foreach ($events as $e) {
            if (($e['attributes']['name'] ?? null) !== 'sign') continue;
            $sg = (array) ($e['attributes']['data']['signer'] ?? []);
            if (empty($sg['key'])) continue;
            $signers[] = [
                'clicksign_signer_id' => $sg['key'],
                'status' => 'signed',
                'signed_at' => $e['attributes']['created'] ?? ($sg['signed_at'] ?? null),
                'ip' => $sg['address'] ?? null,
            ];
        }
        return ['status' => $env['status'] ?? $envelope->status, 'signers' => $signers, 'stub' => false];
    }

    /** P-E.2.1 — cancela o envelope (libera nova versão da proposta). */
    public function cancelar(ClicksignEnvelope $envelope): void
    {
        if (!$this->usandoStub()) {
            try { $this->patch("/envelopes/{$envelope->clicksign_envelope_id}", ['data' => ['type' => 'envelopes', 'id' => $envelope->clicksign_envelope_id, 'attributes' => ['status' => 'cancelled']]]); }
            catch (\Throwable $e) { Log::warning('Clicksign cancelar falhou: ' . $e->getMessage()); }
        }
        $envelope->update(['status' => ClicksignEnvelope::STATUS_CANCELLED, 'is_active' => false, 'finished_at' => now()]);
    }

    private function pdfBase64($doc): string
    {
        $att = $doc->attachment;
        $bytes = app(\App\Attachments\Storage\StorageProvider::class)->get($att->storage_path);
        return base64_encode($bytes);
    }

    /** Valida o HMAC do webhook (Content-Hmac) — usado na Fase 4.3. */
    public static function validarWebhook(string $payload, string $assinatura): bool
    {
        $secret = (string) config('services.clicksign.webhook_secret');
        if ($secret === '' || $assinatura === '') return false;
        $esperado = 'HMAC-SHA256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($esperado, $assinatura);
    }
}
