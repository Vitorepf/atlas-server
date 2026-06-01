<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCyberSecurityExtensionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cyber Security Extension CLI.
 *
 *   php artisan atlas:aaeos:cyber-security-extension
 *     [--activity=bug_bounty_automation]   // scope admission check
 *     [--clauses=dos_explicit_clause]      // exception-clause tokens
 *     [--json]
 *
 * Read-only, deterministic. Returns the scope-admission verdict for the
 * requested activity plus the pinned extension manifest (status, scope counts,
 * promotion gate size). It NEVER executes recon, exploit, scan, or submission.
 *
 * @see docs/engineering-knowledge-base/cyber-security-extension.md
 */
class AtlasCyberSecurityExtensionCommand extends Command
{
    protected $signature = 'atlas:aaeos:cyber-security-extension
        {--activity= : requested activity id (e.g. bug_bounty_automation, dos_resource_exhaustion)}
        {--clauses= : comma-separated exception-clause tokens supplied by the operator}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas cyber extension · scope-admission + promotion-gate decider for the cyber-security extension (scaffold, never executes offensive actions).';

    public function handle(AtlasCyberSecurityExtensionService $service): int
    {
        try {
            $activityRaw = $this->option('activity');
            $activity = is_string($activityRaw) && trim($activityRaw) !== ''
                ? trim($activityRaw)
                : 'bug_bounty_automation';

            $scope = $service->classifyScope([
                'activity' => $activity,
                'clauses' => $this->list('clauses'),
            ]);

            $payload = [
                'ok' => true,
                'schema' => 'atlas.cyber_security_extension.v1',
                'scope' => $scope,
                'manifest' => $service->manifest(),
            ];

            $this->line($this->encode($payload));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
                'type' => $e::class,
            ];
            $this->line($this->encode($payload));

            return self::FAILURE;
        }
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
            static fn (string $v): bool => $v !== '',
        ));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
