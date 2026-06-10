<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Promotion;

use App\Services\Ai\Foundry\Frontier\Outcome\FileRoadmapStorePort;
use App\Services\Ai\Foundry\Frontier\Outcome\RoadmapStorePort;
use App\Services\Ai\Foundry\FoundrySchemas;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusInboxService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSelfConstructionAdmissionBridgeService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;

/**
 * AFEF AP-D Promotion — I4 No Self-Canonization.
 *
 * The ONLY canonical/backlog write path is operator -> compiler -> admission ->
 * #2 gates. This service NEVER calls AreaFocusOperatorDecisionService::decide()
 * and NEVER fabricates a receipt: it consumes a receipt produced by the operator
 * path as the SOLE I4 credential. The promoted finding BODY is loaded from the
 * trusted AreaFocusInboxService::project() projection keyed by receipt.finding_hash
 * — NEVER from a caller-supplied finding — closing the content-binding gap.
 *
 * An AFEF-origin promoted finding receives NO special treatment: it carries
 * owner_candidate='atlas_dev' and the SAME bridge-set required_gates
 * (scope_validator, focused_test, judge_accept, merge_governor) as any
 * factory_max packet, and is blocked by FinalDeliveryQualityGateService /
 * Ap786OwnerFlowExecutor downstream with the SAME canonical reasons — zero
 * AFEF exception.
 */
final class FoundryOperatorPromotionBacklogCompilerService
{
    public const PROMOTED_FINDING_SCHEMA = 'atlas.software_company_stewardship.area_focus_deep_finding.v1';

    public const ORIGIN = 'operator_promoted_inbox_survivor';

    public const STATUS_PROMOTED = 'promoted';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_ALREADY_PROMOTED = 'already_promoted';

    public const BLOCK_RECEIPT_REQUIRED = 'operator_receipt_required';

    public const BLOCK_DECISION_NOT_ACCEPT = 'operator_decision_not_accept';

    public const BLOCK_RECEIPT_SCHEMA_MISMATCH = 'operator_receipt_schema_mismatch';

    public const BLOCK_RECEIPT_NOT_PROMOTION_CREDENTIAL = 'operator_receipt_not_a_promotion_credential';

    public const BLOCK_INBOX_ITEM_NOT_FOUND = 'inbox_item_not_found';

    /** roadmap.v1 capability state for a freshly-promoted, not-yet-proven capability. */
    public const ROADMAP_SEED_STATE = 'implemented';

    private ?string $backlogStorageDirOverride = null;

    private ?string $roadmapStorageDirOverride = null;

    private readonly RoadmapStorePort $roadmapStore;

    /** True when we created the default FileRoadmapStorePort and may point its dir. */
    private readonly bool $ownsDefaultRoadmapStore;

    /**
     * RoadmapStorePort is the EXISTING read seam onto the append-only roadmap.jsonl
     * ledger; it is injectable for tests but defaults to the same FileRoadmapStorePort
     * the materializer command builds (the interface is unbound in the container, so a
     * null default keeps the thin promote command resolvable without a new binding).
     */
    public function __construct(
        private readonly AreaFocusSelfConstructionAdmissionBridgeService $admissionBridge,
        ?RoadmapStorePort $roadmapStore = null,
    ) {
        $this->ownsDefaultRoadmapStore = $roadmapStore === null;
        $this->roadmapStore = $roadmapStore ?? new FileRoadmapStorePort();
    }

    /**
     * Scoped JSONL seam mirroring FoundryEvidenceVerifierService::setRejectionStorageDirForTesting:
     * redirects the promoted-finding append-only ledger.
     */
    public function setBacklogStorageDirForTesting(?string $dir): void
    {
        $this->backlogStorageDirOverride = $dir;
    }

    /**
     * Point the roadmap.v1 seed append at the SAME foundry storage tree the
     * materializer / FileRoadmapStorePort use (roadmap.jsonl under <base>/<slug>/),
     * so both writers share the one append-only ledger.
     */
    public function setRoadmapStorageDirForTesting(?string $dir): void
    {
        $this->roadmapStorageDirOverride = $dir;
    }

    /**
     * I4-gated promotion: operator ACCEPT receipt -> canonical factory_max backlog
     * line through the admission bridge. The receipt is the SOLE credential and
     * the inbox projection is the SOLE trusted body source.
     *
     * @param  array<string,mixed>  $operatorReceipt
     * @param  array<string,true>  $completedPacketIds
     * @return array<string,mixed>
     */
    public function compile(
        array $operatorReceipt,
        AreaFocusInboxService $inbox,
        string $areaId,
        string $focus,
        array $completedPacketIds = [],
    ): array {
        // (1) Validate the receipt as the SOLE I4 credential. No decide() call,
        // no fabrication. Any failure => hard block, ZERO write.
        $credentialBlock = $this->validateReceiptCredential($operatorReceipt);
        if ($credentialBlock !== null) {
            return $this->blocked($credentialBlock);
        }

        $findingHash = (string) $operatorReceipt['finding_hash'];
        $decisionId = (string) ($operatorReceipt['decision_id'] ?? '');

        // (2) TRUSTED-SOURCE BODY: the promoted finding body is loaded ONLY from
        // the operator inbox projection located by receipt.finding_hash. A caller
        // cannot supply the body, so a fig-leaf finding cannot ride a valid hash.
        $inboxItem = $this->locateTrustedInboxItem($inbox, $findingHash);
        if ($inboxItem === null) {
            return $this->blocked(self::BLOCK_INBOX_ITEM_NOT_FOUND);
        }

        // content_hash binds the promoted finding_hash to the operator-decided body.
        $contentHash = MissionCanonicalHash::sha256($this->trustedContent($inboxItem));

        // (3) Build the finding in area_focus_deep_finding.v1 shape from the
        // trusted inbox item ONLY. Fresh deterministic promoted finding_hash.
        $promotedFindingHash = 'sha256:'.MissionCanonicalHash::sha256([
            AreaFocusOperatorDecisionService::RECEIPT_SCHEMA,
            $decisionId,
            $findingHash,
            $contentHash,
        ]);

        $finding = $this->buildFinding($inboxItem, $promotedFindingHash, $areaId, $focus);
        // The PARENT promoted finding carries the trusted spec_seed (success_metric +
        // measure_cmd) AP-E reads post-merge; the bridge's per-packet candidate does
        // not, so it is persisted alongside on the canonical backlog record.
        $promotedParentFinding = $finding;

        // Advisory key-presence sanity (never generative) before admission.
        FoundrySchemas::validateShape(self::PROMOTED_FINDING_SCHEMA, $finding);

        // (4) The SOLE decomposition + governance gate. AP-D never touches the
        // slice planner / packet builder the bridge owns, and never threads a
        // finding directly into the owner-flow executor: admission sets the
        // bridge-set required_gates that #2 gates enforce.
        $admission = $this->admissionBridge->admit(
            $finding,
            AreaFocusSelfConstructionAdmissionBridgeService::ADMISSIBLE_REJECTION_REASONS[0],
            $areaId,
            $focus,
            $completedPacketIds,
        );

        if (($admission['admissible'] ?? false) !== true) {
            $reason = (string) ($admission['admission_blocked_reason'] ?? 'unknown');

            return $this->blocked('admission_blocked:'.$reason, $admission);
        }

        /** @var array<string,mixed> $firstPacketFinding */
        $firstPacketFinding = (array) ($admission['first_packet_finding'] ?? []);

        $promotionReceiptHash = 'sha256:'.MissionCanonicalHash::sha256([
            'operator_promotion_receipt',
            AreaFocusOperatorDecisionService::RECEIPT_SCHEMA,
            $decisionId,
            $promotedFindingHash,
        ]);

        // (5) IDEMPOTENT append: scan the scoped backlog JSONL for the same
        // promoted finding_hash. Duplicate => already_promoted, no second line.
        if ($this->alreadyPromoted($areaId, $promotedFindingHash)) {
            return [
                'status' => self::STATUS_ALREADY_PROMOTED,
                'admissible' => true,
                'blocker_reason' => '',
                'backlog_candidate' => $firstPacketFinding,
                'promoted_parent_finding' => $promotedParentFinding,
                'admission' => $admission,
                'promoted_finding_hash' => $promotedFindingHash,
                'promotion_receipt_hash' => $promotionReceiptHash,
                'backlog_written' => false,
            ];
        }

        $record = [
            'origin' => self::ORIGIN,
            'area_id' => $areaId,
            'focus' => $focus,
            'promoted_finding_hash' => $promotedFindingHash,
            'promoted_parent_finding' => $promotedParentFinding,
            'first_packet_finding' => $firstPacketFinding,
            'promotion_receipt_hash' => $promotionReceiptHash,
            'decision_id' => $decisionId,
            'source_finding_hash' => $findingHash,
        ];
        $this->appendBacklog($areaId, $record);

        // (6) AP-E ROADMAP SEED. This operator-receipt-gated promotion is the
        // ONLY I4-safe write point at which a capability may be registered, so it
        // is also the only place a freshly-merged AFEF capability can be seeded
        // into roadmap.v1 — otherwise AP-E dead-ends in BLOCK_ROADMAP_NOT_LINKED
        // and the capability can never be consolidated. The seed is appended
        // through the SAME append-only roadmap.jsonl ledger the materializer
        // writes (read side: the injected RoadmapStorePort::latest()); it copies
        // the current latest line and ADDS the new capability, bumping the
        // top-level version so latest() resolution stays deterministic with two
        // writers and the seed never shadows a higher-version proven advance.
        // The capability state is ALWAYS 'implemented' (I5: never 'proven' —
        // proven stays gated solely on AP-E's real green measure).
        $parentFindingId = (string) ($promotedParentFinding['finding_id'] ?? '');
        $this->seedRoadmapCapability($areaId, $parentFindingId, $promotionReceiptHash, $decisionId);

        return [
            'status' => self::STATUS_PROMOTED,
            'admissible' => true,
            'blocker_reason' => '',
            'backlog_candidate' => $firstPacketFinding,
            'promoted_parent_finding' => $promotedParentFinding,
            'admission' => $admission,
            'promoted_finding_hash' => $promotedFindingHash,
            'promotion_receipt_hash' => $promotionReceiptHash,
            'backlog_written' => true,
        ];
    }

    /**
     * The receipt is the SOLE credential. Returns a canonical blocker_reason on
     * any failure, or null when the receipt is a valid promotion credential.
     *
     * @param  array<string,mixed>  $receipt
     */
    private function validateReceiptCredential(array $receipt): ?string
    {
        if ($receipt === []) {
            return self::BLOCK_RECEIPT_REQUIRED;
        }

        // schema_version must be the operator decision receipt schema — a receipt
        // of any other shape is not an operator decision credential.
        if (($receipt['schema_version'] ?? null) !== AreaFocusOperatorDecisionService::RECEIPT_SCHEMA) {
            return self::BLOCK_RECEIPT_SCHEMA_MISMATCH;
        }

        if (($receipt['decision'] ?? null) !== AreaFocusOperatorDecisionService::DECISION_ACCEPT) {
            return self::BLOCK_DECISION_NOT_ACCEPT;
        }

        // An executed / actorless receipt is NOT a valid promotion credential:
        // operator ACCEPT does NOT execute (executed===false) and must route to
        // owner execution (requires_owner_execution===true) under a named actor.
        if (
            ($receipt['executed'] ?? null) !== false
            || (string) ($receipt['operator_actor'] ?? '') === ''
            || ($receipt['requires_owner_execution'] ?? null) !== true
            || (string) ($receipt['finding_hash'] ?? '') === ''
        ) {
            return self::BLOCK_RECEIPT_NOT_PROMOTION_CREDENTIAL;
        }

        return null;
    }

    /**
     * Locate the trusted inbox item whose finding_hash === receipt.finding_hash.
     * The body comes ONLY from here — never from a caller-supplied finding.
     *
     * @return array<string,mixed>|null
     */
    private function locateTrustedInboxItem(AreaFocusInboxService $inbox, string $findingHash): ?array
    {
        $projection = $inbox->project();
        $items = array_values(array_filter((array) ($projection['items'] ?? []), 'is_array'));

        foreach ($items as $item) {
            if ((string) ($item['finding_hash'] ?? '') === $findingHash && $findingHash !== '') {
                return $item;
            }
        }

        return null;
    }

    /**
     * Deterministic content fingerprint of the operator-decided body.
     *
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function trustedContent(array $item): array
    {
        return [
            'finding_hash' => (string) ($item['finding_hash'] ?? ''),
            'title' => (string) ($item['title'] ?? ''),
            'detail' => (string) ($item['rationale'] ?? ($item['detail'] ?? '')),
            'severity' => (string) ($item['risk_level'] ?? ($item['risk'] ?? 'medium')),
            'affected_files' => $this->stringList($item['affected_files'] ?? $this->specSeedAffectedFiles($item)),
            'spec_seed' => $this->trustedSpecSeed($item),
        ];
    }

    /**
     * Build the promoted finding in area_focus_deep_finding.v1 shape, sourcing
     * EVERY body field from the trusted inbox item. spec_seed is mirrored
     * INCLUDING the copied success_metric + measure_cmd so AP-E can measure
     * post-merge. active_slice_id is left for the bridge to set.
     *
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function buildFinding(array $item, string $promotedFindingHash, string $areaId, string $focus): array
    {
        $severity = (string) ($item['risk_level'] ?? ($item['risk'] ?? 'medium'));
        $detail = (string) ($item['rationale'] ?? ($item['detail'] ?? ''));
        $affected = $this->stringList($item['affected_files'] ?? $this->specSeedAffectedFiles($item));

        return [
            'schema_version' => self::PROMOTED_FINDING_SCHEMA,
            'finding_id' => 'afef_promoted_'.substr(hash('sha256', $promotedFindingHash), 0, 16),
            'finding_hash' => $promotedFindingHash,
            'area_id' => $areaId,
            'focus' => $focus,
            'title' => (string) ($item['title'] ?? 'Operator-promoted AFEF survivor'),
            'detail' => $detail,
            'severity' => $severity,
            'risk_level' => $severity,
            'owner_candidate' => 'atlas_dev',
            'affected_files' => $affected !== [] ? $affected : ['app/Services/Ai/Foundry/Frontier/Promotion/.afef_promoted'],
            'evidence_refs' => $this->stringList($item['evidence_refs'] ?? []),
            'spec_seed' => $this->trustedSpecSeed($item),
            'origin_type' => self::ORIGIN,
        ];
    }

    /**
     * spec_seed mirrored from the trusted inbox item INCLUDING success_metric +
     * measure_cmd copied verbatim (so AP-E can measure post-merge). No fabrication.
     *
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function trustedSpecSeed(array $item): array
    {
        $seed = is_array($item['spec_seed'] ?? null) ? $item['spec_seed'] : [];

        $successMetric = $seed['success_metric'] ?? ($item['success_metric'] ?? null);
        $measureCmd = $seed['measure_cmd'] ?? ($item['measure_cmd'] ?? null);

        $out = $seed;
        if ($successMetric !== null) {
            $out['success_metric'] = $successMetric;
        }
        if ($measureCmd !== null) {
            $out['measure_cmd'] = $measureCmd;
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return list<string>
     */
    private function specSeedAffectedFiles(array $item): array
    {
        $seed = is_array($item['spec_seed'] ?? null) ? $item['spec_seed'] : [];

        return $this->stringList($seed['affected_files'] ?? []);
    }

    /**
     * Scan the scoped backlog JSONL for an existing line with the same promoted
     * finding_hash (idempotency / dup-finding loop-stop prevention).
     */
    private function alreadyPromoted(string $areaId, string $promotedFindingHash): bool
    {
        $path = $this->backlogFilePath($areaId);
        if (! is_file($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded) && (string) ($decoded['promoted_finding_hash'] ?? '') === $promotedFindingHash) {
                    return true;
                }
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    /**
     * Seed a roadmap.v1 capability for the promoted parent finding, appended to
     * the SAME append-only roadmap.jsonl ledger the materializer writes.
     *
     * Append-only / drift-to-canonical (I9): copy the current latest line, ADD
     * the new capability (idempotent — skip when the finding_id is already
     * present at any version), and bump the top-level version so the materializer
     * latest() (highest version wins) resolves to this newest line. A version:0
     * seed capability never overwrites or shadows a higher-version proven advance
     * because advances bump the SAME capability's version on a NEW higher
     * top-level-version line. State is ALWAYS 'implemented' (I5: never 'proven').
     */
    private function seedRoadmapCapability(
        string $areaId,
        string $parentFindingId,
        string $promotionReceiptHash,
        string $decisionId,
    ): void {
        if ($parentFindingId === '') {
            return;
        }

        // When we own the default FileRoadmapStorePort, point its read at the SAME
        // base dir this seed writes to (so read and append share one ledger even
        // when only the backlog dir is overridden / no Laravel app is booted).
        if ($this->ownsDefaultRoadmapStore && $this->roadmapStore instanceof FileRoadmapStorePort) {
            $this->roadmapStore->setStorageDir($this->roadmapBaseDir());
        }

        $latest = $this->roadmapStore->latest($areaId);

        /** @var list<array<string,mixed>> $capabilities */
        $capabilities = [];
        foreach ((array) ($latest['capabilities'] ?? []) as $cap) {
            if (is_array($cap)) {
                // Idempotent: a capability for this finding already exists (seeded
                // OR already advanced) => do NOT re-seed and do NOT shadow it.
                if ((string) ($cap['finding_id'] ?? '') === $parentFindingId) {
                    return;
                }
                $capabilities[] = $cap;
            }
        }

        $capabilities[] = [
            'finding_id' => $parentFindingId,
            'state' => self::ROADMAP_SEED_STATE,
            'version' => 0,
            'evidence_ref' => $promotionReceiptHash,
        ];

        $generatedAt = 'sha256-seed:'.MissionCanonicalHash::sha256([
            'roadmap_seed_generated_at',
            $areaId,
            $parentFindingId,
            $decisionId,
        ]);

        $line = [
            'schema_version' => FoundrySchemas::ROADMAP,
            'roadmap_id' => 'foundry_roadmap_'.substr(hash('sha256', $areaId), 0, 16),
            'area_id' => $areaId,
            'generated_at' => $generatedAt,
            // Append-only: strictly above the current latest so latest() resolves
            // here; 0 only when this is the very first line in the ledger.
            'version' => $latest === null ? 0 : ((int) ($latest['version'] ?? 0) + 1),
            'capabilities' => array_values($capabilities),
        ];

        FoundrySchemas::validateShape(FoundrySchemas::ROADMAP, $line);

        $this->appendRoadmap($areaId, $line);
    }

    /**
     * Append a roadmap.v1 line to the SAME append-only roadmap.jsonl ledger and
     * path the materializer / FileRoadmapStorePort use.
     *
     * @param  array<string,mixed>  $line
     */
    private function appendRoadmap(string $areaId, array $line): void
    {
        AppendOnlyJsonlStore::append($this->roadmapFilePath($areaId), $line);
    }

    private function roadmapFilePath(string $areaId): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($areaId)) ?: 'unknown_area';

        return $this->roadmapBaseDir().DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'roadmap.jsonl';
    }

    /**
     * Base dir for the roadmap.jsonl ledger. It lives in the SAME foundry tree as
     * the backlog ledger; when only the backlog dir is overridden (the AP-D test
     * seam and the thin CLI), the seed follows it so both ledgers stay co-located.
     * The slug-less FileRoadmapStorePort::setStorageDir() shares this exact base.
     */
    private function roadmapBaseDir(): string
    {
        return $this->roadmapStorageDirOverride
            ?? $this->backlogStorageDirOverride
            ?? (function_exists('storage_path') ? storage_path('atlas/foundry') : sys_get_temp_dir().'/atlas/foundry');
    }

    /**
     * The ONLY write AP-D performs: a single append to the factory_max backlog
     * JSONL. No Postgres, no parallel registry, no second store.
     *
     * @param  array<string,mixed>  $record
     */
    private function appendBacklog(string $areaId, array $record): void
    {
        AppendOnlyJsonlStore::append($this->backlogFilePath($areaId), $record);
    }

    private function backlogFilePath(string $areaId): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($areaId)) ?: 'unknown_area';

        $base = $this->backlogStorageDirOverride
            ?? (function_exists('storage_path') ? storage_path('atlas/foundry') : sys_get_temp_dir().'/atlas/foundry');

        return $base.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.'factory_max_promoted_backlog.jsonl';
    }

    /**
     * @param  array<string,mixed>  $reason  canonical blocker reason
     * @param  array<string,mixed>|null  $admission
     * @return array<string,mixed>
     */
    private function blocked(string $reason, ?array $admission = null): array
    {
        return [
            'status' => self::STATUS_BLOCKED,
            'admissible' => false,
            'blocker_reason' => $reason,
            'backlog_candidate' => null,
            'admission' => $admission,
            'promoted_finding_hash' => '',
            'promotion_receipt_hash' => '',
            'backlog_written' => false,
        ];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry) && $entry !== '') {
                $out[] = $entry;
            }
        }

        return array_values(array_unique($out));
    }
}
