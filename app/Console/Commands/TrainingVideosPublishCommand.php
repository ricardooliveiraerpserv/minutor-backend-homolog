<?php

namespace App\Console\Commands;

use App\Http\Controllers\TrainingVideoController;
use App\Models\TrainingVideo;
use Illuminate\Console\Command;

/**
 * Publica (ou atualiza) um vídeo do "Aprenda o Minutor" por script.
 *  php artisan training-videos:publish /tmp/HelpDesk.mp4 --title="Help Desk" --category="Help Desk" \
 *      --audiences=consultor_horista,consultor_fixo --duration=1480 --sort=10
 * Se já existir vídeo com o mesmo título (ou --id), troca o arquivo e sobe a versão.
 * --audiences vazio = todos os perfis. Perfis válidos: ver TrainingVideo::AUDIENCES.
 */
class TrainingVideosPublishCommand extends Command
{
    protected $signature = 'training-videos:publish {file} {--title=} {--id=} {--category=} {--description=}
        {--audiences=} {--duration=} {--sort=} {--cover= : Imagem de capa (jpg/png/webp)} {--keep : Copia o arquivo em vez de mover}';

    protected $description = 'Publica/atualiza um vídeo de treinamento (Aprenda o Minutor)';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (!is_file($file)) { $this->error("Arquivo não encontrado: $file"); return 1; }

        $video = $this->option('id') ? TrainingVideo::find($this->option('id'))
            : ($this->option('title') ? TrainingVideo::where('title', $this->option('title'))->first() : null);
        if (!$video && !$this->option('title')) { $this->error('Informe --title (novo) ou --id (existente).'); return 1; }

        $aud = null;
        if ($this->option('audiences') !== null) {
            $aud = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('audiences')))));
            $bad = array_filter(array_diff($aud, array_keys(TrainingVideo::AUDIENCES)), fn ($t) => !TrainingVideo::isRestrictionToken($t));
            if ($bad) { $this->error('Perfis inválidos: ' . implode(', ', $bad)); return 1; }
        }

        $video ??= new TrainingVideo(['version' => 1, 'active' => true, 'audiences' => []]);
        foreach (['title' => 'title', 'category' => 'category', 'description' => 'description'] as $opt => $col) {
            if ($this->option($opt) !== null) $video->{$col} = $this->option($opt);
        }
        if ($aud !== null) $video->audiences = $aud;
        if ($this->option('duration') !== null) $video->duration_seconds = (int) $this->option('duration');
        if ($this->option('sort') !== null) $video->sort_order = (int) $this->option('sort');
        $video->save();

        TrainingVideoController::attachFile($video, $file, basename($file), !$this->option('keep'));
        if ($c = $this->option('cover')) {
            if (!is_file($c)) { $this->warn("Capa não encontrada: $c"); }
            else TrainingVideoController::attachCover($video, $c, pathinfo($c, PATHINFO_EXTENSION));
        }
        $video->refresh();
        $this->info("OK #{$video->id} \"{$video->title}\" v{$video->version} (" . round($video->size / 1048576, 1) . ' MB) público=' . (implode(',', $video->audiences ?: []) ?: 'todos'));
        return 0;
    }
}
