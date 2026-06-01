<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDesktopService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Desktop surface-root guard CLI.
 *
 *   php artisan atlas:aaeos:atlas-desktop [--json]
 *
 * Read-only, deterministic. Runs the macOS install guardrail + desktop
 * parent-resolution + anti-mock contract over the doc's own canonical example
 * (a healthy /Applications with exactly one `Atlas Code.app`, the desktop
 * backend-contract and code-surface correctly parented to `atlas-desktop`, and
 * a canonical-sourced surface payload) and emits the audit + gate decision.
 *
 * @see docs/engineering-knowledge-base/atlas-desktop.md
 */
class AtlasDesktopCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-desktop
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Desktop · surface-root guard (macOS install guardrail + cartography-orphan-count-zero gate).';

    public function handle(AtlasDesktopService $service): int
    {
        try {
            // Safe default: the doc's own canonical, healthy example.
            $applications = [
                'Atlas Code.app',
                'Safari.app',
            ];

            $nodes = [
                ['id' => 'atlas', 'parent' => null, 'status' => 'active'],
                ['id' => 'atlas-desktop', 'parent' => 'atlas', 'status' => 'active'],
                ['id' => 'atlas-desktop-backend-contract', 'parent' => 'atlas-desktop', 'status' => 'active'],
                ['id' => 'atlas-desktop-code-surface', 'parent' => 'atlas-desktop', 'status' => 'active'],
            ];

            $surface = [
                'claims_kernel_authority' => false,
                'data_origin' => 'canonical',
            ];

            $audit = $service->audit($applications, $nodes, $surface);

            $this->line((string) json_encode(
                ['ok' => true, 'audit' => $audit],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_desktop_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
