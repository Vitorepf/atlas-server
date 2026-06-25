<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger;

/**
 * FACT-only inspector for AAEL step pre/post state.
 *
 * Pétreo R1/R2: no scores, no grades, no judgements, no aggregated scalars.
 * Snapshot body is deterministic (sorted keys, no embedded wall-clock).
 */
final class AtlasAaelExecutionStepStateInspector
{
    public const ALLOWED_BODY_KEYS = [
        'step_index',
        'step_kind',
        'allowed_files',
        'env',
        'inputs_digest',
        'outputs_digest',
        'files_touched',
        'diff_counts',
    ];

    public const STEP_KINDS = ['read', 'write', 'llm_call', 'tool_call', 'merge', 'gate'];

    /**
     * @param  array{kind:string, allowed_files?:array<int,string>, inputs?:mixed} $step
     * @param  array{cwd:string, git_head:string, dirty:bool} $env
     * @param  array<int,string> $filesTouched absolute paths to read for sha256
     */
    public function capturePre(
        string $runId,
        int $stepIndex,
        array $step,
        array $env,
        array $filesTouched,
        string $wallClockIso,
    ): InspectorSnapshot {
        return $this->build(
            $stepIndex,
            $step,
            $env,
            $filesTouched,
            ['lines_added' => 0, 'lines_removed' => 0, 'files_changed' => 0],
            $step['inputs'] ?? null,
            null,
            $wallClockIso,
        );
    }

    /**
     * @param  array{kind:string, allowed_files?:array<int,string>, inputs?:mixed} $step
     * @param  array{cwd:string, git_head:string, dirty:bool} $env
     * @param  array<int,string> $filesTouched
     * @param  array{lines_added:int, lines_removed:int, files_changed:int} $diffCounts
     * @param  mixed $result
     */
    public function capturePost(
        string $runId,
        int $stepIndex,
        array $step,
        array $env,
        array $filesTouched,
        array $diffCounts,
        $result,
        string $wallClockIso,
    ): InspectorSnapshot {
        return $this->build(
            $stepIndex,
            $step,
            $env,
            $filesTouched,
            $diffCounts,
            $step['inputs'] ?? null,
            $result,
            $wallClockIso,
        );
    }

    /**
     * @param  array{kind:string, allowed_files?:array<int,string>} $step
     * @param  array{cwd:string, git_head:string, dirty:bool} $env
     * @param  array<int,string> $filesTouched
     * @param  array{lines_added:int, lines_removed:int, files_changed:int} $diffCounts
     */
    private function build(
        int $stepIndex,
        array $step,
        array $env,
        array $filesTouched,
        array $diffCounts,
        mixed $inputs,
        mixed $outputs,
        string $wallClockIso,
    ): InspectorSnapshot {
        $kind = (string) ($step['kind'] ?? '');
        if (! in_array($kind, self::STEP_KINDS, true)) {
            throw new \InvalidArgumentException('inspector_unknown_step_kind:'.$kind);
        }

        $allowed = array_values(array_map('strval', (array) ($step['allowed_files'] ?? [])));
        sort($allowed);

        $perFile = [];
        foreach ($filesTouched as $abs) {
            $perFile[] = [
                'path' => $abs,
                'sha256' => is_file($abs) ? (string) hash_file('sha256', $abs) : null,
            ];
        }
        usort($perFile, static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));

        $body = [
            'allowed_files' => $allowed,
            'diff_counts' => [
                'files_changed' => (int) $diffCounts['files_changed'],
                'lines_added' => (int) $diffCounts['lines_added'],
                'lines_removed' => (int) $diffCounts['lines_removed'],
            ],
            'env' => [
                'cwd' => (string) $env['cwd'],
                'dirty' => (bool) $env['dirty'],
                'git_head' => (string) $env['git_head'],
            ],
            'files_touched' => $perFile,
            'inputs_digest' => $this->digest($inputs),
            'outputs_digest' => $outputs === null ? null : $this->digest($outputs),
            'step_index' => $stepIndex,
            'step_kind' => $kind,
        ];

        $body = $this->ksortDeep($body);

        return new InspectorSnapshot($body, $wallClockIso);
    }

    private function digest(mixed $value): string
    {
        $canon = $this->ksortDeep($value);

        return hash('sha256', (string) json_encode($canon, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function ksortDeep(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $isList = array_is_list($v);
        $out = [];
        foreach ($v as $k => $vv) {
            $out[$k] = $this->ksortDeep($vv);
        }
        if (! $isList) {
            ksort($out);
        }

        return $out;
    }
}
