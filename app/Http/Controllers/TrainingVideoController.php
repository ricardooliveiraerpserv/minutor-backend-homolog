<?php

namespace App\Http\Controllers;

use App\Models\TrainingVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * "Aprenda o Minutor" — vídeos de treinamento por perfil.
 *
 *  - index        GET  /training-videos            → vídeos do público do usuário (admin: ?manage=1 = todos)
 *  - play         GET  /training-videos/{id}/play   → URL assinada (6h) para o <video> (a tag não envia Bearer)
 *  - stream       GET  /training-videos/{id}/stream → PÚBLICA + signed; BinaryFileResponse (suporta Range/seek)
 *  - uploadChunk  POST /training-videos/uploads/chunk  (admin) → upload em partes (vídeos > limite de body)
 *  - store/update/destroy (admin). Nova versão do arquivo = version+1 e content_updated_at = agora.
 *
 * Também publicável por script: `php artisan training-videos:publish`.
 */
class TrainingVideoController extends Controller
{
    private function deny(Request $request): ?JsonResponse
    {
        return $request->user()?->isAdmin() ? null : response()->json(['message' => 'Apenas administradores.'], 403);
    }

    private function present(TrainingVideo $v): array
    {
        return [
            'id'                 => $v->id,
            'title'              => $v->title,
            'description'        => $v->description,
            'category'           => $v->category,
            'audiences'          => array_values((array) ($v->audiences ?? [])),
            'has_file'           => (bool) $v->file_path,
            // Capa: link assinado (a tag <img> não envia Bearer). Versão no link → cache novo ao trocar.
            'cover_url'          => $v->cover_path ? URL::temporarySignedRoute('training-videos.cover', now()->addHours(12), [
                'video' => $v->id, 'c' => substr(md5($v->cover_path), 0, 8),
            ]) : null,
            'original_name'      => $v->original_name,
            'size'               => $v->size,
            'duration_seconds'   => $v->duration_seconds,
            'version'            => $v->version,
            'sort_order'         => $v->sort_order,
            'active'             => $v->active,
            'content_updated_at' => optional($v->content_updated_at)->toIso8601String(),
            'created_at'         => optional($v->created_at)->toIso8601String(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $manage = $user->isAdmin() && $request->boolean('manage');
        // Admin pode pré-visualizar a biblioteca de UM perfil (?profile=consultor_horista …).
        $profile = $user->isAdmin() ? $request->query('profile') : null;
        if ($profile !== null && !array_key_exists($profile, TrainingVideo::AUDIENCES)) $profile = null;
        $rows = TrainingVideo::query()
            ->when(!$manage, fn ($q) => $q->where('active', true)->whereNotNull('file_path'))
            ->orderBy('category')->orderBy('sort_order')->orderBy('title')
            ->get()
            ->filter(fn ($v) => $manage || ($profile ? $v->visibleToProfile($profile) : $v->visibleTo($user)))
            ->values()
            ->map(fn ($v) => $this->present($v));

        return response()->json([
            'data'      => $rows,
            'audiences' => TrainingVideo::AUDIENCES,
            'my_profiles' => $user->configuradorProfileKeys(),
        ]);
    }

    public function play(Request $request, TrainingVideo $video): JsonResponse
    {
        if (!$video->file_path || !$video->visibleTo($request->user())) {
            return response()->json(['message' => 'Vídeo indisponível.'], 404);
        }
        $url = URL::temporarySignedRoute('training-videos.stream', now()->addHours(6), [
            'video' => $video->id, 'v' => $video->version,
        ]);
        return response()->json(['url' => $url]);
    }

    public function stream(Request $request, TrainingVideo $video)
    {
        if (!$video->file_path || !Storage::disk('local')->exists($video->file_path)) abort(404);
        return response()->file(Storage::disk('local')->path($video->file_path), [
            'Content-Type'  => $video->mime ?: 'video/mp4',
            'Cache-Control' => 'private, max-age=21600',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    public function cover(Request $request, TrainingVideo $video)
    {
        if (!$video->cover_path || !Storage::disk('local')->exists($video->cover_path)) abort(404);
        return response()->file(Storage::disk('local')->path($video->cover_path), ['Cache-Control' => 'private, max-age=43200']);
    }

    /** Grava/troca a capa (imagem). Usado pela tela (multipart) e pelo script. */
    public static function attachCover(TrainingVideo $video, string $absSource, string $ext): void
    {
        $disk = Storage::disk('local');
        $ext = in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'webp'], true) ? strtolower($ext) : 'jpg';
        $disk->makeDirectory(TrainingVideo::DIR . '/covers');
        $rel = TrainingVideo::DIR . '/covers/' . $video->id . '_' . Str::random(8) . '.' . $ext;
        copy($absSource, $disk->path($rel));
        @chmod($disk->path($rel), 0644);
        $old = $video->cover_path;
        $video->forceFill(['cover_path' => $rel])->save();
        if ($old && $old !== $rel) $disk->delete($old);
    }

    public function uploadCover(Request $request, TrainingVideo $video): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;
        $request->validate(['cover' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120']);
        $f = $request->file('cover');
        self::attachCover($video, $f->getRealPath(), $f->getClientOriginalExtension() ?: $f->extension());
        return response()->json(['data' => $this->present($video->fresh())]);
    }

    /** Upload em partes: append sequencial em _tmp/{upload_id}.part (idempotente por offset). */
    public function uploadChunk(Request $request): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;
        $data = $request->validate([
            'upload_id' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
            'offset'    => 'required|integer|min:0',
            'chunk'     => 'required|file|max:20480',
        ]);
        $disk = Storage::disk('local');
        $rel = TrainingVideo::DIR . '/_tmp/' . $data['upload_id'] . '.part';
        $disk->makeDirectory(TrainingVideo::DIR . '/_tmp');
        $abs = $disk->path($rel);
        $size = file_exists($abs) ? filesize($abs) : 0;
        if ((int) $data['offset'] === $size) {
            $in = fopen($request->file('chunk')->getRealPath(), 'rb');
            $out = fopen($abs, 'ab');
            stream_copy_to_stream($in, $out);
            fclose($in); fclose($out);
            clearstatcache(true, $abs);
            $size = filesize($abs);
        } elseif ((int) $data['offset'] > $size) {
            return response()->json(['message' => 'Parte fora de ordem.', 'size' => $size], 409);
        }
        return response()->json(['size' => $size]);
    }

    private function rules(bool $create): array
    {
        return [
            'title'            => ($create ? 'required' : 'sometimes') . '|string|max:160',
            'description'      => 'nullable|string|max:4000',
            'category'         => 'nullable|string|max:80',
            'audiences'        => 'nullable|array',
            'audiences.*'      => 'string|in:' . implode(',', array_keys(TrainingVideo::AUDIENCES)),
            'sort_order'       => 'nullable|integer',
            'active'           => 'sometimes|boolean',
            'duration_seconds' => 'nullable|integer|min:0',
            'upload_id'        => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
            'original_name'    => 'nullable|string|max:255',
        ];
    }

    /** Move o .part montado para o destino definitivo e atualiza o vídeo (nova versão). */
    public static function attachFile(TrainingVideo $video, string $absSource, ?string $originalName, bool $move = true): void
    {
        $disk = Storage::disk('local');
        $ext = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION)) ?: 'mp4';
        if (!in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true)) $ext = 'mp4';
        $disk->makeDirectory(TrainingVideo::DIR);
        $newVersion = $video->file_path ? ($video->version + 1) : max(1, (int) $video->version);
        $rel = TrainingVideo::DIR . '/' . $video->id . '_v' . $newVersion . '_' . Str::random(6) . '.' . $ext;
        $dest = $disk->path($rel);
        $move ? rename($absSource, $dest) : copy($absSource, $dest);
        // MP4 com o índice (moov) no fim não começa a tocar no navegador → reorganiza (sem perda).
        if (in_array($ext, ['mp4', 'm4v', 'mov'], true)) {
            $tmp = $dest . '.fs';
            try {
                if (\App\Support\Mp4FastStart::process($dest, $tmp)) rename($tmp, $dest);
            } catch (\Throwable $e) {
                \Log::warning('training-videos: faststart falhou', ['file' => $rel, 'err' => $e->getMessage()]);
            }
            if (file_exists($tmp)) @unlink($tmp);
        }
        @chmod($dest, 0644);
        $old = $video->file_path;
        $video->forceFill([
            'file_path'          => $rel,
            'original_name'      => $originalName ?: basename($dest),
            'mime'               => match ($ext) { 'webm' => 'video/webm', 'mov' => 'video/quicktime', default => 'video/mp4' },
            'size'               => filesize($dest),
            'version'            => $newVersion,
            'content_updated_at' => now(),
        ])->save();
        if ($old && $old !== $rel) $disk->delete($old);
    }

    private function consumeUpload(TrainingVideo $video, array $data): void
    {
        if (empty($data['upload_id'])) return;
        $abs = Storage::disk('local')->path(TrainingVideo::DIR . '/_tmp/' . $data['upload_id'] . '.part');
        if (!file_exists($abs) || filesize($abs) === 0) abort(422, 'Upload do vídeo não encontrado — envie o arquivo novamente.');
        self::attachFile($video, $abs, $data['original_name'] ?? null);
    }

    public function store(Request $request): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;
        $data = $request->validate($this->rules(true));
        $video = TrainingVideo::create([
            'title'            => $data['title'],
            'description'      => $data['description'] ?? null,
            'category'         => $data['category'] ?? null,
            'audiences'        => array_values($data['audiences'] ?? []),
            'sort_order'       => $data['sort_order'] ?? 0,
            'active'           => $data['active'] ?? true,
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'version'          => 1,
            'created_by_id'    => $request->user()->id,
            'updated_by_id'    => $request->user()->id,
        ]);
        $this->consumeUpload($video, $data);
        return response()->json(['data' => $this->present($video->fresh())], 201);
    }

    public function update(Request $request, TrainingVideo $video): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;
        $data = $request->validate($this->rules(false));
        $fields = collect($data)->only(['title', 'description', 'category', 'sort_order', 'active', 'duration_seconds'])->all();
        if (array_key_exists('audiences', $data)) $fields['audiences'] = array_values($data['audiences'] ?? []);
        $fields['updated_by_id'] = $request->user()->id;
        $video->fill($fields)->save();
        $this->consumeUpload($video, $data);
        return response()->json(['data' => $this->present($video->fresh())]);
    }

    public function destroy(Request $request, TrainingVideo $video): JsonResponse
    {
        if ($r = $this->deny($request)) return $r;
        if ($video->file_path) Storage::disk('local')->delete($video->file_path);
        if ($video->cover_path) Storage::disk('local')->delete($video->cover_path);
        $video->delete();
        return response()->json(['ok' => true]);
    }
}
