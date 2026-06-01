<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCodeCodexSlatePremiumService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Code Codex Slate Premium v1 — slate-teal canon guard CLI.
 *
 *   php artisan atlas:aaeos:atlas-code-codex-slate-premium [--json]
 *
 * Read-only, deterministic. Lints CSS/TSX source against the slate-premium canon
 * (forbidden warm-graphite/black bg, no pulse-halo status dots, calm
 * letter-spacing, no serif italic in body, slate tokens kept out of :root). With
 * no flags it inspects the doc's own canonical example snippet => ok=true. It
 * never mutates files or promotes status.
 *
 * @see docs/engineering-knowledge-base/atlas-code-codex-slate-premium-v1.md
 */
class AtlasCodeCodexSlatePremiumCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-code-codex-slate-premium
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Code slate-teal canon · lint CSS/TSX against the codex slate premium rules.';

    public function handle(AtlasCodeCodexSlatePremiumService $service): int
    {
        try {
            $result = $service->auditCanonExample();

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode(
                ['ok' => false, 'error' => $e->getMessage()],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return self::FAILURE;
        }
    }
}
