<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionReadModel;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Diff\AtlasCortexSnapshotDiffEngine;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Diff\AtlasCortexSnapshotDiffExporter;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Diff\AtlasCortexSnapshotDiffReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Diff\AtlasCortexSnapshotDiffSummary;
use Illuminate\Console\Command;

/**
 * Read-only CLI over the cortex snapshot diff surface.
 *   inspect — print the full categorized diff array (schema atlas.cortex.snapshot_diff.v1).
 *   summary — print only the AtlasCortexSnapshotDiffSummary digest (no aggregate scalars).
 *   export  — write JSONL via the exporter to --out and print {exported, path, sha256}.
 *
 * EVERY invocation records ONE receipt in the AtlasCortexSnapshotDiffReceiptLedger so the diff
 * observation is auditable (consistent with the B4 atlas:loop:comprehend observability contract).
 *
 * Read-only over the loop's brain — never mutates a snapshot, never re-builds the comprehension
 * model. Exits non-zero (with a JSON error) when a requested snapshot_id is absent; no receipt is
 * recorded on that failure path.
 */
final class AtlasLoopCortexDiffCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:cortex:diff
        {action : inspect|summary|export}
        {--left= : left snapshot_id (default: previous latest for scope)}
        {--right= : right snapshot_id (default: latest for scope)}
        {--scope= : scope_root relative path, e.g. app/Services/Ai/AutonomousEvolution}
        {--out= : destination path for export action}
        {--json}';

    /** @var string */
    protected $description = 'Cortex snapshot diff CLI: inspect | summary | export (read-only, auditable).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['inspect', 'summary', 'export'], true)) {
            return $this->emit(['error' => 'unknown_action:'.$action], self::FAILURE);
        }

        $readModel = $this->app()->make(AtlasLoopScopeComprehensionReadModel::class);
        $scope = (string) $this->option('scope');
        $left = (string) $this->option('left');
        $right = (string) $this->option('right');

        $leftModel = $left !== '' ? $readModel->get($left) : $readModel->previousLatestFor($scope);
        if ($leftModel === null) {
            return $this->emit(['error' => 'left_snapshot_missing', 'left' => $left, 'scope' => $scope], self::FAILURE);
        }
        $rightModel = $right !== '' ? $readModel->get($right) : $readModel->latestFor($scope);
        if ($rightModel === null) {
            return $this->emit(['error' => 'right_snapshot_missing', 'right' => $right, 'scope' => $scope], self::FAILURE);
        }

        $engine = $this->app()->make(AtlasCortexSnapshotDiffEngine::class);
        $diff = $engine->diff($leftModel, $rightModel);

        $summarizer = $this->app()->make(AtlasCortexSnapshotDiffSummary::class);
        $summary = $summarizer->summarize($diff + [
            'from_snapshot_id' => $leftModel->snapshotId,
            'to_snapshot_id' => $rightModel->snapshotId,
        ]);

        $ledger = $this->app()->make(AtlasCortexSnapshotDiffReceiptLedger::class);
        $ledger->record($leftModel->snapshotId, $rightModel->snapshotId, $scope, $diff, $summary);

        return match ($action) {
            'inspect' => $this->emit($diff),
            'summary' => $this->emit($summary),
            'export' => $this->doExport($diff),
            default => $this->emit(['error' => 'unknown_action:'.$action], self::FAILURE),
        };
    }

    /**
     * @param  array<string,mixed>  $diff
     */
    private function doExport(array $diff): int
    {
        $out = (string) $this->option('out');
        if ($out === '') {
            return $this->emit(['error' => 'out_required'], self::FAILURE);
        }
        $exporter = $this->app()->make(AtlasCortexSnapshotDiffExporter::class);
        $receipt = $exporter->export($diff, $out, (string) $this->option('scope'));

        return $this->emit($receipt);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit = self::SUCCESS): int
    {
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $exit;
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
