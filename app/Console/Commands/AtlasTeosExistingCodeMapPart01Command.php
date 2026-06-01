<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasTeosExistingCodeMapPart01Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas TEOS Existing Code Map · Parte 1 anti-duplication CLI.
 *
 *   php artisan atlas:aaeos:teos-existing-code-map-part-01 [--json]
 *
 * Read-only, deterministic. Evaluates a proposed component name against the
 * documented "Nao-greenfield" blocks and greenfield allow-list, so a forbidden
 * parallel class (e.g. LongHorizonCompactionEngine) is refused with its existing
 * reuse target. It NEVER edits code, creates a class, writes to disk or mutates
 * memory.
 *
 * With no options it runs a canonical forbidden proposal
 * (LongHorizonCompactionEngine) which must come back verdict=blocked — that
 * doubles as a self-check that the anti-duplication gate is wired and enforcing.
 *
 * @see docs/engineering-knowledge-base/atlas-teos-existing-code-map-part-01.md
 */
class AtlasTeosExistingCodeMapPart01Command extends Command
{
    protected $signature = 'atlas:aaeos:teos-existing-code-map-part-01 {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas TEOS · evaluate a proposed component against the Part 01 inventory and block forbidden parallel classes.';

    public function handle(AtlasTeosExistingCodeMapPart01Service $service): int
    {
        try {
            $proposal = $service->evaluateProposal('LongHorizonCompactionEngine');

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $proposal],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // The canonical default is a known forbidden parallel: a healthy gate
            // must BLOCK it. If it ever stops blocking, fail loudly.
            return $proposal['verdict'] === AtlasTeosExistingCodeMapPart01Service::VERDICT_BLOCKED
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'teos_existing_code_map_part_01_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
