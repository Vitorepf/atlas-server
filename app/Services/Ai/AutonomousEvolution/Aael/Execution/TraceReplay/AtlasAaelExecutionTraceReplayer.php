<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\TraceReplay;

use RuntimeException;

/**
 * Replays a JSONL trace produced by AtlasAaelExecutionTraceRecorder.
 *
 * Trace format (one JSON object per line):
 *   line 0: {"_type":"manifest", "trace_id":..., "root_commit":..., "terminal_status":...}
 *   line N: {"_type":"step", "step_index":N, "action":..., "input":..., "output_b64":..., "output_fingerprint":sha256-hex}
 *
 * FACT producer only — never scores/grades/auto-corrects. Never mutates the source trace file.
 */
final class AtlasAaelExecutionTraceReplayer
{
    public function __construct(private readonly string $rootCommitAtReplay) {}

    public function replay(
        string $tracePath,
        AaelStepActor $actor,
        ?int $fromStep = null,
        ?int $toStep = null,
    ): ReplayReport {
        if (! is_file($tracePath)) {
            throw new RuntimeException('replayer_trace_missing:'.$tracePath);
        }
        $lines = (array) file($tracePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (count($lines) === 0) {
            throw new RuntimeException('replayer_manifest_missing_empty_file');
        }
        $manifest = json_decode((string) $lines[0], true);
        // The recorder writes `record_type`; legacy fixtures use `_type`. Accept both so the
        // replayer is the single live consumer of recorder traces AND legacy test fixtures.
        $manifestType = is_array($manifest) ? (string) ($manifest['record_type'] ?? $manifest['_type'] ?? '') : '';
        if (! is_array($manifest) || $manifestType !== 'manifest') {
            throw new RuntimeException('replayer_manifest_missing_or_invalid');
        }
        $rootCommitAtRecord = (string) ($manifest['root_commit'] ?? '');
        // Terminal status is recorded on a SEPARATE `finish` row by the recorder. Walk lines
        // once to surface an aborted-during-record terminal status (legacy fixtures stamp it
        // on the manifest itself; honor either shape).
        $terminal = (string) ($manifest['terminal_status'] ?? '');
        if ($terminal === '') {
            foreach ($lines as $line) {
                $row = json_decode((string) $line, true);
                $rowType = is_array($row) ? (string) ($row['record_type'] ?? $row['_type'] ?? '') : '';
                if ($rowType === 'finish') {
                    $terminal = (string) ($row['terminal_status'] ?? '');
                    break;
                }
            }
        }
        if ($terminal === 'aborted-during-record') {
            throw new RuntimeException('replayer_refuses_aborted_trace:terminal_status=aborted-during-record');
        }

        $divergences = [];
        $totalReplayed = 0;
        $firstDivergedIndex = null;

        for ($i = 1; $i < count($lines); $i++) {
            $entry = json_decode((string) $lines[$i], true);
            if (! is_array($entry)) {
                continue;
            }
            $entryType = (string) ($entry['record_type'] ?? $entry['_type'] ?? '');
            if ($entryType === 'manifest' || $entryType === 'finish') {
                continue;
            }
            // Accept either the legacy `step` type or the recorder's `trace_step` envelope.
            if ($entryType !== '' && $entryType !== 'step' && $entryType !== 'trace_step') {
                continue;
            }
            $stepIndex = (int) ($entry['step_index'] ?? -1);
            if ($fromStep !== null && $stepIndex < $fromStep) {
                continue;
            }
            if ($toStep !== null && $stepIndex > $toStep) {
                continue;
            }

            $action = (string) ($entry['action'] ?? $entry['action_name'] ?? '');
            $input = $entry['input'] ?? null;
            $recordedFp = (string) ($entry['output_fingerprint'] ?? '');
            $recordedBytes = isset($entry['output_b64']) ? (string) base64_decode((string) $entry['output_b64'], true) : '';

            $observed = $actor->perform($stepIndex, $action, $input);
            $observedFp = hash('sha256', $observed);
            $diverged = $observedFp !== $recordedFp;
            // When the recorder didn't include raw output_b64 (the production recorder doesn't,
            // by design — it only stores fingerprints) we cannot compute a byte-diff offset.
            $offset = ($diverged && $recordedBytes !== '') ? $this->firstByteDiffOffset($recordedBytes, $observed) : null;

            $divergences[] = new DivergenceFact($stepIndex, $action, $recordedFp, $observedFp, $diverged, $offset);
            $totalReplayed++;
            if ($diverged && $firstDivergedIndex === null) {
                $firstDivergedIndex = $stepIndex;
            }
        }

        $divergedCount = 0;
        foreach ($divergences as $d) {
            if ($d->diverged) {
                $divergedCount++;
            }
        }

        return new ReplayReport(
            $totalReplayed,
            $divergedCount,
            $firstDivergedIndex,
            $rootCommitAtRecord,
            $this->rootCommitAtReplay,
            $divergences,
        );
    }

    private function firstByteDiffOffset(string $a, string $b): ?int
    {
        $len = min(strlen($a), strlen($b));
        for ($i = 0; $i < $len; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $i;
            }
        }
        if (strlen($a) !== strlen($b)) {
            return $len;
        }

        return null;
    }
}
