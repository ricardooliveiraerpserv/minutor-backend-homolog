<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;

class EmailSentListener
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        if ($event instanceof MessageSending) {
            Log::info('📤 [EMAIL SENDING] Email sendo enviado:', [
                'to' => $event->message->getTo(),
                'subject' => $event->message->getSubject(),
                'from' => $event->message->getFrom(),
            ]);
        }

        if ($event instanceof MessageSent) {
            try {
                $originalMessage = $event->data['original'] ?? null;
                Log::info('✅ [EMAIL SENT] Email enviado com sucesso:', [
                    'response' => $event->response ? $event->response->getDebugInfo() : 'No response info',
                    'message_data' => $event->data,
                ]);
            } catch (\Exception $e) {
                Log::info('✅ [EMAIL SENT] Email enviado (dados limitados):', [
                    'event_class' => get_class($event),
                    'sent_message' => 'SentMessage object present'
                ]);
            }
        }
    }
}
