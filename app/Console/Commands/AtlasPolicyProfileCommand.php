<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasPolicyProfileService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the kernel Policy Profile step.
 *
 * Resolves the permission envelope (sandbox, autonomy, cost, tools) that bounds
 * Atlas Decide for a given intent, risk and environment.
 *
 * @see docs/engineering-knowledge-base/system-graph/policy-profile.md
 */
final class AtlasPolicyProfileCommand extends Command
{
    protected $signature = 'atlas:aaeos:policy-profile {--json : Machine-readable JSON output}';

    protected $description = 'Resolve the Policy Profile permission envelope (sandbox, autonomy, cost, tools) before Atlas Decide.';

    public function handle(AtlasPolicyProfileService $service): int
    {
        try {
            // Safe default: a destructive intent with no signature, proving the
            // invariant that a dangerous action is blocked until authorized.
            $result = $service->resolveProfile([
                'intent' => 'programming.delete_files',
                'risk' => 'high',
                'environment' => 'workspace',
                'signature_present' => false,
            ]);
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
