<?php

namespace App\SourceCode\Analyzer;

/**
 * Detecta segredos hardcoded no fonte ANTES de qualquer uso por IA (Fase 2) ou persistência.
 * Regra: NUNCA persistir o segredo em claro. Retorna:
 *  - findings: [{type, location(line), severity}] — sem o valor do segredo;
 *  - masked:   cópia do código com os segredos substituídos por «REDACTED:tipo» (para a IA).
 * A ocorrência não é removida silenciosamente da análise: fica registrada em security_findings.
 */
class SecretSanitizer
{
    /** @var list<array{name:string,re:string,severity:string}> */
    private const PATTERNS = [
        ['name' => 'private_key',        're' => '/-----BEGIN(?:\s+[A-Z0-9]+)*\s+PRIVATE KEY-----[\s\S]*?-----END(?:\s+[A-Z0-9]+)*\s+PRIVATE KEY-----/', 'severity' => 'critical'],
        ['name' => 'aws_access_key',     're' => '/\bAKIA[0-9A-Z]{16}\b/', 'severity' => 'critical'],
        ['name' => 'github_token',       're' => '/\b(?:ghp|gho|ghs|ghr|github_pat)_[A-Za-z0-9_]{20,}\b/', 'severity' => 'critical'],
        ['name' => 'anthropic_key',      're' => '/\bsk-ant-[A-Za-z0-9-]{20,}\b/', 'severity' => 'critical'],
        ['name' => 'openai_key',         're' => '/\bsk-[A-Za-z0-9]{20,}\b/', 'severity' => 'critical'],
        ['name' => 'google_api_key',     're' => '/\bAIza[0-9A-Za-z_\-]{20,}\b/', 'severity' => 'critical'],
        ['name' => 'slack_token',        're' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/', 'severity' => 'critical'],
        ['name' => 'bearer_token',       're' => '/\bBearer\s+[A-Za-z0-9._\-]{12,}/i', 'severity' => 'high'],
        ['name' => 'connection_string',  're' => '/(?:Server|Data\s*Source|Host|Provider)\s*=[^;\n]+;[^\n]*(?:Password|Pwd|Senha)\s*=[^;\n]+/i', 'severity' => 'critical'],
        ['name' => 'password_assign',    're' => '/\b(?:password|passwd|pwd|senha|secret|api[_-]?key|apikey|token|client[_-]?secret)\s*[:=]{1,2}\s*["\'][^"\']{4,}["\']/i', 'severity' => 'high'],
    ];

    /** @return array{findings:list<array{type:string,location:int,severity:string}>, masked:string} */
    public function scan(string $code): array
    {
        $findings = [];
        $masked = $code;
        foreach (self::PATTERNS as $p) {
            if (preg_match_all($p['re'], $code, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $hit) {
                    $findings[] = [
                        'type'     => $p['name'],
                        'location' => substr_count(substr($code, 0, $hit[1]), "\n") + 1,
                        'severity' => $p['severity'],
                    ];
                }
                // mascara o valor no texto que futuramente iria à IA (nunca guarda o segredo)
                $masked = preg_replace($p['re'], '«REDACTED:' . $p['name'] . '»', $masked);
            }
        }
        // ordena por linha
        usort($findings, fn ($a, $b) => $a['location'] <=> $b['location']);
        return ['findings' => $findings, 'masked' => $masked];
    }
}
