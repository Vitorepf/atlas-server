<?php

namespace App\Services\Ai\Rivals\Core;

use App\Services\Ai\Rivals\Support\AtomicWriter;
use App\Services\Ai\Rivals\Support\EventStream;
use App\Services\Ai\Rivals\Support\RunPaths;
use RuntimeException;

/** Authoritative, crash-visible lifecycle for one Rivals run. */
final class RunStateMachine
{
    public const PLANNED = 'planned';

    public const PREFLIGHTED = 'preflighted';

    public const NATIVE_RUNNING = 'native_running';

    public const RESULTS_IMPORTED = 'results_imported';

    public const EVIDENCE_BUILT = 'evidence_built';

    public const VERIFIED = 'verified';

    public const ADJUDICATED = 'adjudicated';

    public const REPORTED = 'reported';

    public const BUNDLED = 'bundled';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const SUPERSEDED = 'superseded';

    /** @var list<string> */
    private const ORDER = [
        self::PLANNED,
        self::PREFLIGHTED,
        self::NATIVE_RUNNING,
        self::RESULTS_IMPORTED,
        self::EVIDENCE_BUILT,
        self::VERIFIED,
        self::ADJUDICATED,
        self::REPORTED,
        self::BUNDLED,
    ];

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::PLANNED => [self::PREFLIGHTED, self::NATIVE_RUNNING, self::FAILED, self::CANCELLED],
        self::PREFLIGHTED => [self::NATIVE_RUNNING, self::FAILED, self::CANCELLED],
        self::NATIVE_RUNNING => [self::RESULTS_IMPORTED, self::FAILED, self::CANCELLED],
        self::RESULTS_IMPORTED => [self::EVIDENCE_BUILT, self::FAILED, self::CANCELLED],
        self::EVIDENCE_BUILT => [self::VERIFIED, self::FAILED, self::CANCELLED],
        self::VERIFIED => [self::ADJUDICATED, self::FAILED, self::CANCELLED],
        self::ADJUDICATED => [self::REPORTED, self::SUPERSEDED, self::FAILED],
        self::REPORTED => [self::BUNDLED, self::SUPERSEDED],
        self::BUNDLED => [self::SUPERSEDED, self::FAILED],
        self::FAILED => [self::SUPERSEDED],
        self::CANCELLED => [self::SUPERSEDED],
        self::SUPERSEDED => [],
    ];

    public function path(string $runId): string
    {
        return RunPaths::runDir($runId).'/state.json';
    }

    public function current(string $runId): ?array
    {
        $path = $this->path($runId);
        if (! is_file($path)) {
            return null;
        }

        return json_decode(file_get_contents($path), true) ?: null;
    }

    public function mark(string $runId, string $state, array $meta = []): array
    {
        if (! array_key_exists($state, self::TRANSITIONS)) {
            throw new RuntimeException("rivals_unknown_run_state:{$state}");
        }
        if ($state === self::NATIVE_RUNNING && is_file(RunPaths::planPath($runId))) {
            $plan = RunPlan::load($runId);
            if (($plan->data['suite_id'] ?? null) !== 'local_fake') {
                try {
                    FrozenUnitManifest::load($runId);
                } catch (\Throwable $e) {
                    throw new RuntimeException('rivals_unit_freeze_required:'.($e->getMessage() ?: 'manifest_missing'), 0, $e);
                }
            }
        }
        $current = $this->current($runId);
        $from = (string) ($current['state'] ?? '');
        if ($current === null && $state !== self::PLANNED) {
            throw new RuntimeException('rivals_run_state_required:planned');
        }
        if ($current !== null && $from === $state) {
            return $current;
        }
        if ($current !== null && ! in_array($state, self::TRANSITIONS[$from] ?? [], true)) {
            throw new RuntimeException("rivals_run_state_transition_forbidden:{$from}->{$state}");
        }
        $at = now()->toIso8601String();
        $payload = [
            'schema_version' => 'atlas.rivals2.run_state.v2',
            'run_id' => $runId,
            'state' => $state,
            'revision' => (int) ($current['revision'] ?? 1),
            'updated_at' => $at,
            'history' => array_values(array_merge($current['history'] ?? [], [[
                'from' => $from !== '' ? $from : null,
                'state' => $state,
                'at' => $at,
                'meta' => $meta,
            ]])),
        ];
        RunPaths::ensureDir(RunPaths::runDir($runId));
        AtomicWriter::write(
            $this->path($runId),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        EventStream::append($runId, 'run_state_changed', [
            'from' => $from !== '' ? $from : null,
            'to' => $state,
            'revision' => $payload['revision'],
            'meta' => $meta,
        ]);

        return $payload;
    }

    /**
     * Explicit replace-import rewind: keep plan, drop derived state back to planned.
     * Only allowed with operator --replace-import (never silent).
     */
    public function resetForReplaceImport(string $runId, array $meta = []): array
    {
        $current = $this->current($runId);
        if ($current === null) {
            throw new RuntimeException('rivals_run_state_required:planned');
        }
        $at = now()->toIso8601String();
        $revision = ((int) ($current['revision'] ?? 1)) + 1;
        $payload = [
            'schema_version' => 'atlas.rivals2.run_state.v2',
            'run_id' => $runId,
            'state' => self::PLANNED,
            'revision' => $revision,
            'updated_at' => $at,
            'history' => array_values(array_merge($current['history'] ?? [], [
                [
                    'from' => $current['state'] ?? null,
                    'state' => self::SUPERSEDED,
                    'at' => $at,
                    'meta' => ['replace_import' => true] + $meta,
                ],
                [
                    'from' => self::SUPERSEDED,
                    'state' => self::PLANNED,
                    'at' => $at,
                    'meta' => ['revision' => $revision],
                ],
            ])),
        ];
        RunPaths::ensureDir(RunPaths::runDir($runId));
        AtomicWriter::write(
            $this->path($runId),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        EventStream::append($runId, 'run_revision_started', [
            'revision' => $revision,
            'reason' => 'replace_import',
        ]);

        return $payload;
    }

    public function cancel(string $runId, string $reason): array
    {
        return $this->mark($runId, self::CANCELLED, ['reason' => $reason]);
    }

    public function fail(string $runId, string $reason): array
    {
        return $this->mark($runId, self::FAILED, ['reason' => $reason]);
    }

    public function assertAtLeast(string $runId, string $required): void
    {
        $current = $this->current($runId);
        $state = (string) ($current['state'] ?? '');
        if ($current === null
            || ! in_array($state, self::ORDER, true)
            || $this->rank($state) < $this->rank($required)) {
            throw new RuntimeException("rivals_run_state_required:{$required}");
        }
    }

    public function resumeAction(string $runId): string
    {
        $state = (string) (($this->current($runId)['state'] ?? ''));

        return match ($state) {
            self::PLANNED, self::PREFLIGHTED, self::NATIVE_RUNNING => 'native_execution',
            self::RESULTS_IMPORTED => 'build_evidence',
            self::EVIDENCE_BUILT => 'verify',
            self::VERIFIED => 'adjudicate',
            self::ADJUDICATED => 'report',
            self::REPORTED => 'bundle',
            self::BUNDLED => 'complete',
            self::FAILED, self::CANCELLED, self::SUPERSEDED => 'operator_intervention',
            default => 'plan',
        };
    }

    private function rank(string $state): int
    {
        $idx = array_search($state, self::ORDER, true);

        return $idx === false ? -1 : $idx;
    }
}
