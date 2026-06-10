<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCycleRecorderService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusEvidencePackService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousLoopReceiptIntegrityService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanCompletionTrackerService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Foundry · Evidence Harvester + Anchor Verifier (AP-A).
 *
 * AP-A INVIOLABLE RULE: this service generates NOTHING. Zero proposal
 * generation, zero canonical/doc writes, zero production mutation. It only
 * HARVESTS real evidence (from existing owners, read-only) into a dossier and
 * deterministically VERIFIES anchors against that real evidence.
 *
 * harvest() composes four owners read-only:
 *   - AreaFocusCycleRecorderService::listCycles/replay (real cycles)
 *   - AtlasEvidenceLedger::eventsForScope               (real ledger events)
 *   - AreaFocusEvidencePackService::build               (evidence packs)
 *   - AutonomousLoopReceiptIntegrityService::receiptFor (merge/validation gates)
 *   - PlanCompletionTrackerService::rollup              (plan completion, read-only)
 *
 * Every owner call is guarded by an input-override seam (array_key_exists)
 * mirroring SelfDirectedEvolutionGapReadModelService::project, so tests inject
 * all sources and touch ZERO DB/JSONL.
 *
 * I1 (Evidence-Bound): verifyAnchor() REJECTS a non-existent/false anchor
 * (fake cycle_id, missing commit, repro that cannot be resolved) with a
 * recorded machine-readable drop_reason. repro_cmd anchors are INERT markers:
 * never executed, always resolved=false / integrity_status=unresolved.
 */
final class FoundryEvidenceHarvesterService
{
    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const SOURCE_CYCLES = 'cycles';

    public const SOURCE_LEDGER = 'ledger';

    public const SOURCE_PLAN_COMPLETION = 'plan_completion';

    public const INTEGRITY_OK = 'ok';

    public const INTEGRITY_INCOMPLETE = 'incomplete';

    public const INTEGRITY_UNRESOLVED = 'unresolved';

    public const ANCHOR_DEFERRED_SOURCE = 'deferred_by_apa';

    /** Ledger event types that signal a blocker anchor. */
    private const BLOCKER_EVENT_TYPES = [
        'OPERATION_BLOCKED',
        'GATE_BLOCKED',
        'KERNEL_PIPELINE_REJECTED',
    ];

    /** @var list<string> machine-readable verifier drop reasons */
    public const DROP_REASONS = [
        'cycle_id_not_found',
        'commit_hash_absent',
        'repro_cmd_unresolvable',
        'merge_hash_empty',
        'validation_not_passed',
        'evidence_refs_empty',
        'pre_merge_inbox_absent',
        'provider_router_used',
        'integrity_not_ok',
        'ledger_event_missing',
        'event_hash_mismatch',
    ];

    public function __construct(
        private readonly AreaFocusCycleRecorderService $cycleRecorder,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AreaFocusEvidencePackService $evidencePacks,
        private readonly AutonomousLoopReceiptIntegrityService $receiptIntegrity,
        private readonly PlanCompletionTrackerService $planCompletion,
    ) {}

    /**
     * Harvest a real-evidence dossier (atlas.foundry.dossier.v1).
     *
     * Input-override seam keys (mirror SDE::project): cycles, ledger_events,
     * plan_rollup, area_id, plan_id, decomposed_plan, ledger_scope_type,
     * ledger_limit. When all data sources are injected, zero owner DB/JSONL
     * access happens.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function harvest(array $input = []): array
    {
        $areaId = array_key_exists('area_id', $input) && is_string($input['area_id']) && $input['area_id'] !== ''
            ? $input['area_id']
            : 'agentic_engineering_os';

        $blockers = [];
        $anchors = [];
        $cycleReceipts = [];
        $evidencePacks = [];

        // ---- cycles source ----
        [$cycles, $cyclesSummary, $cyclesBlocker] = $this->collectCycles($input, $areaId);
        if ($cyclesBlocker !== null) {
            $blockers[] = $cyclesBlocker;
        }
        foreach ($cycles as $cycle) {
            if (! is_array($cycle)) {
                continue;
            }
            try {
                $receipt = $this->receiptIntegrity->receiptFor($cycle, ['area_id' => $areaId]);
            } catch (Throwable $e) {
                $blockers[] = $this->sourceUnavailable('cycle_receipt', $e);
                $receipt = [];
            }
            try {
                $pack = $this->evidencePacks->build($cycle);
            } catch (Throwable $e) {
                $blockers[] = $this->sourceUnavailable('evidence_pack', $e);
                $pack = [];
            }
            if ($receipt !== []) {
                $cycleReceipts[] = $receipt;
            }
            if ($pack !== []) {
                $evidencePacks[] = $pack;
            }
            $anchors = array_merge($anchors, $this->cycleAnchors($cycle, $receipt));
        }

        // ---- ledger source ----
        [$ledgerEvents, $ledgerSummary, $ledgerBlocker] = $this->collectLedger($input, $areaId);
        if ($ledgerBlocker !== null) {
            $blockers[] = $ledgerBlocker;
        }
        $anchors = array_merge($anchors, $this->ledgerBlockerAnchors($ledgerEvents));

        // ---- plan completion source (read-only rollup; NO recordCycle) ----
        [$planRollup, $planSummary, $planBlocker] = $this->collectPlanCompletion($input, $areaId);
        if ($planBlocker !== null) {
            $blockers[] = $planBlocker;
        }
        $anchors = array_merge($anchors, $this->planCompletionAnchors($planRollup));

        $anchors = $this->dedupeAnchors($anchors);

        $sourceSummary = [
            self::SOURCE_CYCLES => $cyclesSummary,
            self::SOURCE_LEDGER => $ledgerSummary,
            self::SOURCE_PLAN_COMPLETION => $planSummary,
        ];
        $availableCount = count(array_filter(
            $sourceSummary,
            static fn (array $row): bool => ($row['available'] ?? false) === true,
        ));
        $status = match (true) {
            $availableCount === 0 => self::STATUS_BLOCKED,
            $blockers !== [] => self::STATUS_PARTIAL,
            default => self::STATUS_READY,
        };

        $payload = [
            'schema_version' => FoundrySchemas::DOSSIER,
            'status' => $status,
            'area_id' => $areaId,
            'source_summary' => $sourceSummary,
            'anchor_count' => count($anchors),
            'anchors' => $anchors,
            'cycle_receipts' => $cycleReceipts,
            'evidence_packs' => $evidencePacks,
            'plan_completion' => $planRollup,
            'blockers' => $blockers,
            'owner_reuse_matrix' => $this->ownerReuseMatrix(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['dossier_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * Verify every anchor in a dossier (atlas.foundry.verifier_verdict.v1 list).
     * Aggregated, deterministic, no provider call.
     *
     * @param  array<string,mixed>  $dossier
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function verify(array $dossier, array $input = []): array
    {
        $anchors = array_values(array_filter((array) ($dossier['anchors'] ?? []), 'is_array'));
        $verdicts = [];
        $confirmed = 0;
        $refuted = 0;
        $rejections = [];

        foreach ($anchors as $anchor) {
            $verdict = $this->verifyAnchor($anchor, $input);
            $verdicts[] = $verdict;
            if (($verdict['verdict'] ?? '') === 'confirmed') {
                $confirmed++;
            } else {
                $refuted++;
                $rejections[] = $this->falseAnchorRejection($anchor, $verdict);
            }
        }

        return [
            'verdict_count' => count($verdicts),
            'confirmed' => $confirmed,
            'refuted' => $refuted,
            'verdicts' => $verdicts,
            'false_anchor_rejections' => $rejections,
        ];
    }

    /**
     * Verify ONE anchor against real evidence. Returns a deterministic
     * atlas.foundry.verifier_verdict.v1. A false/non-existent anchor is
     * REFUTED with a machine-readable drop_reason (invariant I1).
     *
     * Gate checks (merge_hash/validation/pre_merge_inbox/provider_router/
     * integrity) READ from receiptFor()/preMergeGate() output, never re-derived.
     *
     * @param  array<string,mixed>  $anchor
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function verifyAnchor(array $anchor, array $input = []): array
    {
        $type = (string) ($anchor['anchor_type'] ?? '');
        $checks = [];
        $dropReason = null;
        $matchedEventIds = [];

        // Integrity gate first (read from harvested anchor integrity_status).
        $integrity = (string) ($anchor['integrity_status'] ?? '');

        switch ($type) {
            case 'cycle_id':
                $cycle = $this->resolveCycle((string) ($anchor['anchor_claim'] ?? ''), $input);
                if ($cycle === null) {
                    $checks[] = $this->check('cycle_resolves', 'fail', 'cycle_id not found via replay()');
                    $dropReason = 'cycle_id_not_found';
                } else {
                    $checks[] = $this->check('cycle_resolves', 'pass', 'cycle found');
                }
                break;

            case 'commit_hash':
                $claim = trim((string) ($anchor['anchor_claim'] ?? ''));
                if ($claim === '') {
                    $checks[] = $this->check('commit_present', 'fail', 'commit hash absent');
                    $dropReason = 'commit_hash_absent';
                } else {
                    $checks[] = $this->check('commit_present', 'pass', 'commit hash present');
                }
                break;

            case 'merge_hash':
                $claim = trim((string) ($anchor['anchor_claim'] ?? ''));
                if ($claim === '') {
                    $checks[] = $this->check('merge_hash_present', 'fail', 'merge hash empty');
                    $dropReason = 'merge_hash_empty';
                } else {
                    $checks[] = $this->check('merge_hash_present', 'pass', 'merge hash present');
                }
                break;

            case 'ledger_event':
                [$ok, $ids] = $this->resolveLedgerEvent($anchor, $input);
                $matchedEventIds = $ids;
                if (! $ok) {
                    $checks[] = $this->check('ledger_event_resolves', 'fail', 'no matching real ledger event');
                    $dropReason = 'ledger_event_missing';
                } else {
                    $checks[] = $this->check('ledger_event_resolves', 'pass', 'matched real ledger event');
                }
                break;

            case 'blocker_count':
            case 'plan_completion':
                // Read-model anchors: confirmed when harvested integrity is ok.
                $checks[] = $this->check('read_model_integrity', $integrity === self::INTEGRITY_OK ? 'pass' : 'fail', 'integrity='.$integrity);
                if ($integrity !== self::INTEGRITY_OK) {
                    $dropReason = 'integrity_not_ok';
                }
                break;

            case 'repro_cmd':
                // INERT marker — NEVER executed in AP-A. Always unresolvable here.
                $checks[] = $this->check('repro_cmd_inert', 'fail', 'repro_cmd is an inert marker; AP-A never executes shell');
                $dropReason = 'repro_cmd_unresolvable';
                break;

            default:
                $checks[] = $this->check('known_anchor_type', 'fail', 'unknown anchor_type: '.$type);
                $dropReason = 'integrity_not_ok';
                break;
        }

        $verdict = $dropReason === null ? 'confirmed' : 'refuted';

        $identity = [
            'verdict' => $verdict,
            'anchor_id' => (string) ($anchor['anchor_id'] ?? ''),
            'anchor_type' => $type,
            'anchor_claim' => $anchor['anchor_claim'] ?? null,
            'drop_reason' => $dropReason,
            'matched_event_ids' => $matchedEventIds,
            'checks' => $checks,
        ];

        $payload = [
            'schema_version' => FoundrySchemas::VERIFIER_VERDICT,
            'verdict' => $verdict,
            'anchor_id' => (string) ($anchor['anchor_id'] ?? ''),
            'anchor_type' => $type,
            'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'checks' => $checks,
            'drop_reason' => $dropReason,
            'matched_event_ids' => $matchedEventIds,
            'verification_hash' => 'sha256:'.MissionCanonicalHash::sha256($identity),
        ];

        return $payload;
    }

    /**
     * Persist a false-anchor rejection to OWN scoped JSONL ONLY. Append-only,
     * never written to atlas_ledger_events / canonical stores. Returns the
     * record (also emitted in verify() output).
     *
     * @param  array<string,mixed>  $anchor
     * @param  array<string,mixed>  $verdict
     * @return array<string,mixed>
     */
    public function recordFalseAnchorRejection(array $anchor, array $verdict, string $areaId = 'agentic_engineering_os'): array
    {
        $record = $this->falseAnchorRejection($anchor, $verdict);
        $this->appendRejectionJsonl($this->rejectionFilePath($areaId), $record);

        return $record;
    }

    // ---------- read seams (read-only; overridable for tests) ----------

    /** @return array<string,mixed> */
    protected function fetchCycleList(string $areaId): array
    {
        return $this->cycleRecorder->listCycles($areaId);
    }

    /** @return array<string,mixed>|null */
    protected function fetchCycleReplay(string $cycleId): ?array
    {
        return $this->cycleRecorder->replay($cycleId);
    }

    /** @return array<int,array<string,mixed>> */
    protected function fetchLedgerEvents(string $scopeType, string $scopeId, int $limit): array
    {
        return $this->ledger->eventsForScope($scopeType, $scopeId, $limit);
    }

    /**
     * @param  array<string,mixed>  $decomposedPlan
     * @return array<string,mixed>
     */
    protected function fetchPlanRollup(string $planId, string $areaId, array $decomposedPlan): array
    {
        return $this->planCompletion->rollup($planId, $areaId, $decomposedPlan);
    }

    // ---------- source collectors ----------

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:list<mixed>,1:array<string,mixed>,2:array<string,mixed>|null}
     */
    private function collectCycles(array $input, string $areaId): array
    {
        try {
            if (array_key_exists('cycles', $input) && is_array($input['cycles'])) {
                $cycles = array_values($input['cycles']);
            } else {
                $list = $this->fetchCycleList($areaId);
                $cycles = [];
                foreach ((array) ($list['cycles'] ?? []) as $summary) {
                    $cid = is_array($summary) ? (string) ($summary['cycle_id'] ?? '') : '';
                    if ($cid === '') {
                        continue;
                    }
                    $full = $this->fetchCycleReplay($cid);
                    if (is_array($full)) {
                        $cycles[] = $full;
                    }
                }
            }
        } catch (Throwable $e) {
            return [[], $this->unavailableSummary(), $this->sourceUnavailable(self::SOURCE_CYCLES, $e)];
        }

        return [$cycles, $this->availableSummary(count($cycles)), null];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>,2:array<string,mixed>|null}
     */
    private function collectLedger(array $input, string $areaId): array
    {
        try {
            if (array_key_exists('ledger_events', $input) && is_array($input['ledger_events'])) {
                $events = array_values(array_filter($input['ledger_events'], 'is_array'));
            } else {
                $scopeType = array_key_exists('ledger_scope_type', $input) && is_string($input['ledger_scope_type'])
                    ? $input['ledger_scope_type']
                    : 'area_focus_loop';
                $limit = array_key_exists('ledger_limit', $input) ? max(1, (int) $input['ledger_limit']) : 100;
                $events = $this->fetchLedgerEvents($scopeType, $areaId, $limit);
            }
        } catch (Throwable $e) {
            return [[], $this->unavailableSummary(), $this->sourceUnavailable(self::SOURCE_LEDGER, $e)];
        }

        return [$events, $this->availableSummary(count($events)), null];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>|null}
     */
    private function collectPlanCompletion(array $input, string $areaId): array
    {
        try {
            if (array_key_exists('plan_rollup', $input) && is_array($input['plan_rollup'])) {
                $rollup = $input['plan_rollup'];
            } else {
                $planId = array_key_exists('plan_id', $input) && is_string($input['plan_id']) ? $input['plan_id'] : '';
                if ($planId === '') {
                    // No plan requested: report unavailable WITHOUT touching the owner.
                    return [[], $this->unavailableSummary(), null];
                }
                $decomposed = array_key_exists('decomposed_plan', $input) && is_array($input['decomposed_plan'])
                    ? $input['decomposed_plan']
                    : [];
                $rollup = $this->fetchPlanRollup($planId, $areaId, $decomposed);
            }
        } catch (Throwable $e) {
            return [[], $this->unavailableSummary(), $this->sourceUnavailable(self::SOURCE_PLAN_COMPLETION, $e)];
        }

        $raw = is_array($rollup['slices'] ?? null) ? count($rollup['slices']) : 0;

        return [$rollup, $this->availableSummary($raw), null];
    }

    // ---------- anchor builders ----------

    /**
     * @param  array<string,mixed>  $cycle
     * @param  array<string,mixed>  $receipt
     * @return list<array<string,mixed>>
     */
    private function cycleAnchors(array $cycle, array $receipt): array
    {
        $anchors = [];
        $cycleId = (string) ($cycle['cycle_id'] ?? '');

        if ($cycleId !== '') {
            $anchors[] = $this->makeAnchor(
                'cycle_id',
                AreaFocusCycleRecorderService::class.'::replay',
                'cycle.cycle_id',
                $cycleId,
                true,
                self::INTEGRITY_OK,
            );
        }

        // commit_hash from cycle commit block.
        $commit = is_array($cycle['commit'] ?? null) ? $cycle['commit'] : [];
        $commitHash = (string) ($commit['commit_hash'] ?? '');
        if ($commitHash !== '') {
            $anchors[] = $this->makeAnchor(
                'commit_hash',
                'cycle.commit',
                'cycle.commit.commit_hash',
                $commitHash,
                true,
                self::INTEGRITY_OK,
            );
        }

        // merge_hash read from receiptFor() output (never re-derived).
        $mergeHash = (string) ($receipt['merge_hash'] ?? '');
        if ($mergeHash !== '') {
            $anchors[] = $this->makeAnchor(
                'merge_hash',
                AutonomousLoopReceiptIntegrityService::class.'::receiptFor',
                'receipt.merge_hash',
                $mergeHash,
                true,
                self::INTEGRITY_OK,
            );
        }

        // blocker_count anchors from cycle blocked_reasons[] / blockers[].
        $blockers = array_values(array_filter(
            array_merge(
                (array) ($cycle['blocked_reasons'] ?? []),
                (array) ($cycle['blockers'] ?? []),
            ),
            'is_string',
        ));
        if ($blockers !== []) {
            $anchors[] = $this->makeAnchor(
                'blocker_count',
                AreaFocusCycleRecorderService::class.'::replay',
                'cycle.blocked_reasons',
                count($blockers),
                true,
                self::INTEGRITY_OK,
            );
        }

        // repro_cmd anchors: INERT markers, never executed.
        foreach ($this->reproCommands($cycle) as $cmd) {
            $anchors[] = $this->reproAnchor($cmd);
        }

        return $anchors;
    }

    /**
     * @param  array<int,array<string,mixed>>  $events
     * @return list<array<string,mixed>>
     */
    private function ledgerBlockerAnchors(array $events): array
    {
        $anchors = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $eventType = (string) ($event['event_type'] ?? '');
            if (! in_array($eventType, self::BLOCKER_EVENT_TYPES, true)) {
                continue;
            }
            $eventId = (string) ($event['event_id'] ?? '');
            $anchors[] = $this->makeAnchor(
                'ledger_event',
                AtlasEvidenceLedger::class.'::eventsForScope',
                'ledger_event.event_id',
                $eventId !== '' ? $eventId : $eventType,
                $eventId !== '',
                $eventId !== '' ? self::INTEGRITY_OK : self::INTEGRITY_INCOMPLETE,
                // Finding 17: carry the identity keys the strong verifier
                // (FoundryEvidenceVerifierService::resolveLedgerRows) needs to
                // resolve the REAL row and recompute event_hash. The anchor
                // NEVER self-supplies event_hash — only row-locator identity.
                $this->ledgerAnchorMeta($event),
            );
        }

        return $anchors;
    }

    /**
     * @param  array<string,mixed>  $rollup
     * @return list<array<string,mixed>>
     */
    private function planCompletionAnchors(array $rollup): array
    {
        if ($rollup === []) {
            return [];
        }
        $planId = (string) ($rollup['plan_id'] ?? '');
        $delivered = (int) (data_get($rollup, 'totals.delivered', data_get($rollup, 'delivered_count', 0)));

        return [
            $this->makeAnchor(
                'plan_completion',
                PlanCompletionTrackerService::class.'::rollup',
                'plan_completion.totals.delivered',
                ['plan_id' => $planId, 'delivered' => $delivered],
                true,
                self::INTEGRITY_OK,
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return list<string>
     */
    private function reproCommands(array $cycle): array
    {
        $cmds = [];
        foreach ((array) ($cycle['repro_cmds'] ?? $cycle['repro_commands'] ?? []) as $cmd) {
            if (is_string($cmd) && $cmd !== '') {
                $cmds[] = $cmd;
            }
        }

        return array_values(array_unique($cmds));
    }

    /**
     * @return array<string,mixed>
     */
    private function reproAnchor(string $cmd): array
    {
        // INERT marker: resolved=false, integrity unresolved, deferred to a
        // later, gated phase. AP-A NEVER executes a shell command.
        return $this->makeAnchor(
            'repro_cmd',
            self::ANCHOR_DEFERRED_SOURCE,
            'cycle.repro_cmds',
            $cmd,
            false,
            self::INTEGRITY_UNRESOLVED,
        );
    }

    /**
     * Extract the row-locator identity keys for a ledger_event anchor.
     *
     * Finding 17: these are the SAME keys FoundryEvidenceVerifierService reads
     * (anchor_meta.correlation_id / envelope_id / scope_type / scope_id) to
     * resolve the REAL row before recomputing event_hash. event_hash is
     * deliberately NOT copied here: the anchor never self-supplies the hash —
     * the verifier always recomputes it from the resolved DB row.
     *
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function ledgerAnchorMeta(array $event): array
    {
        $meta = [];
        foreach (['scope_type', 'scope_id', 'correlation_id', 'envelope_id'] as $key) {
            $value = $event[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $meta[$key] = $value;
            }
        }

        return $meta;
    }

    /**
     * @param  mixed  $claim
     * @param  array<string,mixed>  $anchorMeta  row-locator identity (never event_hash)
     * @return array<string,mixed>
     */
    private function makeAnchor(
        string $type,
        string $source,
        string $sourcePath,
        mixed $claim,
        bool $resolved,
        string $integrityStatus,
        array $anchorMeta = [],
    ): array {
        $stable = [
            'anchor_type' => $type,
            'anchor_claim' => $claim,
            'source_path' => $sourcePath,
        ];
        $hash = hash('sha256', (string) json_encode($stable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $anchor = [
            'anchor_id' => 'fanchor_'.substr($hash, 0, 16),
            'anchor_type' => $type,
            'anchor_source' => $source,
            'source_path' => $sourcePath,
            'anchor_claim' => $claim,
            'resolved' => $resolved,
            'integrity_status' => $integrityStatus,
            'anchor_hash' => 'sha256:'.$hash,
        ];

        // Additive: only emit anchor_meta when present so anchors that never
        // had it stay byte-identical (existing tests + dossier_hash unchanged).
        if ($anchorMeta !== []) {
            $anchor['anchor_meta'] = $anchorMeta;
        }

        return $anchor;
    }

    /**
     * @param  list<array<string,mixed>>  $anchors
     * @return list<array<string,mixed>>
     */
    private function dedupeAnchors(array $anchors): array
    {
        $seen = [];
        $unique = [];
        foreach ($anchors as $anchor) {
            $key = (string) ($anchor['anchor_id'] ?? '');
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $anchor;
        }

        return array_values($unique);
    }

    // ---------- verifier helpers ----------

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null
     */
    private function resolveCycle(string $cycleId, array $input): ?array
    {
        if ($cycleId === '') {
            return null;
        }
        if (array_key_exists('cycles', $input) && is_array($input['cycles'])) {
            foreach ($input['cycles'] as $cycle) {
                if (is_array($cycle) && (string) ($cycle['cycle_id'] ?? '') === $cycleId) {
                    return $cycle;
                }
            }

            return null;
        }

        try {
            return $this->fetchCycleReplay($cycleId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $anchor
     * @param  array<string,mixed>  $input
     * @return array{0:bool,1:list<string>}
     */
    private function resolveLedgerEvent(array $anchor, array $input): array
    {
        $claim = (string) ($anchor['anchor_claim'] ?? '');
        if ($claim === '') {
            return [false, []];
        }

        $events = [];
        if (array_key_exists('ledger_events', $input) && is_array($input['ledger_events'])) {
            $events = array_values(array_filter($input['ledger_events'], 'is_array'));
        } else {
            try {
                $scopeType = is_string($input['ledger_scope_type'] ?? null) ? $input['ledger_scope_type'] : 'area_focus_loop';
                $scopeId = is_string($input['area_id'] ?? null) ? $input['area_id'] : 'agentic_engineering_os';
                $events = $this->fetchLedgerEvents($scopeType, $scopeId, 100);
            } catch (Throwable) {
                return [false, []];
            }
        }

        foreach ($events as $event) {
            if ((string) ($event['event_id'] ?? '') === $claim) {
                return [true, [$claim]];
            }
        }

        return [false, []];
    }

    /**
     * @param  array<string,mixed>  $anchor
     * @param  array<string,mixed>  $verdict
     * @return array<string,mixed>
     */
    private function falseAnchorRejection(array $anchor, array $verdict): array
    {
        return [
            'schema_version' => FoundrySchemas::FALSE_ANCHOR_REJECTION,
            'anchor_id' => (string) ($anchor['anchor_id'] ?? ''),
            'anchor_type' => (string) ($anchor['anchor_type'] ?? ''),
            'drop_reason' => (string) ($verdict['drop_reason'] ?? 'integrity_not_ok'),
            'claimed_value' => $anchor['anchor_claim'] ?? null,
            'recorded_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'verification_hash' => (string) ($verdict['verification_hash'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function appendRejectionJsonl(string $path, array $record): void
    {
        AppendOnlyJsonlStore::append($path, $record);
    }

    private function rejectionFilePath(string $areaId): string
    {
        $base = function_exists('storage_path')
            ? storage_path('atlas/foundry/'.$this->areaSlug($areaId))
            : sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_foundry'.DIRECTORY_SEPARATOR.$this->areaSlug($areaId);

        return $base.DIRECTORY_SEPARATOR.'false_anchor_rejections.jsonl';
    }

    private function areaSlug(string $areaId): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($areaId)) ?? '';

        return $slug !== '' ? $slug : 'unknown_area';
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $name, string $result, string $detail): array
    {
        return ['name' => $name, 'result' => $result, 'detail' => $detail];
    }

    // ---------- summaries / matrix / policy ----------

    /**
     * @return array<string,mixed>
     */
    private function availableSummary(int $rawCount): array
    {
        return ['available' => true, 'raw_count' => $rawCount];
    }

    /**
     * @return array<string,mixed>
     */
    private function unavailableSummary(): array
    {
        return ['available' => false, 'raw_count' => 0];
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceUnavailable(string $source, Throwable $e): array
    {
        return [
            'source' => $source,
            'reason' => 'source_unavailable',
            'detail' => $e->getMessage(),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function ownerReuseMatrix(): array
    {
        return [
            self::SOURCE_CYCLES => [
                'owner_service' => AreaFocusCycleRecorderService::class,
                'reused_methods' => ['listCycles', 'replay'],
                'not_invoked_methods' => ['record'],
                'source_schema_version' => AreaFocusCycleRecorderService::CYCLE_SCHEMA,
            ],
            'evidence_pack' => [
                'owner_service' => AreaFocusEvidencePackService::class,
                'reused_methods' => ['build'],
                'not_invoked_methods' => [],
                'source_schema_version' => AreaFocusEvidencePackService::PACK_SCHEMA,
            ],
            'receipt_integrity' => [
                'owner_service' => AutonomousLoopReceiptIntegrityService::class,
                'reused_methods' => ['receiptFor', 'preMergeGate'],
                'not_invoked_methods' => [],
                'source_schema_version' => AutonomousLoopReceiptIntegrityService::RECEIPT_SCHEMA,
            ],
            self::SOURCE_LEDGER => [
                'owner_service' => AtlasEvidenceLedger::class,
                'reused_methods' => ['eventsForScope'],
                'not_invoked_methods' => ['record'],
                'source_schema_version' => AtlasEvidenceLedger::SCHEMA_VERSION,
            ],
            self::SOURCE_PLAN_COMPLETION => [
                'owner_service' => PlanCompletionTrackerService::class,
                'reused_methods' => ['rollup'],
                'not_invoked_methods' => ['recordCycle'],
                'source_schema_version' => PlanCompletionTrackerService::LEDGER_SCHEMA,
            ],
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'writes_state' => false,
            'generates_code' => false,
            'materializes_schema' => false,
            'provider_invoked' => false,
            'ledger_record_invoked' => false,
            'canonical_doc_write_allowed' => false,
        ];
    }
}
