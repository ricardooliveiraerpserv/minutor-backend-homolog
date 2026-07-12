<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'conversation_id' => $this->conversation_id,
            'type'            => [
                'value' => $this->type?->value,
                'label' => $this->type?->label(),
            ],
            'sender'          => $this->whenLoaded('sender', fn () => $this->sender ? [
                'id'                 => $this->sender->id,
                'name'               => $this->sender->name,
                'profile_photo_url'  => $this->sender->profile_photo_url ?? null,
            ] : null),
            'body'            => $this->body,
            'metadata'        => $this->metadata,
            'reply_to_id'     => $this->reply_to_id,
            'reply_to'        => $this->whenLoaded('replyTo', fn () => $this->replyTo ? [
                'id'      => $this->replyTo->id,
                'body'    => $this->replyTo->deleted_at ? '[mensagem excluída]' : mb_substr(strip_tags($this->replyTo->body ?? ''), 0, 140),
                'sender'  => $this->replyTo->sender ? [
                    'id'   => $this->replyTo->sender->id,
                    'name' => $this->replyTo->sender->name,
                ] : null,
            ] : null),
            'status'          => $this->status?->value ?? 'unread',
            'snoozed_until'   => $this->snoozed_until?->toIso8601String(),
            'resolved_at'     => $this->resolved_at?->toIso8601String(),
            'created_at'      => $this->created_at?->toIso8601String(),
            'edited_at'       => $this->edited_at?->toIso8601String(),
            'deleted_at'      => $this->deleted_at?->toIso8601String(),
            'pinned_at'       => $this->pinned_at?->toIso8601String(),
            'pinned_by'       => $this->pinned_by,
            'is_favorite'     => $this->whenLoaded('favorites', function () use ($request) {
                $uid = $request->user()?->id;
                return $uid ? $this->favorites->contains('user_id', $uid) : false;
            }, false),
            'reactions'       => $this->whenLoaded('reactions', function () use ($request) {
                $currentUserId = $request->user()?->id;
                $groups = [];
                foreach ($this->reactions as $r) {
                    $emoji = $r->emoji;
                    if (! isset($groups[$emoji])) {
                        $groups[$emoji] = ['emoji' => $emoji, 'count' => 0, 'by_me' => false, 'users' => []];
                    }
                    $groups[$emoji]['count']++;
                    if ($r->user_id === $currentUserId) $groups[$emoji]['by_me'] = true;
                    if ($r->user) {
                        $groups[$emoji]['users'][] = ['id' => $r->user->id, 'name' => $r->user->name];
                    }
                }
                return array_values($groups);
            }, []),
            'attachments'     => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id'       => $a->id,
                'filename' => $a->filename,
                'mime'     => $a->mime,
                'size'     => $a->size,
                'url'      => $a->url,
                'is_image' => $a->isImage(),
                'is_audio' => $a->isAudio(),
            ])->all(), []),
        ];
    }
}
