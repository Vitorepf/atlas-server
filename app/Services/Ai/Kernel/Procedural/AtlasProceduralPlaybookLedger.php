<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Procedural;

use App\Services\Ai\EngineeringKernel\OutcomeProofGate;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Throwable;

/**
 * ATLAS BUILD #3 SLICE 1 — store for the GENERAL procedural playbook.
 *
 * Reuses the SAME measurement substrate as the repair playbook ledger
 * ({@see \App\Services\Ai\Kernel\Repair\AtlasRepairPlaybookLedger}): on-disk
 * append-only JSONL that survives the DB, keyed by a stable task category, with
 * per-key resolution rate computed from REAL outcomes (never fabricated). The
 * delta is the SHAPE it stores — a task-agnostic {@see ProceduralPlaybook} with
 * the five canonical fields, not a domain-locked repair decision.
 *
 * Events (Slice 1 defines the first; applier/outcome/correction land in 2 & 3):
 *   {"event":"define","task_category":"...","objective":"...","steps":[...],
 *    "postconditions":[...],"forbidden_actions":[...],"prior_corrections":[...],
 *    "at":"ISO-8601"}
 */
final class AtlasProceduralPlaybookLedger
{
    private string $path;

    private OutcomeProofGate $proofGate;

    public function __construct(?string $path = null, ?OutcomeProofGate $proofGate = null)
    {
        $this->path = $path ?? (function_exists('storage_path')
            ? storage_path('atlas/kernel/procedural_playbook.jsonl')
            : sys_get_temp_dir().'/atlas/kernel/procedural_playbook.jsonl');
        $this->proofGate = $proofGate ?? new OutcomeProofGate;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Producer: register (or re-register) a procedural playbook for a task category. */
    public function define(ProceduralPlaybook $playbook): void
    {
        if (trim($playbook->taskCategory) === '') {
            return;
        }
        $this->append(['event' => 'define'] + $playbook->toArray());
    }

    /**
     * Consumer: recover the latest playbook matching a task category (last
     * `define` wins, so re-definitions supersede). Returns null when nothing
     * matches — never a fabricated playbook.
     */
    public function retrieve(string $taskCategory): ?ProceduralPlaybook
    {
        $key = ProceduralPlaybook::normalizeCategory($taskCategory);
        if ($key === '') {
            return null;
        }

        $latest = null;
        foreach ($this->readAll() as $row) {
            if ((string) ($row['event'] ?? '') !== 'define') {
                continue;
            }
            if (ProceduralPlaybook::normalizeCategory((string) ($row['task_category'] ?? '')) !== $key) {
                continue;
            }
            $latest = $row;
        }

        if ($latest === null) {
            return null;
        }

        // Slice 3: fold real-failure prior-corrections into the recovered
        // playbook, so a proven failure CHANGES the next retrieval/injection.
        $playbook = ProceduralPlaybook::fromArray($latest);
        $corrections = $this->correctionsFor($key);

        return $corrections === [] ? $playbook : $playbook->withPriorCorrections($corrections);
    }

    /**
     * Producer (Slice 2): a task STARTED following a matched playbook — one
     * attempt. Correlated to its outcome by a caller-owned application id.
     */
    public function recordApplied(string $applicationId, string $taskCategory): void
    {
        if (trim($applicationId) === '' || trim($taskCategory) === '') {
            return;
        }
        $this->append([
            'event' => 'applied',
            'application_id' => $applicationId,
            'task_category' => $taskCategory,
        ]);
    }

    /**
     * Consumer (Slice 2): the REAL outcome of following the playbook. Routed
     * through the SAME {@see OutcomeProofGate} as the Engineering Kernel
     * Learning Loop, so a fake-green can never earn a success credit. Only a
     * proven_real success (claims success AND real execution evidence passes)
     * increments the measured rate; a fake_green is recorded but suppressed.
     * No-op unless there is an OPEN application (last event = applied).
     *
     * @param  array<string,mixed>  $execution  execution evidence (commands, tests_run, assertions_executed, ...)
     * @return array{proven_real:bool,fake_green:bool,credited:bool,reason:string}
     */
    public function recordOutcome(string $applicationId, string $status, array $execution = []): array
    {
        $miss = ['proven_real' => false, 'fake_green' => false, 'credited' => false, 'reason' => 'no_open_application'];
        if (trim($applicationId) === '') {
            return $miss;
        }
        $open = $this->openApplicationFor($applicationId);
        if ($open === null) {
            return $miss;
        }

        $verdict = $this->proofGate->assess($status, $execution);
        $claimsSuccess = in_array(mb_strtolower(trim($status)), ['success', 'succeeded', 'passed', 'ready'], true);
        $credited = $claimsSuccess && $verdict['proven_real'] === true;

        $this->append([
            'event' => 'outcome',
            'application_id' => $applicationId,
            'task_category' => (string) ($open['task_category'] ?? ''),
            'status' => $status,
            'claims_success' => $claimsSuccess,
            'proven_real' => $verdict['proven_real'],
            'fake_green' => $verdict['fake_green'],
            'credited' => $credited,
            'reason' => $verdict['reason'],
        ]);

        return ['proven_real' => $verdict['proven_real'], 'fake_green' => $verdict['fake_green'], 'credited' => $credited, 'reason' => $verdict['reason']];
    }

    /**
     * Producer/consumer (Slice 3): a prior-correction derived from a REAL,
     * proven failure of following the playbook. This is the delta over a static
     * checklist — a proven failure CHANGES the next retrieval/injection (the
     * correction is folded back by {@see retrieve()}).
     *
     * Fabrication-proof: writes ONLY when the application has a recorded outcome
     * that the OutcomeProofGate accepted as a truthful non-success (proven_real,
     * not credited, no success claim). No real failure on record → no correction.
     */
    public function recordFailureCorrection(string $applicationId, string $correction): bool
    {
        $correction = trim($correction);
        if (trim($applicationId) === '' || $correction === '') {
            return false;
        }

        $failure = $this->realFailureOutcomeFor($applicationId);
        if ($failure === null) {
            return false;
        }

        $this->append([
            'event' => 'correction',
            'application_id' => $applicationId,
            'task_category' => (string) ($failure['task_category'] ?? ''),
            'correction' => $correction,
            'derived_from' => 'real_failure_outcome',
        ]);

        return true;
    }

    /**
     * Per-category MEASURED follow rate — the same honest contract as the repair
     * ledger: `unmeasured` (never a fabricated rate) until at least one attempt.
     * A credited success requires a proven_real outcome; fake_greens are counted
     * separately as suppressed, never in the numerator.
     *
     * @return array{schema_version:string,task_category:string,status:string,attempts:int,successes:int,success_rate:float,fake_green_suppressed:int}
     */
    public function rateFor(string $taskCategory): array
    {
        $key = ProceduralPlaybook::normalizeCategory($taskCategory);
        $attempts = 0;
        $successes = 0;
        $fakeGreen = 0;

        foreach ($this->readAll() as $row) {
            if (ProceduralPlaybook::normalizeCategory((string) ($row['task_category'] ?? '')) !== $key) {
                continue;
            }
            $event = (string) ($row['event'] ?? '');
            if ($event === 'applied') {
                $attempts++;
            } elseif ($event === 'outcome') {
                if (($row['credited'] ?? false) === true) {
                    $successes++;
                }
                if (($row['fake_green'] ?? false) === true) {
                    $fakeGreen++;
                }
            }
        }

        return [
            'schema_version' => 'atlas.kernel.procedural_playbook.v1',
            'task_category' => $taskCategory,
            'status' => $attempts > 0 ? 'measured' : 'unmeasured',
            'attempts' => $attempts,
            'successes' => $successes,
            'success_rate' => $attempts > 0 ? round($successes / $attempts, 3) : 0.0,
            'fake_green_suppressed' => $fakeGreen,
        ];
    }

    /**
     * Cadence read model (Slice 2): every DEFINED playbook with its measured
     * follow signal in ONE single pass — injections (attempts), success rate,
     * corrections fired, fake-greens suppressed. Honest zero: a defined-but-
     * never-applied playbook reports attempts=0/unmeasured; a stray outcome with
     * no define never manufactures a row.
     *
     * @return list<array{task_category:string,status:string,attempts:int,successes:int,success_rate:float,fake_green_suppressed:int,corrections:int}>
     */
    public function cadence(): array
    {
        /** @var array<string,array{category:string,defined:bool,attempts:int,successes:int,fake_green:int,corrections:int}> $agg */
        $agg = [];
        foreach ($this->readAll() as $row) {
            $rawCategory = (string) ($row['task_category'] ?? '');
            $key = ProceduralPlaybook::normalizeCategory($rawCategory);
            if ($key === '') {
                continue;
            }
            $agg[$key] ??= ['category' => $rawCategory, 'defined' => false, 'attempts' => 0, 'successes' => 0, 'fake_green' => 0, 'corrections' => 0];

            switch ((string) ($row['event'] ?? '')) {
                case 'define':
                    $agg[$key]['defined'] = true;
                    $agg[$key]['category'] = $rawCategory;
                    break;
                case 'applied':
                    $agg[$key]['attempts']++;
                    break;
                case 'outcome':
                    if (($row['credited'] ?? false) === true) {
                        $agg[$key]['successes']++;
                    }
                    if (($row['fake_green'] ?? false) === true) {
                        $agg[$key]['fake_green']++;
                    }
                    break;
                case 'correction':
                    $agg[$key]['corrections']++;
                    break;
            }
        }

        $report = [];
        foreach ($agg as $a) {
            if (! $a['defined']) {
                continue;
            }
            $report[] = [
                'task_category' => $a['category'],
                'status' => $a['attempts'] > 0 ? 'measured' : 'unmeasured',
                'attempts' => $a['attempts'],
                'successes' => $a['successes'],
                'success_rate' => $a['attempts'] > 0 ? round($a['successes'] / $a['attempts'], 3) : 0.0,
                'fake_green_suppressed' => $a['fake_green'],
                'corrections' => $a['corrections'],
            ];
        }
        usort($report, static fn (array $x, array $y): int => strcmp($x['task_category'], $y['task_category']));

        return $report;
    }

    /**
     * @return array<string,mixed>|null the open (un-outcomed) application row, or
     *                                  null if none / already outcomed
     */
    private function openApplicationFor(string $applicationId): ?array
    {
        $last = null;
        foreach ($this->readAll() as $row) {
            if ((string) ($row['application_id'] ?? '') !== $applicationId) {
                continue;
            }
            $last = $row;
        }

        return ($last !== null && (string) ($last['event'] ?? '') === 'applied') ? $last : null;
    }

    /**
     * The application's outcome row IF it is a proven, truthful failure — the
     * only thing that may seed a prior-correction. At most one outcome exists
     * per application (recordOutcome no-ops after the first).
     *
     * @return array<string,mixed>|null
     */
    private function realFailureOutcomeFor(string $applicationId): ?array
    {
        foreach ($this->readAll() as $row) {
            if ((string) ($row['application_id'] ?? '') !== $applicationId) {
                continue;
            }
            if ((string) ($row['event'] ?? '') !== 'outcome') {
                continue;
            }
            if (($row['proven_real'] ?? false) === true
                && ($row['credited'] ?? false) === false
                && ($row['claims_success'] ?? false) === false) {
                return $row;
            }
        }

        return null;
    }

    /**
     * All prior-corrections recorded for a task category, in order.
     *
     * @return list<string>
     */
    private function correctionsFor(string $normalizedKey): array
    {
        $corrections = [];
        foreach ($this->readAll() as $row) {
            if ((string) ($row['event'] ?? '') !== 'correction') {
                continue;
            }
            if (ProceduralPlaybook::normalizeCategory((string) ($row['task_category'] ?? '')) !== $normalizedKey) {
                continue;
            }
            $correction = trim((string) ($row['correction'] ?? ''));
            if ($correction !== '') {
                $corrections[] = $correction;
            }
        }

        return $corrections;
    }

    /** @return list<array<string,mixed>> */
    private function readAll(): array
    {
        try {
            return AppendOnlyJsonlStore::read($this->path);
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $payload */
    private function append(array $payload): void
    {
        try {
            $payload['at'] = now()->toIso8601String();
            AppendOnlyJsonlStore::append($this->path, $payload);
        } catch (Throwable) {
            // fail-open: the corpus never blocks a work flow.
        }
    }
}
