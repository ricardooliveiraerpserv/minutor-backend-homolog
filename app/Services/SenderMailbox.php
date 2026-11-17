<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * REMETENTE dos e-mails de publicações/comunicações (escolhido no formulário).
 * Opções: o PRÓPRIO e-mail de quem envia (padrão), o "não responder" do Minutor e as caixas do
 * Help Desk (suporte@…). Envio pelo Graph (permissão de aplicação); se a Microsoft recusar o envio
 * em nome da pessoa (caixa fora do tenant / sem permissão), cai AUTOMATICAMENTE no "não responder".
 */
class SenderMailbox
{
    /** Domínios cujas caixas o Minutor envia em nome (tenant Microsoft da ERPSERV/BIZIFY). */
    private const OWN_DOMAINS = ['erpserv.com.br', 'bizify.com.br', 'minutor.com.br'];

    public static ?string $lastUsed = null;
    public static bool $lastFellBack = false;

    public static function noreply(): ?string
    {
        return config('services.graph.mailbox', env('GRAPH_MAILBOX', env('MAIL_FROM_ADDRESS')))
            ?: \App\Models\HelpDeskEmailAccount::where('provider', 'microsoft365')->where('enabled', true)->orderBy('id')->value('email');
    }

    /** @return array<int, array{email:string,label:string,kind:string}> */
    public static function options(?User $u): array
    {
        $out = [];
        $own = $u?->email ? mb_strtolower(trim($u->email)) : null;
        if ($own && in_array(substr(strrchr($own, '@'), 1), self::OWN_DOMAINS, true)) {
            $out[] = ['email' => $own, 'label' => ($u->name ? $u->name . ' — ' : '') . $own . ' (você)', 'kind' => 'me'];
        }
        if ($nr = self::noreply()) {
            $out[] = ['email' => mb_strtolower($nr), 'label' => 'Minutor — ' . mb_strtolower($nr) . ' (não responder)', 'kind' => 'noreply'];
        }
        foreach (\App\Models\HelpDeskEmailAccount::withoutGlobalScopes()->where('enabled', true)->get(['email', 'name']) as $a) {
            $e = mb_strtolower(trim((string) $a->email));
            if ($e && !collect($out)->contains('email', $e)) {
                $out[] = ['email' => $e, 'label' => ($a->name ?: 'Help Desk') . ' — ' . $e, 'kind' => 'helpdesk'];
            }
        }
        return $out;
    }

    /** Valida a escolha do formulário; inválida/vazia → o próprio e-mail (se permitido) ou o não-responder. */
    public static function resolve(?User $u, ?string $chosen): ?string
    {
        $opts = collect(self::options($u))->pluck('email');
        $c = $chosen ? mb_strtolower(trim($chosen)) : null;
        if ($c && $opts->contains($c)) return $c;
        return $opts->first() ?: self::noreply();
    }

    /** Remetente gravado na publicação (ou o não-responder quando não há). */
    public static function forRecord(?string $stored): ?string
    {
        return $stored ?: self::noreply();
    }

    /**
     * GraphMailSender::sendAs com FALLBACK: se o envio em nome de $from falhar, reenvia pelo
     * não-responder (o "Para" que era o próprio remetente também troca). Retorna [ok, erro].
     */
    public static function sendAs(string $from, array $to, ...$rest): array
    {
        self::$lastUsed = $from; self::$lastFellBack = false;
        [$ok, $err] = GraphMailSender::sendAs($from, $to, ...$rest);
        $nr = self::noreply();
        if (!$ok && $nr && mb_strtolower($from) !== mb_strtolower($nr)) {
            Log::warning('📧 [REMETENTE] envio em nome de ' . $from . ' falhou — usando o não-responder', ['erro' => $err]);
            $to2 = array_map(fn ($e) => mb_strtolower((string) $e) === mb_strtolower($from) ? $nr : $e, $to);
            [$ok, $err] = GraphMailSender::sendAs($nr, $to2, ...$rest);
            self::$lastUsed = $nr; self::$lastFellBack = true;
        }
        return [$ok, $err];
    }
}
