<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger\AtlasAaelExecutionStepStateInspector;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Arms the dormant orphan {@see AtlasAaelExecutionStepStateInspector::capturePre()} at the operator surface:
 * captures the deterministic PRE-step state snapshot for an AAEL execution step (allowed files, env, files
 * touched + sha256, inputs digest, step index/kind) and emits it as facts.
 *
 * Pure + read-only: it hashes/digests the supplied state and reports; it executes no step and mutates nothing.
 */
final class AtlasLoopAaelCaptureCommand extends Command
{
    protected $signature = 'atlas:loop:aael-capture {--run-id=} {--step-index=0} {--step=} {--env=} {--files=} {--json}';

    protected $description = 'Read-only AAEL pre-step state capture (snapshot of an execution step before it runs).';

    public function handle(): int
    {
        $runId = trim((string) $this->option('run-id'));
        if ($runId === '') {
            return $this->refuse('aael-capture requires --run-id=<id>');
        }
        $step = $this->readJsonObject('step');
        if ($step === null) {
            return $this->refuse('aael-capture requires --step=<json object with a `kind`>');
        }
        $stepIndex = max(0, (int) $this->option('step-index'));
        $env = array_merge(['cwd' => '', 'git_head' => '', 'dirty' => false], $this->readJsonObject('env') ?? []);
        $files = $this->readJsonList('files');
        $wallClockIso = gmdate('c');

        try {
            $snapshot = app(AtlasAaelExecutionStepStateInspector::class)
                ->capturePre($runId, $stepIndex, $step, $env, $files, $wallClockIso);
        } catch (InvalidArgumentException $e) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'capture_refused',
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $facts = ['run_id' => $runId] + $snapshot->toArray();

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('run_id: '.$runId.'  step_index: '.$facts['body']['step_index'].'  step_kind: '.$facts['body']['step_kind']);
        }

        return self::SUCCESS;
    }

    /** @return array<string,mixed>|null */
    private function readJsonObject(string $option): ?array
    {
        $raw = trim((string) $this->option($option));
        if ($raw === '') {
            return null;
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<string> */
    private function readJsonList(string $option): array
    {
        $raw = trim((string) $this->option($option));
        if ($raw === '') {
            return [];
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) && array_is_list($decoded) ? array_map('strval', $decoded) : [];
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
