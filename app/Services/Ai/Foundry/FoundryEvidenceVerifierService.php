<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCycleRecorderService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousLoopReceiptIntegrityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Foundry AP-A Verifier.
 *
 * GENERATES NOTHING. This service does not propose, write canonical docs, call
 * providers, or mutate production state. It deterministically VERIFIES harvested
 * anchors against REAL evidence (recorded cycles, ledger rows, owner receipts)
 * and records every refutation to its OWN scoped append-only JSONL rejection
 * ledger. It NEVER calls AtlasEvidenceLedger::record().
 *
 * Invariant I1 (Evidence-Bound): a non-existent / false anchor MUST be refuted
 * with a recorded machine-readable drop_reason. A real anchor is confirmed.
 *
 * Foundry generation schemas (evolution_proposal/proposal_verdict/
 * evolution_outcome/roadmap) are declared in {@see FoundrySchemas} for the
 * downstream gated APs (AP-C / AP-E) and are intentionally UNUSED here.
 */
final class FoundryEvidenceVerifierService
{
    public const VERDICT_SCHEMA = 'atlas.foundry.verifier_verdict.v1';

    public const REJECTION_SCHEMA = 'atlas.foundry.false_anchor_rejection.v1';

    public const VERDICT_CONFIRMED = 'confirmed';

    public const VERDICT_REFUTED = 'refuted';

    private ?string $repoRootOverride = null;

    private ?string $rejectionStorageDirOverride = null;

    public function __construct(
        private readonly AreaFocusCycleRecorderService $cycleRecorder,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AutonomousLoopReceiptIntegrityService $receiptIntegrity,
    ) {}

    /**
     * Sandbox seam: scope the read-only `git -C <root>` calls to a test repo.
     */
    public function setRepoRootForTesting(?string $dir): void
    {
        $this->repoRootOverride = $dir;
    }

    /**
     * Scoped JSONL seam: redirect the append-only rejection ledger.
     */
    public function setRejectionStorageDirForTesting(?string $dir): void
    {
        $this->rejectionStorageDirOverride = $dir;
    }

    /**
     * Aggregate verdict over a dossier's anchors[].
     *
     * @param  array<string,mixed>  $dossier
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function verify(array $dossier, array $input = []): array
    {
        $anchors = array_values(array_filter((array) ($dossier['anchors'] ?? []), 'is_array'));
        $areaId = (string) ($dossier['area_id'] ?? ($input['area_id'] ?? 'agentic_engineering_os'));

        $verdicts = [];
        $confirmed = 0;
        $refuted = 0;
        foreach ($anchors as $anchor) {
            $verdict = $this->verifyAnchor($anchor, $input + ['area_id' => $areaId]);
            $verdicts[] = $verdict;
            if ($verdict['verdict'] === self::VERDICT_CONFIRMED) {
                $confirmed++;
            } else {
                $refuted++;
            }
        }

        $aggregate = [
            'schema_version' => self::VERDICT_SCHEMA,
            'area_id' => $areaId,
            'anchor_count' => count($anchors),
            'confirmed_count' => $confirmed,
            'refuted_count' => $refuted,
            'all_confirmed' => $anchors !== [] && $refuted === 0,
            'verdicts' => $verdicts,
            'claim_policy' => $this->claimPolicy(),
        ];
        $aggregate['verification_hash'] = $this->hash($this->aggregateIdentity($aggregate));

        return $aggregate;
    }

    /**
     * Verify a single harvested anchor (atlas.foundry.anchor.v1).
     *
     * @param  array<string,mixed>  $anchor
     * @param  array<string,mixed>  $input
     * @return array<string,mixed> atlas.foundry.verifier_verdict.v1
     */
    public function verifyAnchor(array $anchor, array $input = []): array
    {
        $anchorType = (string) ($anchor['anchor_type'] ?? '');
        $anchorId = (string) ($anchor['anchor_id'] ?? $this->deriveAnchorId($anchor));
        $anchorClaim = $anchor['anchor_claim'] ?? null;
        $sourcePath = (string) ($anchor['source_path'] ?? '');

        [$checks, $dropReason, $matchedEventIds] = match ($anchorType) {
            'cycle_id' => $this->checkCycleId($anchorClaim, $input),
            'commit_hash' => $this->checkCommitHash($anchorClaim, $input),
            'ledger_event' => $this->checkLedgerEvent($anchor, $input),
            'merge_hash', 'plan_completion', 'blocker_count' => $this->checkGate($anchorType, $sourcePath, $input),
            'repro_cmd' => $this->checkReproCmd(),
            default => $this->checkUnknown($anchorType),
        };

        $verdict = $dropReason === null ? self::VERDICT_CONFIRMED : self::VERDICT_REFUTED;

        $result = [
            'schema_version' => self::VERDICT_SCHEMA,
            'verdict' => $verdict,
            'anchor_id' => $anchorId,
            'anchor_type' => $anchorType,
            'checked_at' => $this->deterministicCheckedAt($anchorId, $anchorType),
            'checks' => $checks,
            'drop_reason' => $dropReason,
            'matched_event_ids' => array_values($matchedEventIds),
            'claim_policy' => $this->claimPolicy(),
        ];
        $result['verification_hash'] = $this->hash($this->verdictIdentity($result, $anchorClaim));

        if ($verdict === self::VERDICT_REFUTED) {
            $this->recordRejection($result, $anchorClaim, $input);
        }

        return $result;
    }

    /**
     * cycle_id anchor: resolved REAL via AreaFocusCycleRecorderService::replay
     * (or the 'cycles' input-override seam). Missing => refuted.
     *
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:?string,2:list<string>}
     */
    private function checkCycleId(mixed $claim, array $input): array
    {
        $cycleId = (string) $claim;
        $cycle = $this->resolveCycle($cycleId, $input);

        if ($cycle === null) {
            return [
                [$this->check('cycle_id_resolved', 'fail', "replay({$cycleId}) returned null")],
                'cycle_id_not_found',
                [],
            ];
        }

        return [
            [$this->check('cycle_id_resolved', 'pass', "cycle {$cycleId} resolved via AreaFocusCycleRecorderService::replay")],
            null,
            [],
        ];
    }

    /**
     * commit_hash anchor: read-only `git -C <root> log --oneline` is the ONLY
     * shell exec. Tests can bypass git entirely via the 'commit_hashes' seam.
     *
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:?string,2:list<string>}
     */
    private function checkCommitHash(mixed $claim, array $input): array
    {
        $hash = trim((string) $claim);
        $present = $this->commitHashPresent($hash, $input);

        if (! $present) {
            return [
                [$this->check('commit_hash_present', 'fail', "git log resolved nothing for {$hash}")],
                'commit_hash_absent',
                [],
            ];
        }

        return [
            [$this->check('commit_hash_present', 'pass', "commit {$hash} present in repo history")],
            null,
            [],
        ];
    }

    /**
     * ledger_event anchor: resolve a REAL row, reconstruct the EXACT envelope
     * computeEventHash was fed at write time, recompute, compare stored
     * event_hash. Missing row => ledger_event_missing; tamper => event_hash_mismatch.
     *
     * @param  array<string,mixed>  $anchor
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:?string,2:list<string>}
     */
    private function checkLedgerEvent(array $anchor, array $input): array
    {
        $eventId = (string) ($anchor['anchor_claim'] ?? '');
        $rows = $this->resolveLedgerRows($anchor, $input);

        $matched = null;
        foreach ($rows as $row) {
            if ((string) ($row['event_id'] ?? '') === $eventId && $eventId !== '') {
                $matched = $row;
                break;
            }
        }
        if ($matched === null && $eventId === '' && $rows !== []) {
            $matched = $rows[0];
            $eventId = (string) ($matched['event_id'] ?? '');
        }

        if ($matched === null) {
            return [
                [$this->check('ledger_event_present', 'fail', "no matching ledger row for {$eventId}")],
                'ledger_event_missing',
                [],
            ];
        }

        $storedHash = (string) ($matched['event_hash'] ?? '');
        $recomputed = AtlasEvidenceLedger::computeEventHash($this->reconstructEnvelope($matched));

        if ($storedHash === '' || ! hash_equals($storedHash, $recomputed)) {
            return [
                [
                    $this->check('ledger_event_present', 'pass', "row {$eventId} resolved"),
                    $this->check('event_hash_roundtrip', 'fail', "stored {$storedHash} != recomputed {$recomputed}"),
                ],
                'event_hash_mismatch',
                [],
            ];
        }

        return [
            [
                $this->check('ledger_event_present', 'pass', "row {$eventId} resolved"),
                $this->check('event_hash_roundtrip', 'pass', 'recomputed event_hash matches stored'),
            ],
            null,
            [$eventId],
        ];
    }

    /**
     * Reconstruct the EXACT envelope AtlasEvidenceLedger::computeEventHash was
     * fed at write time. occurred_at is re-parsed via CarbonImmutable->toISOString().
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function reconstructEnvelope(array $row): array
    {
        $occurredAt = $row['occurred_at'] ?? null;

        return [
            'event_id' => $row['event_id'] ?? null,
            'event_type' => $row['event_type'] ?? null,
            'envelope_id' => $row['envelope_id'] ?? null,
            'correlation_id' => $row['correlation_id'] ?? null,
            'causation_id' => $row['causation_id'] ?? null,
            'scope_type' => $row['scope_type'] ?? null,
            'scope_id' => $row['scope_id'] ?? null,
            'payload_hash' => $row['payload_hash'] ?? null,
            'occurred_at' => $occurredAt === null
                ? null
                : CarbonImmutable::parse((string) $occurredAt)->toISOString(),
        ];
    }

    /**
     * Gate anchor (merge_hash / plan_completion / blocker_count): READ derived
     * gate fields from the owner output (receiptFor / preMergeGate). NEVER
     * re-derived from the raw cycle.
     *
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:?string,2:list<string>}
     */
    private function checkGate(string $anchorType, string $sourcePath, array $input): array
    {
        $cycleId = (string) ($input['gate_cycle_id'] ?? '');
        $cycle = is_array($input['cycle'] ?? null) ? $input['cycle'] : $this->resolveCycle($cycleId, $input);

        if ($cycle === null) {
            return [
                [$this->check('gate_cycle_resolved', 'fail', 'no owner cycle to read receipt from')],
                'cycle_id_not_found',
                [],
            ];
        }

        $receipt = $this->receiptIntegrity->receiptFor($cycle, is_array($input['receipt_context'] ?? null) ? $input['receipt_context'] : []);
        $preMerge = $this->receiptIntegrity->preMergeGate($cycle);

        $checks = [];

        if (($receipt['provider_router_used'] ?? false) === true) {
            $checks[] = $this->check('provider_router', 'fail', 'owner signalled provider_router_used');

            return [$checks, 'provider_router_used', []];
        }
        $checks[] = $this->check('provider_router', 'pass', 'no provider router used');

        if (($receipt['validation']['passed'] ?? null) !== true) {
            $checks[] = $this->check('validation', 'fail', 'owner validation not passed');

            return [$checks, 'validation_not_passed', []];
        }
        $checks[] = $this->check('validation', 'pass', 'owner validation passed');

        $evidenceRefs = array_values((array) ($receipt['evidence_refs'] ?? []));
        if ($evidenceRefs === []) {
            $checks[] = $this->check('evidence_refs', 'fail', 'owner evidence_refs empty');

            return [$checks, 'evidence_refs_empty', []];
        }
        $checks[] = $this->check('evidence_refs', 'pass', 'owner evidence_refs present');

        if (($preMerge['merge_allowed'] ?? null) !== true) {
            $checks[] = $this->check('pre_merge_inbox', 'fail', 'owner preMergeGate merge_allowed != true');

            return [$checks, 'pre_merge_inbox_absent', []];
        }
        $checks[] = $this->check('pre_merge_inbox', 'pass', 'owner preMergeGate merge_allowed');

        $mergeHash = trim((string) ($receipt['merge_hash'] ?? ''));
        if (($receipt['lifecycle_state'] ?? '') === AutonomousLoopReceiptIntegrityService::STATE_MERGED && $mergeHash === '') {
            $checks[] = $this->check('merge_hash', 'fail', 'lifecycle merged but merge_hash empty');

            return [$checks, 'merge_hash_empty', []];
        }
        $checks[] = $this->check('merge_hash', 'pass', 'merge_hash consistent with lifecycle state');

        // Owner-derived aggregate integrity is the FINAL gate: only reached once
        // the specific owner signals above are clean. Read, never re-derived.
        if (($receipt['integrity'] ?? '') !== AutonomousLoopReceiptIntegrityService::INTEGRITY_OK) {
            $checks[] = $this->check('integrity', 'fail', 'owner receipt integrity != ok');

            return [$checks, 'integrity_not_ok', []];
        }
        $checks[] = $this->check('integrity', 'pass', 'owner receipt integrity ok');

        return [$checks, null, []];
    }

    /**
     * repro_cmd anchor: ALWAYS refuted, WITHOUT executing anything. Verification
     * is deferred to a gated AP. No shell command other than git is ever invoked.
     *
     * @return array{0:list<array<string,mixed>>,1:?string,2:list<string>}
     */
    private function checkReproCmd(): array
    {
        return [
            [$this->check('repro_cmd', 'fail', 'repro_cmd never executed in AP-A; verification deferred to gated AP')],
            'repro_cmd_unresolvable',
            [],
        ];
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:?string,2:list<string>}
     */
    private function checkUnknown(string $anchorType): array
    {
        return [
            [$this->check('anchor_type_known', 'fail', "unknown anchor_type '{$anchorType}'")],
            'unknown_anchor_type',
            [],
        ];
    }

    /**
     * Resolve a cycle via input-override seam ('cycles') first, else replay().
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null
     */
    private function resolveCycle(string $cycleId, array $input): ?array
    {
        if (array_key_exists('cycles', $input) && is_array($input['cycles'])) {
            foreach ($input['cycles'] as $candidate) {
                if (is_array($candidate) && (string) ($candidate['cycle_id'] ?? '') === $cycleId && $cycleId !== '') {
                    return $candidate;
                }
            }

            return null;
        }

        return $this->cycleRecorder->replay($cycleId);
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function commitHashPresent(string $hash, array $input): bool
    {
        if ($hash === '') {
            return false;
        }

        if (array_key_exists('commit_hashes', $input)) {
            $known = array_map('strval', (array) $input['commit_hashes']);
            foreach ($known as $candidate) {
                if ($candidate !== '' && str_starts_with($candidate, $hash)) {
                    return true;
                }
                if ($hash !== '' && str_starts_with($hash, $candidate) && $candidate !== '') {
                    return true;
                }
            }

            return false;
        }

        return $this->gitCommitPresent($hash);
    }

    /**
     * Read-only, timeout-guarded `git -C <root> log --oneline`. No write flags.
     */
    private function gitCommitPresent(string $hash): bool
    {
        $root = $this->repoRootOverride ?? (function_exists('base_path') ? base_path() : getcwd());
        if (! is_string($root) || $root === '' || ! is_dir($root)) {
            return false;
        }
        if (! class_exists(\Symfony\Component\Process\Process::class)) {
            return false;
        }

        $process = new \Symfony\Component\Process\Process(
            ['git', '-C', $root, 'log', '--oneline', '--max-count=2000'],
        );
        $process->setTimeout(15.0);
        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }
        if (! $process->isSuccessful()) {
            return false;
        }

        $short = substr($hash, 0, 12);

        return $short !== '' && str_contains($process->getOutput(), $short);
    }

    /**
     * Resolve ledger rows via input-override seam first, else the real ledger.
     * Schema::hasTable guard replicated so a missing migration degrades gracefully.
     *
     * @param  array<string,mixed>  $anchor
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function resolveLedgerRows(array $anchor, array $input): array
    {
        if (array_key_exists('ledger_events', $input) && is_array($input['ledger_events'])) {
            return array_values(array_filter($input['ledger_events'], 'is_array'));
        }

        if (! Schema::hasTable('atlas_ledger_events')) {
            return [];
        }

        $correlationId = (string) ($anchor['correlation_id'] ?? data_get($anchor, 'anchor_meta.correlation_id', ''));
        if ($correlationId !== '') {
            return $this->ledger->eventsForCorrelation($correlationId);
        }

        $envelopeId = (string) data_get($anchor, 'anchor_meta.envelope_id', '');
        if ($envelopeId !== '') {
            return $this->ledger->eventsForEnvelope($envelopeId);
        }

        $scopeType = (string) data_get($anchor, 'anchor_meta.scope_type', '');
        $scopeId = (string) data_get($anchor, 'anchor_meta.scope_id', '');
        if ($scopeType !== '' && $scopeId !== '') {
            return $this->ledger->eventsForScope($scopeType, $scopeId);
        }

        return [];
    }

    /**
     * Append an immutable false_anchor_rejection.v1 record to OWN scoped JSONL.
     * NEVER calls AtlasEvidenceLedger::record().
     *
     * @param  array<string,mixed>  $verdict
     * @param  array<string,mixed>  $input
     */
    private function recordRejection(array $verdict, mixed $claim, array $input): void
    {
        $areaId = (string) ($input['area_id'] ?? 'agentic_engineering_os');
        $record = [
            'schema_version' => self::REJECTION_SCHEMA,
            'anchor_id' => $verdict['anchor_id'],
            'anchor_type' => $verdict['anchor_type'],
            'drop_reason' => $verdict['drop_reason'],
            'claimed_value' => is_scalar($claim) ? (string) $claim : json_encode($claim, JSON_UNESCAPED_SLASHES),
            'recorded_at' => $verdict['checked_at'],
            'verification_hash' => $verdict['verification_hash'],
        ];

        $this->appendJsonl($this->rejectionFilePath($areaId), $record);
    }

    private function rejectionFilePath(string $areaId): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($areaId)) ?: 'unknown_area';

        $base = $this->rejectionStorageDirOverride
            ?? (function_exists('storage_path') ? storage_path('atlas/foundry') : sys_get_temp_dir().'/atlas/foundry');

        return $base.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'false_anchor_rejections.jsonl';
    }

    /**
     * Append-only JSONL write (mirrors AreaFocusCycleRecorderService pattern).
     *
     * @param  array<string,mixed>  $payload
     */
    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * @param  array<string,mixed>  $anchor
     */
    private function deriveAnchorId(array $anchor): string
    {
        return 'anchor:'.substr($this->hash([
            'anchor_type' => $anchor['anchor_type'] ?? '',
            'anchor_claim' => $anchor['anchor_claim'] ?? '',
            'source_path' => $anchor['source_path'] ?? '',
        ]), 0, 24);
    }

    /**
     * @return array{name:string,result:string,detail:string}
     */
    private function check(string $name, string $result, string $detail): array
    {
        return ['name' => $name, 'result' => $result, 'detail' => $detail];
    }

    private function deterministicCheckedAt(string $anchorId, string $anchorType): string
    {
        // Deterministic stamp so identical anchor+input => identical verdict hash.
        return 'verdict:'.substr($this->hash(['anchor_id' => $anchorId, 'anchor_type' => $anchorType]), 0, 16);
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => false,
            'writes_state' => true,
            'ledger_record_invoked' => false,
            'canonical_doc_write_allowed' => false,
            'provider_invoked' => false,
            'generates_code' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $verdict
     * @return array<string,mixed>
     */
    private function verdictIdentity(array $verdict, mixed $claim): array
    {
        return [
            'schema_version' => $verdict['schema_version'],
            'verdict' => $verdict['verdict'],
            'anchor_id' => $verdict['anchor_id'],
            'anchor_type' => $verdict['anchor_type'],
            'drop_reason' => $verdict['drop_reason'],
            'matched_event_ids' => $verdict['matched_event_ids'],
            'anchor_claim' => is_scalar($claim) ? (string) $claim : json_encode($claim, JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @param  array<string,mixed>  $aggregate
     * @return array<string,mixed>
     */
    private function aggregateIdentity(array $aggregate): array
    {
        return [
            'schema_version' => $aggregate['schema_version'],
            'area_id' => $aggregate['area_id'],
            'anchor_count' => $aggregate['anchor_count'],
            'confirmed_count' => $aggregate['confirmed_count'],
            'refuted_count' => $aggregate['refuted_count'],
            'verdict_hashes' => array_map(
                static fn (array $v): string => (string) ($v['verification_hash'] ?? ''),
                $aggregate['verdicts'],
            ),
        ];
    }

    private function hash(mixed $value): string
    {
        return MissionCanonicalHash::sha256($value);
    }
}
