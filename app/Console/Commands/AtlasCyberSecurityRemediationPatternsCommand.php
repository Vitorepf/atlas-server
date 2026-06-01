<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCyberSecurityRemediationPatternsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cyber Remediation Patterns CLI.
 *
 *   php artisan atlas:aaeos:cyber-security-remediation-patterns
 *     [--cwe=CWE-89]                       // resolve the owning pattern for a CWE/LLM ref
 *     [--pattern=cyber-rem-sqli]           // or resolve by explicit pattern id
 *     [--covered=union,boolean_blind]      // variants the Negative PoC already covers
 *     [--json]
 *
 * Read-only, deterministic. A developer / cyber-* skill consults this router in
 * the remediation phase to resolve the canonical fix for a finding, the anti-
 * fixes to reject, and the Negative-PoC variant taxonomy that MUST be covered.
 * It also reports which taxonomy variants a Patch is still missing. It NEVER
 * applies a fix.
 *
 * @see docs/engineering-knowledge-base/cyber-security/remediation-patterns.md
 */
class AtlasCyberSecurityRemediationPatternsCommand extends Command
{
    protected $signature = 'atlas:aaeos:cyber-security-remediation-patterns
        {--cwe= : primary CWE token of the finding (e.g. CWE-89) or an LLM ref (LLM01)}
        {--pattern= : explicit cyber-rem-* pattern id (takes precedence over --cwe)}
        {--covered= : comma-separated variant ids the Negative PoC already covers}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas cyber · resolve the canonical remediation pattern (fix, anti-fixes, required Negative-PoC variants) for a finding from the remediation-patterns KB.';

    public function handle(AtlasCyberSecurityRemediationPatternsService $service): int
    {
        try {
            $finding = [
                'cwe' => $this->str('cwe') ?? 'CWE-89',
                'pattern_id' => $this->str('pattern'),
            ];

            $resolution = $service->resolve($finding);

            $missing = [];
            if (($resolution['matched'] ?? false) === true) {
                $missing = $service->missingVariants(
                    (string) $resolution['pattern_id'],
                    $this->list('covered'),
                );
            }

            $this->line((string) json_encode(
                [
                    'ok' => true,
                    'resolution' => $resolution,
                    'missing_variants' => $missing,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // A finding with no owning pattern is a non-zero exit so CI can gate.
            return ($resolution['remediable'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'cyber_remediation_patterns_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    private function str(string $option): ?string
    {
        $raw = $this->option($option);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return trim($raw);
    }

    /**
     * @return list<string>
     */
    private function list(string $option): array
    {
        $raw = $this->option($option);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn ($v) => $v !== '',
        ));
    }
}
