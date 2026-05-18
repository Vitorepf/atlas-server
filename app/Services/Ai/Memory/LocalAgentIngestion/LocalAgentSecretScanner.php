<?php

namespace App\Services\Ai\Memory\LocalAgentIngestion;

/**
 * Detects common secret patterns inside ingested text and produces a redacted
 * copy. The scanner is intentionally PRE-CLASSIFICATION: the same content is
 * never persisted in raw form once a finding is detected — only the redacted
 * copy and the structured finding summary leave this layer.
 *
 * The patterns are tuned for high-precision common cases (Anthropic, OpenAI,
 * GitHub PATs, AWS access keys, PEM blocks, bearer tokens, .env-style
 * KEY=VALUE assignments where KEY contains secret/api/token/password). It is
 * NOT a comprehensive DLP scanner — production hardening would plug a real
 * scanner here. The pipeline's safety contract is layered: scanner produces
 * the best signal it can; if a class is `sensitive_secret`, promotion is
 * blocked regardless.
 *
 * @phpstan-type SecretFinding array{kind:string,start:int,length:int}
 */
final class LocalAgentSecretScanner
{
    /**
     * Ordered list of (kind, regex). Kinds appear in receipts and findings;
     * keep them stable. Regexes match the literal secret payload only.
     *
     * @var array<int,array{0:string,1:string}>
     */
    private const PATTERNS = [
        ['anthropic_api_key', '/sk-ant-[A-Za-z0-9_\-]{20,}/'],
        ['openai_api_key', '/sk-(?:proj-)?[A-Za-z0-9_\-]{20,}/'],
        ['github_pat', '/gh[pousr]_[A-Za-z0-9]{20,}/'],
        ['aws_access_key', '/\bAKIA[0-9A-Z]{16}\b/'],
        ['pem_private_key', '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----[\s\S]+?-----END (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/'],
        ['bearer_token', '/\bBearer\s+[A-Za-z0-9_\-\.]{20,}/'],
        ['env_assignment_secret', '/(?im)^(?:export\s+)?[A-Z][A-Z0-9_]*(?:SECRET|TOKEN|API[_-]?KEY|PASSWORD|PRIVATE[_-]?KEY|ACCESS[_-]?KEY)[A-Z0-9_]*\s*=\s*\S+/'],
    ];

    /**
     * @return array{redacted:string,findings:list<SecretFinding>,counts:array<string,int>}
     */
    public function scanAndRedact(string $content): array
    {
        $findings = [];
        $counts = [];

        $redacted = $content;
        foreach (self::PATTERNS as [$kind, $pattern]) {
            $redacted = preg_replace_callback(
                $pattern,
                function (array $m) use ($kind, &$findings, &$counts): string {
                    $match = (string) $m[0];
                    $findings[] = [
                        'kind' => $kind,
                        'start' => 0,
                        'length' => strlen($match),
                    ];
                    $counts[$kind] = ($counts[$kind] ?? 0) + 1;

                    return '['.strtoupper($kind).':REDACTED:'.strlen($match).']';
                },
                (string) $redacted,
            ) ?? $redacted;
        }

        return [
            'redacted' => $redacted,
            'findings' => $findings,
            'counts' => $counts,
        ];
    }

    public function hasFindings(string $content): bool
    {
        foreach (self::PATTERNS as [, $pattern]) {
            if (preg_match($pattern, $content) === 1) {
                return true;
            }
        }

        return false;
    }
}
