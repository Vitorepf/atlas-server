<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopIntelligenceOverlay;
use Illuminate\Console\Command;

final class AtlasLoopReviewFeedbackCommand extends Command
{
    protected $signature = 'atlas:loop:review-feedback
        {--run= : Unified loop run id or run directory}
        {--item= : Proposal/finding id or hash}
        {--path= : Proposal/finding path}
        {--mode= : Proposal/finding mode}
        {--action=approved : approved, applied, rejected, invalid, stale}
        {--reason= : Short review reason}
        {--operator=operator : Operator label}
        {--json : Machine-readable JSON output}';

    protected $description = 'Record append-only human review feedback for unified loop priority learning. Does not apply or merge anything.';

    public function handle(AtlasLoopIntelligenceOverlay $overlay): int
    {
        $runDir = $this->resolveRunDir();
        if ($runDir === null) {
            $payload = [
                'schema_version' => 'atlas.loop.review_feedback.write',
                'status' => 'failed',
                'reason' => 'run_not_found',
            ];
            $this->emit($payload);

            return self::FAILURE;
        }

        $payload = $overlay->recordFeedback($runDir, [
            'item' => $this->stringOption('item'),
            'path' => $this->stringOption('path'),
            'mode' => $this->stringOption('mode'),
            'action' => $this->stringOption('action') ?? 'approved',
            'reason' => $this->stringOption('reason'),
            'operator' => $this->stringOption('operator') ?? 'operator',
        ]);
        $this->emit($payload);

        return self::SUCCESS;
    }

    private function resolveRunDir(): ?string
    {
        $run = $this->stringOption('run');
        if ($run !== null) {
            if (is_dir($run)) {
                return rtrim($run, '/');
            }
            $candidate = storage_path('atlas/loop/unified/'.basename($run));

            return is_dir($candidate) ? $candidate : null;
        }

        $root = storage_path('atlas/loop/unified');
        $dirs = array_values(array_filter(glob($root.'/run-*') ?: [], 'is_dir'));
        usort($dirs, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $dirs[0] ?? null;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }
}
