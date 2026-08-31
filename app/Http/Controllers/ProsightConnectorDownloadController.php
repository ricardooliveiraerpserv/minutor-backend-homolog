<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Download do agente Connector DENTRO do Minutor — o cliente/técnico não precisa acessar o GitHub;
 * baixa direto do Minutor (mesmo canal do agente). Dois artefatos:
 *   (1) PACOTE-FONTE (Python, Windows/Linux) — servido pelo próprio Minutor a partir de
 *       resources/connector-agent/ (sempre disponível, sem dependência externa).
 *   (2) BINÁRIOS COMPILADOS (.exe Windows / binário Linux) — proxied do GitHub Release
 *       (best-effort; usa PROSIGHT_CONNECTOR_GH_TOKEN se o repo for privado). Se indisponível,
 *       a UI cai só no pacote-fonte.
 * Gate: prosight.operations.manage (UI admin).
 */
class ProsightConnectorDownloadController extends Controller
{
    private const GH_REPO = 'ricardooliveiraerpserv/prosight-connector-agent';

    /** Diretório da fonte do agente embarcada no backend. */
    private function agentDir(): string
    {
        return base_path('resources/connector-agent');
    }

    /** Token opcional p/ o GitHub (repo privado). Sem token, tenta anônimo (repo público). */
    private function ghToken(): ?string
    {
        $t = env('PROSIGHT_CONNECTOR_GH_TOKEN');
        return is_string($t) && $t !== '' ? $t : null;
    }

    private function ghHeaders(bool $octet = false): array
    {
        $h = ['Accept' => $octet ? 'application/octet-stream' : 'application/vnd.github+json', 'User-Agent' => 'Minutor-Prosight'];
        if ($tok = $this->ghToken()) { $h['Authorization'] = "Bearer {$tok}"; }
        return $h;
    }

    /**
     * GET /prosight/connector/agent/package — zipa a fonte do agente e envia como download.
     * Cross-platform (roda com Python 3.8+ no Windows e no Linux).
     */
    public function package(Request $request): StreamedResponse|JsonResponse
    {
        $dir = $this->agentDir();
        if (! is_dir($dir)) {
            return response()->json(['message' => 'Pacote do agente indisponível.'], 404);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'connector_');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            return response()->json(['message' => 'Falha ao montar o pacote.'], 500);
        }
        foreach (scandir($dir) as $f) {
            $full = $dir . DIRECTORY_SEPARATOR . $f;
            if (is_file($full)) {
                $zip->addFile($full, "prosight-connector-agent/{$f}");
            }
        }
        $zip->close();

        return response()->streamDownload(function () use ($tmp) {
            readfile($tmp);
            @unlink($tmp);
        }, 'prosight-connector-agent.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * GET /prosight/connector/agent/releases — metadados do último Release (binários compilados).
     * Best-effort: se o GitHub não responder/segredo faltar, devolve available=false (UI usa só a fonte).
     */
    public function releases(Request $request): JsonResponse
    {
        try {
            $resp = Http::withHeaders($this->ghHeaders())->timeout(8)
                ->get('https://api.github.com/repos/' . self::GH_REPO . '/releases/latest');
            if (! $resp->successful()) {
                return response()->json(['data' => ['available' => false, 'reason' => 'no_release']]);
            }
            $rel = $resp->json();
            $assets = [];
            foreach (($rel['assets'] ?? []) as $a) {
                $name = (string) ($a['name'] ?? '');
                $platform = str_ends_with($name, '.exe') ? 'windows'
                    : (str_contains($name, 'linux') ? 'linux'
                    : (str_ends_with($name, '.zip') ? 'source' : 'other'));
                $assets[] = [
                    'name'     => $name,
                    'size'     => (int) ($a['size'] ?? 0),
                    'platform' => $platform,
                    // O FE baixa via GET /prosight/connector/agent/download?asset=<name> (proxy do Minutor).
                ];
            }
            return response()->json(['data' => [
                'available'    => count($assets) > 0,
                'version'      => $rel['tag_name'] ?? null,
                'published_at' => $rel['published_at'] ?? null,
                'html_url'     => $rel['html_url'] ?? null,
                'assets'       => $assets,
            ]]);
        } catch (\Throwable $e) {
            return response()->json(['data' => ['available' => false, 'reason' => 'error']]);
        }
    }

    /**
     * GET /prosight/connector/agent/download?asset=NOME — proxied stream de 1 asset do Release.
     * O Minutor busca no GitHub (com token se privado) e repassa os bytes ao cliente.
     */
    public function download(Request $request): StreamedResponse|JsonResponse
    {
        $asset = (string) $request->query('asset', '');
        if ($asset === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $asset)) {
            return response()->json(['message' => 'Asset inválido.'], 422);
        }

        // Resolve o asset pelo nome no último release.
        $meta = Http::withHeaders($this->ghHeaders())->timeout(8)
            ->get('https://api.github.com/repos/' . self::GH_REPO . '/releases/latest');
        if (! $meta->successful()) {
            return response()->json(['message' => 'Release indisponível.'], 404);
        }
        $assetUrl = null;
        foreach (($meta->json()['assets'] ?? []) as $a) {
            if (($a['name'] ?? null) === $asset) { $assetUrl = $a['url'] ?? null; break; }
        }
        if (! $assetUrl) {
            return response()->json(['message' => 'Asset não encontrado.'], 404);
        }

        // Stream do binário (Accept: octet-stream faz a API do GitHub redirecionar p/ os bytes).
        $bin = Http::withHeaders($this->ghHeaders(true))->timeout(120)->get($assetUrl);
        if (! $bin->successful()) {
            return response()->json(['message' => 'Falha ao baixar o binário.'], 502);
        }
        $body = $bin->body();

        return response()->streamDownload(function () use ($body) {
            echo $body;
        }, $asset, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }
}
