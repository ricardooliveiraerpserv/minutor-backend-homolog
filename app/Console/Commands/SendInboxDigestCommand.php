<?php

namespace App\Console\Commands;

use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendInboxDigestCommand extends Command
{
    protected $signature = 'inbox:digest
        {--min-quiet=15 : Minutos de inatividade do user antes de enviar digest}
        {--cooldown=60 : Mínimo de minutos entre digests pro mesmo user}
        {--dry : Apenas lista os candidatos sem enviar email}';

    protected $description = 'Envia digest por email aos usuários com mensagens não lidas no chat (anti-spam por cooldown).';

    public function handle(): int
    {
        $minQuiet  = (int) $this->option('min-quiet');
        $cooldown  = (int) $this->option('cooldown');
        $dryRun    = (bool) $this->option('dry');
        $now       = now();
        $threshold = $now->copy()->subMinutes($minQuiet);
        $cooldownThreshold = $now->copy()->subMinutes($cooldown);

        // 1) Mensagens novas (após last_read_at) cujo created_at >= threshold (já passaram do silêncio)
        // 2) User ativo + enabled + email + não desabilitou + cooldown ok
        $candidates = User::query()
            ->where('enabled', true)
            ->where(function ($q) { $q->whereNull('inbox_email_disabled')->orWhere('inbox_email_disabled', false); })
            ->whereNotNull('email')
            ->where(function ($q) use ($cooldownThreshold) {
                $q->whereNull('inbox_email_last_sent_at')
                  ->orWhere('inbox_email_last_sent_at', '<=', $cooldownThreshold);
            })
            ->get(['id', 'name', 'email', 'inbox_email_last_sent_at']);

        $sent = 0;
        $skipped = 0;
        foreach ($candidates as $user) {
            $unreadConvs = $this->collectUnreadConversations($user, $threshold);
            if ($unreadConvs->isEmpty()) { $skipped++; continue; }

            $total = $unreadConvs->sum('unread_count');
            $this->info("✉  {$user->email}: {$total} novas em {$unreadConvs->count()} conversa(s)");

            if (! $dryRun) {
                try {
                    Mail::raw($this->renderText($user, $unreadConvs), function ($message) use ($user, $total) {
                        $message->to($user->email, $user->name)
                            ->subject("[Minutor Chat] Você tem {$total} mensagem(ns) não lida(s)");
                    });
                    $user->update(['inbox_email_last_sent_at' => now()]);
                    $sent++;
                } catch (\Throwable $e) {
                    $this->error("✗ falha ao enviar pra {$user->email}: {$e->getMessage()}");
                }
            }
        }

        $this->info("Concluído. Enviados: $sent · Sem novidades: $skipped · " . ($dryRun ? 'DRY-RUN' : 'real'));
        return self::SUCCESS;
    }

    private function collectUnreadConversations(User $user, Carbon $threshold)
    {
        return ConversationParticipant::query()
            ->where('user_id', $user->id)
            ->with('conversation:id,type,title')
            ->get()
            ->map(function (ConversationParticipant $p) use ($threshold) {
                $newMessagesQuery = Message::query()
                    ->where('conversation_id', $p->conversation_id)
                    ->where('sender_user_id', '!=', $p->user_id)
                    ->whereNull('deleted_at')
                    ->where('created_at', '<=', $threshold);

                if ($p->last_read_at) {
                    $newMessagesQuery->where('created_at', '>', $p->last_read_at);
                }

                $count = $newMessagesQuery->count();
                if ($count === 0) return null;

                $latest = $newMessagesQuery->orderByDesc('created_at')->first(['id', 'body', 'created_at']);

                return [
                    'conversation_id'   => $p->conversation_id,
                    'conversation_type' => $p->conversation?->type?->value,
                    'conversation_title' => $p->conversation?->title,
                    'unread_count'      => $count,
                    'latest_preview'    => mb_substr(strip_tags($latest->body ?? ''), 0, 140),
                    'latest_at'         => $latest?->created_at?->toDateTimeString(),
                ];
            })
            ->filter()
            ->values();
    }

    private function renderText(User $user, $unreadConvs): string
    {
        $lines = [];
        $lines[] = "Olá, {$user->name}!";
        $lines[] = '';
        $lines[] = "Você tem novas mensagens não lidas no chat do Minutor:";
        $lines[] = '';
        foreach ($unreadConvs as $c) {
            $title = $c['conversation_title'] ?: ('Conversa #' . $c['conversation_id']);
            $type  = match ($c['conversation_type']) {
                'group' => '[Grupo] ',
                'bot'   => '[BOT] ',
                default => '',
            };
            $lines[] = "• {$type}{$title} ({$c['unread_count']} nova(s))";
            $lines[] = "   {$c['latest_preview']}";
            $lines[] = '';
        }
        $lines[] = '— Acesse o Minutor para responder.';
        $lines[] = '';
        $lines[] = 'Para desativar estes emails: vá em Usuários → seu perfil → desligue "Notificações por email".';
        return implode("\n", $lines);
    }
}
