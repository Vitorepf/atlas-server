<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Compounding\AtlasLearningCandidateGeneralizationService;
use Illuminate\Console\Command;

/**
 * MAXJ-03 read-only surface: emits abstract-lesson proposals for candidate
 * signatures with case_count >= floor. Never writes memory. Never routes
 * through ASI-02. Shadow-first, reversible.
 */
final class AtlasAiLearningGeneralizationCommand extends Command
{
    protected $signature = 'atlas:ai:learning-generalization
        {--floor= : Minimum unique candidates per signature to propose (default 8, hard floor 3)}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when no proposals were produced}';

    protected $description = 'MAXJ-03 — group ai_learning_candidates by {memory_type, primary_cause, scope}, dedupe by candidate_hash, and propose abstract lessons for signatures with case_count >= floor. Read-only.';

    public function handle(AtlasLearningCandidateGeneralizationService $service): int
    {
        $floorOpt = $this->option('floor');
        $floor = is_numeric($floorOpt) ? (int) $floorOpt : null;

        $report = $service->propose($floor);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Learning Generalization</>', (string) ($report['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Floor (effective)', (string) ($report['floor'] ?? '?'));
            $this->components->twoColumnDetail('Rows scanned', (string) data_get($report, 'totals.rows_scanned', 0));
            $this->components->twoColumnDetail('Duplicates collapsed', (string) data_get($report, 'totals.duplicates_collapsed', 0));
            $this->components->twoColumnDetail('Signatures', (string) data_get($report, 'totals.signatures', 0));
            $this->components->twoColumnDetail('Proposals', (string) data_get($report, 'totals.proposals', 0));
            foreach ((array) ($report['proposals'] ?? []) as $proposal) {
                $this->components->twoColumnDetail(
                    (string) ($proposal['signature_key'] ?? '?'),
                    'case_count='.(string) ($proposal['case_count'] ?? 0)
                        .' refs='.count((array) ($proposal['evidence_refs'] ?? [])),
                );
            }
        }

        if ((bool) $this->option('strict') && ($report['status'] ?? null) !== 'ok') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
