<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * AP-810 / LHL-03 — Flight Recorder v0 (AP-808/809).
 *
 * A black box for the long-horizon loop. For every cycle it BINDS the full
 * causal chain of references so the operator can later ask, of any commit,
 * block or provider call: "why does this exist?". It is read-only /
 * deterministic / input-seam driven. It NEVER invokes a provider, NEVER runs
 * the loop, NEVER merges, NEVER deletes a branch/worktree, NEVER mutates code.
 *
 * record(): folds one cycle's references into a canonical flight record and
 * appends it to an append-only JSONL ledger (idempotent on the record hash).
 * A *counted* cycle (one that claims real productivity: merged/implemented)
 * MUST carry the full evidence chain — if any required ref is missing the
 * record is flagged `incomplete` and NEVER reported as recorded-and-counted.
 * A *blocked* cycle is not productivity: it is recorded as `recorded` but with
 * counted=false, keeping its blocker + retry policy.
 *
 * explain(): reconstructs the chain + the reason a given commit/block/provider
 * call exists, reading from the stored ledger (or an injected ledger seam).
 *
 * Honesty rules (operator does not accept false claims):
 *   - blocked is NEVER counted as success;
 *   - a sandbox commit is NOT a merge and a plan-only Forge path is NOT
 *     implementation — neither may be counted as productive delivery;
 *   - starvation recovery / filler is NEVER counted as productivity;
 *   - a counted cycle missing any evidence ref is `incomplete`, never recorded
 *     as a clean counted cycle.
 *
 * Contract: AP-808/809; AP-810 build contract slice LHL-03.
 * Reference shape: AreaFocusCycleRecorderService.php, AreaFocusEvidencePackService.php.
 */
final class LoopFlightRecorderService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_flight_recorder.v1';

    public const STATUS_RECORDED = 'recorded';

    public const STATUS_INCOMPLETE = 'incomplete';

    /**
     * Outcomes that CLAIM real productivity. A cycle with one of these outcomes
     * is "counted" and therefore must carry the full evidence chain.
     */
    private const COUNTED_OUTCOMES = ['merged', 'implemented', 'completed'];

    /**
     * Outcomes that explicitly DO NOT count as productivity even though work
     * happened. Encoded so the recorder never lets them masquerade as merged.
     */
    private const NON_PRODUCTIVE_OUTCOMES = [
        'blocked',
        'sandbox_commit',
        'plan_only',
        'recovery',
        'starvation_recovery',
        'filler',
        'skipped',
        'planned',
        'deferred',
    ];

    /**
     * The full causal chain a counted cycle must bind (AP-808 black box). If any
     * of these refs is empty for a counted cycle the record is `incomplete`.
     */
    private const REQUIRED_EVIDENCE_REFS = [
        'preflight_ref',
        'candidate_ref',
        'provider_ref',
        'diff_summary',
        'validation_commands',
        'judge_verdict',
        'merge_governor_result',
        'post_cycle_audit_ref',
        'next_state',
    ];

    private ?string $storageRootOverride = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function storageDir(): string
    {
        if ($this->storageRootOverride !== null) {
            return $this->storageRootOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/long_horizon_loop')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/long_horizon_loop';
    }

    /**
     * Path to the append-only flight ledger for an (area, focus) pair.
     */
    public function recordPath(string $area, string $focus): string
    {
        return $this->storageDir()
            .DIRECTORY_SEPARATOR.AreaFocusSlugNormalizer::lowerUnderscoreToken($area, 'unknown', true, false)
            .DIRECTORY_SEPARATOR.AreaFocusSlugNormalizer::lowerUnderscoreToken($focus, 'unknown', true, false)
            .DIRECTORY_SEPARATOR.'flight_recorder.jsonl';
    }

    /**
     * Bind one cycle's references into a canonical flight record and append it
     * to the JSONL ledger. Every key is optional; an empty cycle records as an
     * `incomplete` blocked cycle and never crashes.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function record(array $input = []): array
    {
        $input = AreaFocusLoopPayloadNormalizer::mergeFixture($input);

        $area = $this->str($input['area'] ?? 'agentic_engineering_os', 'agentic_engineering_os');
        $focus = $this->str($input['focus'] ?? 'dev_forge', 'dev_forge');
        $runId = trim((string) ($input['run_id'] ?? ''));
        $cycleIndex = (int) ($input['cycle_index'] ?? 0);

        $cycle = is_array($input['cycle'] ?? null) ? $input['cycle'] : $input;

        $outcome = $this->normalizeOutcome((string) ($cycle['outcome'] ?? ($cycle['cycle_outcome'] ?? '')));
        $blockers = $this->stringList($cycle['blockers'] ?? ($cycle['blocker'] ?? []));
        $blockerReason = trim((string) ($cycle['blocker_reason'] ?? ($cycle['blocker'] ?? '')));

        // The bound causal chain — every external fact comes through the seam.
        $chain = [
            'preflight_ref' => $this->refString($cycle['preflight_ref'] ?? null),
            'candidate_ref' => $this->candidateRef($cycle),
            'packet_ref' => $this->refString($cycle['packet_ref'] ?? ($cycle['packet_id'] ?? null)),
            'provider_ref' => $this->refString($cycle['provider_ref'] ?? ($cycle['session_ref'] ?? null)),
            'diff_summary' => $this->diffSummary($cycle),
            'validation_commands' => $this->stringList($cycle['validation_commands'] ?? ($cycle['validations'] ?? [])),
            'judge_verdict' => $this->refString($cycle['judge_verdict'] ?? null),
            'merge_governor_result' => $this->mergeGovernorResult($cycle),
            'post_cycle_audit_ref' => $this->refString($cycle['post_cycle_audit_ref'] ?? ($cycle['audit_ref'] ?? null)),
            'cleanup_ref' => $this->refString($cycle['cleanup_ref'] ?? ($cycle['cleanup'] ?? null)),
            'next_state' => $this->str($cycle['next_state'] ?? '', ''),
            'resource_snapshot' => is_array($cycle['resource_snapshot'] ?? null) ? $cycle['resource_snapshot'] : [],
            'retry_policy' => $this->retryPolicy($cycle),
        ];

        $isCountedClaim = in_array($outcome, self::COUNTED_OUTCOMES, true)
            || (bool) ($cycle['counted'] ?? false);
        $isNonProductive = in_array($outcome, self::NON_PRODUCTIVE_OUTCOMES, true);
        $isBlocked = $outcome === 'blocked' || ($outcome === '' && ($blockers !== [] || $blockerReason !== ''));

        $missingRefs = [];
        if ($isCountedClaim) {
            foreach (self::REQUIRED_EVIDENCE_REFS as $ref) {
                if ($this->refEmpty($chain[$ref] ?? null)) {
                    $missingRefs[] = $ref;
                }
            }
        }

        // HONESTY: a counted claim is only honored when (a) it is a productive
        // outcome — sandbox commit / plan-only / recovery NEVER count — and
        // (b) the full evidence chain is present.
        $counted = $isCountedClaim && ! $isNonProductive && $missingRefs === [];

        $reportBlockers = $blockers;
        if ($isCountedClaim && $isNonProductive) {
            $reportBlockers[] = 'non_productive_outcome_claimed_as_counted:'.$outcome;
        }
        if ($missingRefs !== []) {
            $reportBlockers[] = 'counted_cycle_missing_evidence_refs:'.implode(',', $missingRefs);
        }

        $status = $missingRefs !== [] ? self::STATUS_INCOMPLETE : self::STATUS_RECORDED;

        // Stable identity over the cycle coordinates + the bound chain.
        $recordId = 'lfr_'.substr(MissionCanonicalHash::sha256([
            $area,
            $focus,
            $runId,
            $cycleIndex,
            $outcome,
            $chain['candidate_ref'],
            $chain['provider_ref'] ?? '',
            $chain['diff_summary']['hash'] ?? '',
            $chain['merge_governor_result']['merged'] ?? false,
        ]), 0, 16);

        $core = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-808',
            'slice_id' => 'LHL-03',
            'status' => $status,
            'flight_record_id' => $recordId,
            'run_id' => $runId,
            'cycle_index' => $cycleIndex,
            'area' => $area,
            'focus' => $focus,
            'outcome' => $outcome !== '' ? $outcome : ($isBlocked ? 'blocked' : 'unknown'),
            'counted' => $counted,
            'is_productive_outcome' => ! $isNonProductive && $outcome !== '',
            'chain' => $chain,
            'blockers' => AreaFocusStringListNormalizer::uniqueStringValues($reportBlockers),
            'missing_evidence_refs' => $missingRefs,
            'blocker_reason' => $isBlocked ? ($blockerReason !== '' ? $blockerReason : 'cycle_blocked') : null,
            'retry_policy' => $isBlocked ? $chain['retry_policy'] : null,
            'next_action' => $this->nextAction($status, $counted, $isBlocked),
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'blocked_never_dressed_as_ready' => true,
                'blocked_never_counted' => true,
                'sandbox_commit_is_not_merge' => true,
                'plan_only_is_not_implementation' => true,
            ],
        ];
        $core['record_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->hashablePayload($core));

        $persist = (bool) ($input['persist'] ?? true);
        $persisted = false;
        $recordPath = $this->recordPath($area, $focus);
        if ($persist) {
            // Idempotent: an already-recorded flight record is not appended twice.
            if ($this->findInFile($recordPath, $recordId) === null) {
                $stored = $core;
                $stored['recorded_at'] = AreaFocusUtcClock::atomNow();
                AreaFocusAppendOnlyJsonlRecorder::append($recordPath, $stored);
                $persisted = true;
            } else {
                $persisted = true;
            }
        }

        $core['persisted'] = $persisted;
        $core['record_path'] = $recordPath;
        $core['checked_at'] = AreaFocusUtcClock::atomNow();

        return $core;
    }

    /**
     * Answer "why does this commit/block/provider call exist?" by reconstructing
     * the bound chain from the stored ledger (or an injected `ledger` seam).
     *
     * Lookup keys (any one): `flight_record_id`, `commit` (matches a diff commit
     * sha), `provider_ref`, or (`run_id` + `cycle_index`).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function explain(array $input = []): array
    {
        $input = AreaFocusLoopPayloadNormalizer::mergeFixture($input);

        $area = $this->str($input['area'] ?? 'agentic_engineering_os', 'agentic_engineering_os');
        $focus = $this->str($input['focus'] ?? 'dev_forge', 'dev_forge');

        $records = is_array($input['ledger'] ?? null)
            ? AreaFocusLoopPayloadNormalizer::listOfArrays($input['ledger'])
            : $this->readRecords($this->recordPath($area, $focus));

        $recordId = trim((string) ($input['flight_record_id'] ?? ''));
        $commit = trim((string) ($input['commit'] ?? ''));
        $providerRef = trim((string) ($input['provider_ref'] ?? ''));
        $runId = trim((string) ($input['run_id'] ?? ''));
        $cycleIndex = array_key_exists('cycle_index', $input) ? (int) $input['cycle_index'] : null;

        $found = null;
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            if ($recordId !== '' && (string) ($record['flight_record_id'] ?? '') === $recordId) {
                $found = $record;
                break;
            }
            if ($commit !== '' && $this->recordMatchesCommit($record, $commit)) {
                $found = $record;
                break;
            }
            if ($providerRef !== '' && (string) (($record['chain']['provider_ref'] ?? '')) === $providerRef) {
                $found = $record;
                break;
            }
            if ($runId !== '' && $cycleIndex !== null
                && (string) ($record['run_id'] ?? '') === $runId
                && (int) ($record['cycle_index'] ?? -1) === $cycleIndex) {
                $found = $record;
                break;
            }
        }

        if ($found === null) {
            $payload = [
                'schema_version' => self::REPORT_SCHEMA,
                'ap_contract' => 'AP-808',
                'slice_id' => 'LHL-03',
                'status' => self::STATUS_INCOMPLETE,
                'found' => false,
                'area' => $area,
                'focus' => $focus,
                'query' => $this->explainQuery($recordId, $commit, $providerRef, $runId, $cycleIndex),
                'reason' => 'no_flight_record_found_for_query',
                'chain' => [],
                'blockers' => ['flight_record_not_found'],
                'checked_at' => AreaFocusUtcClock::atomNow(),
                'claim_policy' => [
                    'read_only' => true,
                    'runs_provider' => false,
                    'runs_loop' => false,
                    'runs_merge' => false,
                    'deletes_branches' => false,
                ],
            ];
            $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->hashablePayload($payload));

            return $payload;
        }

        $chain = is_array($found['chain'] ?? null) ? $found['chain'] : [];

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-808',
            'slice_id' => 'LHL-03',
            'status' => self::STATUS_RECORDED,
            'found' => true,
            'area' => $area,
            'focus' => $focus,
            'query' => $this->explainQuery($recordId, $commit, $providerRef, $runId, $cycleIndex),
            'flight_record_id' => (string) ($found['flight_record_id'] ?? ''),
            'run_id' => (string) ($found['run_id'] ?? ''),
            'cycle_index' => (int) ($found['cycle_index'] ?? 0),
            'outcome' => (string) ($found['outcome'] ?? 'unknown'),
            'counted' => (bool) ($found['counted'] ?? false),
            'reason' => $this->reasonFor($found),
            'chain' => $chain,
            'blocker_reason' => $found['blocker_reason'] ?? null,
            'retry_policy' => $found['retry_policy'] ?? null,
            'blockers' => $this->stringList($found['blockers'] ?? []),
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
            ],
        ];
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->hashablePayload($payload));

        return $payload;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Human-grade reason a record exists, derived only from stored facts.
     *
     * @param  array<string,mixed>  $record
     */
    private function reasonFor(array $record): string
    {
        $outcome = (string) ($record['outcome'] ?? 'unknown');
        $chain = is_array($record['chain'] ?? null) ? $record['chain'] : [];
        $candidate = (string) ($chain['candidate_ref'] ?? 'unknown_candidate');

        if ((bool) ($record['counted'] ?? false)) {
            $verdict = (string) ($chain['judge_verdict'] ?? 'verdict_unknown');

            return "counted cycle for {$candidate}: {$outcome} (judge={$verdict})";
        }
        if ($outcome === 'blocked' || ($record['blocker_reason'] ?? null) !== null) {
            $reason = (string) ($record['blocker_reason'] ?? 'cycle_blocked');

            return "blocked cycle for {$candidate}: {$reason}";
        }
        if (in_array($outcome, self::NON_PRODUCTIVE_OUTCOMES, true)) {
            return "non-productive cycle for {$candidate}: {$outcome} (not counted as delivery)";
        }

        return "cycle for {$candidate}: {$outcome}";
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function recordMatchesCommit(array $record, string $commit): bool
    {
        $chain = is_array($record['chain'] ?? null) ? $record['chain'] : [];
        $diff = is_array($chain['diff_summary'] ?? null) ? $chain['diff_summary'] : [];
        $recordCommit = trim((string) ($diff['commit'] ?? ''));
        if ($recordCommit !== '' && ($recordCommit === $commit || str_starts_with($recordCommit, $commit) || str_starts_with($commit, $recordCommit))) {
            return true;
        }
        foreach ($this->stringList($diff['commits'] ?? []) as $c) {
            if ($c === $commit || str_starts_with($c, $commit) || str_starts_with($commit, $c)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function candidateRef(array $cycle): string
    {
        $candidate = is_array($cycle['candidate'] ?? null) ? $cycle['candidate'] : [];
        $ref = trim((string) ($cycle['candidate_ref'] ?? ''));
        if ($ref !== '') {
            return $ref;
        }

        return trim((string) ($candidate['finding_id'] ?? ($cycle['finding_id'] ?? '')));
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function diffSummary(array $cycle): array
    {
        $diff = is_array($cycle['diff_summary'] ?? null) ? $cycle['diff_summary'] : [];
        if ($diff === [] && is_array($cycle['diff'] ?? null)) {
            $diff = $cycle['diff'];
        }
        if ($diff === []) {
            return [];
        }

        $files = $this->stringList($diff['files'] ?? []);
        $summary = [
            'files' => $files,
            'files_changed' => (int) ($diff['files_changed'] ?? count($files)),
            'insertions' => (int) ($diff['insertions'] ?? 0),
            'deletions' => (int) ($diff['deletions'] ?? 0),
            'commit' => trim((string) ($diff['commit'] ?? '')),
            'commits' => $this->stringList($diff['commits'] ?? []),
            'sandbox_only' => (bool) ($diff['sandbox_only'] ?? false),
        ];
        $summary['hash'] = MissionCanonicalHash::sha256([
            $summary['files'],
            $summary['files_changed'],
            $summary['insertions'],
            $summary['deletions'],
            $summary['commit'],
            $summary['commits'],
        ]);

        return $summary;
    }

    /**
     * Normalize the merge governor result. A sandbox commit is NOT a merge.
     *
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function mergeGovernorResult(array $cycle): array
    {
        $mg = is_array($cycle['merge_governor_result'] ?? null) ? $cycle['merge_governor_result'] : [];
        if ($mg === [] && is_array($cycle['merge'] ?? null)) {
            $mg = $cycle['merge'];
        }
        if ($mg === []) {
            return [];
        }

        $sandboxOnly = (bool) ($mg['sandbox_only'] ?? false) || (bool) ($mg['sandbox_commit'] ?? false);
        // A sandbox commit can never be reported as a real merge.
        $merged = (bool) ($mg['merged'] ?? false) && ! $sandboxOnly;

        return [
            'merged' => $merged,
            'merge_target' => trim((string) ($mg['merge_target'] ?? '')),
            'merge_commit' => trim((string) ($mg['merge_commit'] ?? '')),
            'sandbox_only' => $sandboxOnly,
            'result' => trim((string) ($mg['result'] ?? '')),
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array<string,mixed>
     */
    private function retryPolicy(array $cycle): array
    {
        $rp = is_array($cycle['retry_policy'] ?? null) ? $cycle['retry_policy'] : [];

        return [
            'retryable' => (bool) ($rp['retryable'] ?? ($cycle['retryable'] ?? false)),
            'max_retries' => (int) ($rp['max_retries'] ?? ($cycle['max_retries'] ?? 0)),
            'retries_so_far' => (int) ($rp['retries_so_far'] ?? ($cycle['retries_so_far'] ?? 0)),
            'backoff' => trim((string) ($rp['backoff'] ?? '')),
        ];
    }

    private function nextAction(string $status, bool $counted, bool $isBlocked): string
    {
        if ($status === self::STATUS_INCOMPLETE) {
            return 'repair_evidence_chain';
        }
        if ($isBlocked) {
            return 'retry_or_stop';
        }

        return $counted ? 'continue' : 'continue_not_counted';
    }

    private function normalizeOutcome(string $value): string
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'merge', 'merged' => 'merged',
            'implement', 'implemented' => 'implemented',
            'complete', 'completed', 'success' => $value === 'success' ? 'completed' : 'completed',
            'block', 'blocked' => 'blocked',
            'sandbox', 'sandbox_commit', 'sandbox-commit' => 'sandbox_commit',
            'plan', 'plan_only', 'plan-only', 'planned_only' => 'plan_only',
            'recovery', 'starvation_recovery' => $value === 'recovery' ? 'recovery' : 'starvation_recovery',
            'filler' => 'filler',
            'skip', 'skipped' => 'skipped',
            'planned' => 'planned',
            'defer', 'deferred' => 'deferred',
            default => $value,
        };
    }

    private function refString(mixed $value): ?string
    {
        if (is_array($value)) {
            // Accept a structured ref ({id|ref|hash}) and reduce to a string id.
            foreach (['id', 'ref', 'hash', 'report_hash'] as $key) {
                if (isset($value[$key]) && trim((string) $value[$key]) !== '') {
                    return trim((string) $value[$key]);
                }
            }

            return $value === [] ? null : MissionCanonicalHash::sha256($value);
        }
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function refEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_array($value)) {
            return $value === [];
        }

        return trim((string) $value) === '';
    }

    private function explainQuery(string $recordId, string $commit, string $providerRef, string $runId, ?int $cycleIndex): array
    {
        return array_filter([
            'flight_record_id' => $recordId !== '' ? $recordId : null,
            'commit' => $commit !== '' ? $commit : null,
            'provider_ref' => $providerRef !== '' ? $providerRef : null,
            'run_id' => $runId !== '' ? $runId : null,
            'cycle_index' => $cycleIndex,
        ], static fn ($v): bool => $v !== null);
    }

    // ---------- jsonl io ----------

    /**
     * @return array<string,mixed>|null
     */
    private function findInFile(string $path, string $recordId): ?array
    {
        foreach ($this->readRecords($path) as $record) {
            if ((string) ($record['flight_record_id'] ?? '') === $recordId) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Read all flight records from a JSONL file. Never throws on corruption.
     *
     * @return list<array<string,mixed>>
     */
    private function readRecords(string $path): array
    {
        return AreaFocusJsonlReader::rowsWithStringKey($path, 'flight_record_id')[0];
    }

    // ---------- primitives ----------

    private function str(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : $fallback;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return AreaFocusStringListNormalizer::trimmedStringsOrScalarString($value);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function hashablePayload(array $payload): array
    {
        return AreaFocusLoopPayloadNormalizer::withoutFields(
            $payload,
            ['checked_at', 'recorded_at', 'report_hash', 'record_hash', 'persisted', 'record_path'],
        );
    }
}
