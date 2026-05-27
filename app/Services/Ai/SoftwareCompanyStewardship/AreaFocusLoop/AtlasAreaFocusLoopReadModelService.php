<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Software Company Stewardship Stack · Area Focus Loop ·
 * Core Read-Only Runtime (AP-716).
 *
 * Atlas Software Company Stewardship Stack is a stack/capability family inside
 * the Atlas Autonomous Software Company Runtime, NOT a new OS.
 *
 * This is the first concrete read-only read-model of the Area Focus Loop. It
 * resolves a canonical area contract (first supported area: `agentic_engineering_os`),
 * resolves the area owner docs, computes readiness, emits coarse FINDING SEEDS
 * and a health summary, and routes deeper scanning, spec drafting and execution
 * to existing canonical owners.
 *
 * Boundary (no duplication):
 *   - Deep finding scan belongs to the Agentic Engineering OS Finding Engine (AP-717).
 *   - Gap/spec proposal belongs to the Self-Directed Evolution Layer.
 *   - Small/local execution belongs to Atlas Dev; long-horizon to Forge.
 *   - The Night Shift Product Mode control plane (AP-712) and the product surface
 *     (AP-721) are separate slices.
 * This service produces only the read-only CORE read-model + seeds; it does not
 * implement, duplicate or replace any of those owners.
 *
 * Hard guarantees: it NEVER writes state, NEVER invokes a provider, NEVER drafts
 * a spec, NEVER opens a branch/worktree, NEVER routes work for execution and
 * NEVER merges, deploys, accesses secrets or makes destructive changes.
 *
 * Runtime reconciliation (AP-786 / duplicate_runtime_risk · areafocusloop):
 * this class is the canonical AP-716 core read-model. The sibling AP-712 Night
 * Shift control plane ({@see AreaFocusLoopReadModelService}) composes this
 * service for owner-doc readiness; it does not re-declare the Area Contract.
 */
class AtlasAreaFocusLoopReadModelService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.area_focus_loop.v1';

    public const SEED_SCHEMA = 'atlas.software_company_stewardship.area_focus_finding_seed.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const SLICE_AP = 'AP-716';

    public const RUNTIME_AUTHORITY = 'core_read_model';

    public const DEFAULT_AREA_ID = AtlasNightShiftAreaFocusContractRegistry::AREA_AGENTIC_ENGINEERING_OS;

    public const SIBLING_CONTROL_PLANE = 'App\\Services\\Ai\\NightShift\\AreaFocusLoopReadModelService';

    /** Canonical finding sources this core read-model points seeds at (referenced, never imported/invoked here). */
    private const FINDING_ENGINE_AP717 = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AgenticEngineeringOsFindingEngineService.php';

    private const SELF_DIRECTED_EVOLUTION_DOC = 'docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md';

    public function __construct(
        private readonly AtlasNightShiftAreaFocusContractRegistry $registry,
    ) {}

    /**
     * Project the read-only Area Focus Loop core read-model for one canonical area.
     *
     * Optional `$input` keeps the projection deterministic and side-effect-free:
     *   - area_id:           string  area to resolve (default: agentic_engineering_os)
     *   - contract:          array   override the resolved area contract (test seam)
     *   - owner_doc_exists:  array<string,bool>  presence map override (test seam)
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array
    {
        $areaId = trim((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));

        $contract = $this->resolveContract($areaId, $input);
        if ($contract === null) {
            return $this->finalize($this->blockedUnregisteredArea($areaId));
        }

        $ownerDocsResolved = $this->resolveOwnerDocs($contract['owner_docs'], $input);
        $readiness = $this->readiness($ownerDocsResolved);
        $seeds = $this->findingSeeds($contract, $ownerDocsResolved);

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => $readiness['status'],
            'stewardship_stack' => $this->stewardshipStack(),
            'area_contract' => $contract,
            'area_map' => $this->areaMap($contract),
            'owner_docs_resolved' => $ownerDocsResolved,
            'readiness' => $readiness,
            'governed_mode' => $this->governedMode($contract),
            'finding_seed_count' => count($seeds),
            'finding_seeds' => $seeds,
            'health_summary' => $this->healthSummary($contract, $ownerDocsResolved, $seeds, $readiness),
            'evidence_refs' => $this->evidenceRefs($contract),
            'owner_reuse_matrix' => $this->ownerReuseMatrix(),
            'next_actions' => $this->nextActions($readiness),
            'claim_policy' => $this->claimPolicy(),
        ];

        return $this->finalize($payload);
    }

    /**
     * List the canonical area ids this read-model can resolve.
     *
     * @return list<string>
     */
    public function registeredAreaIds(): array
    {
        return $this->registry->registeredAreas();
    }

    // ---------- contract resolution ----------

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null
     */
    private function resolveContract(string $areaId, array $input): ?array
    {
        if (is_array($input['contract'] ?? null)) {
            return $input['contract'];
        }

        $resolved = $this->registry->resolve($areaId);

        return $resolved !== null ? $this->mapRegistryContract($resolved) : null;
    }

    /**
     * Project the AP-712 registry contract into the AP-716 core read-model shape.
     * The registry remains the single Area Contract authority; this mapper never
     * re-declares contract fields inline.
     *
     * @param  array<string,mixed>  $registryContract
     * @return array<string,mixed>
     */
    private function mapRegistryContract(array $registryContract): array
    {
        $wip = $registryContract['wip_limit'] ?? null;
        $maxBranches = is_array($wip) ? (int) ($wip['max_branches'] ?? 3) : (int) $wip;

        return [
            'area_id' => (string) ($registryContract['area_id'] ?? self::DEFAULT_AREA_ID),
            'area_name' => (string) ($registryContract['area_name'] ?? ''),
            'owner_docs' => array_values((array) ($registryContract['area_owner_docs'] ?? [])),
            'repo_scope' => [
                'repos' => array_values((array) ($registryContract['repo_scope']['repos'] ?? [])),
                'mode' => 'read_only_scan',
                'allowed_paths' => array_values((array) ($registryContract['repo_scope']['allowed_paths'] ?? [])),
                'forbidden_paths' => array_values((array) ($registryContract['repo_scope']['forbidden_paths'] ?? [])),
            ],
            'autonomy_tier' => (int) ($registryContract['autonomy_tier'] ?? 0),
            'dev_budget' => ['mode' => 'max_governed', 'units' => 'governed_capacity'],
            'forge_budget' => ['mode' => 'max_governed', 'units' => 'governed_capacity'],
            'wip_limit' => max(0, $maxBranches),
            'risk_policy' => [
                'mode' => 'max_governed',
                'inbox_only_domains' => array_values((array) ($registryContract['risk_policy']['sensitive_domains'] ?? [])),
                'no_merge_without_operator' => true,
                'no_deploy_without_operator' => true,
                'no_secret_access' => true,
                'no_destructive_change' => true,
            ],
            'stop_conditions' => array_values(array_unique(array_merge(
                (array) ($registryContract['stop_conditions'] ?? []),
                ['operator_kill_switch'],
            ))),
            'inbox_destination' => (string) ($registryContract['inbox_destination'] ?? 'morning_inbox'),
            'objective' => (string) ($registryContract['objective'] ?? ''),
            'covers' => [
                'aaeos', 'atlas_dev', 'forge', 'self_construction', 'evidence',
                'mission_control', 'branch_sandbox', 'replay', 'governance', 'desktop_surfaces',
            ],
        ];
    }

    /**
     * Resolve each owner doc to a presence flag (read-only filesystem check via a
     * seam that tests can override with `owner_doc_exists`).
     *
     * @param  list<string>  $ownerDocs
     * @param  array<string,mixed>  $input
     * @return list<array<string,mixed>>
     */
    private function resolveOwnerDocs(array $ownerDocs, array $input): array
    {
        $override = is_array($input['owner_doc_exists'] ?? null) ? $input['owner_doc_exists'] : null;

        $resolved = [];
        foreach ($ownerDocs as $doc) {
            $path = (string) $doc;
            $exists = $override !== null && array_key_exists($path, $override)
                ? (bool) $override[$path]
                : $this->ownerDocExists($path);
            $resolved[] = ['path' => $path, 'exists' => $exists];
        }

        return $resolved;
    }

    /**
     * Read-only existence seam (overridable in tests).
     */
    protected function ownerDocExists(string $relativePath): bool
    {
        $base = function_exists('base_path') ? base_path() : getcwd();

        return is_file(rtrim((string) $base, '/').'/'.ltrim($relativePath, '/'));
    }

    // ---------- readiness, seeds, health ----------

    /**
     * @param  list<array<string,mixed>>  $ownerDocsResolved
     * @return array<string,mixed>
     */
    private function readiness(array $ownerDocsResolved): array
    {
        $total = count($ownerDocsResolved);
        $present = count(array_filter($ownerDocsResolved, static fn (array $d): bool => ($d['exists'] ?? false) === true));
        $allPresent = $total > 0 && $present === $total;

        $status = match (true) {
            $total === 0 => self::STATUS_BLOCKED,
            $allPresent => self::STATUS_READY,
            default => self::STATUS_PARTIAL,
        };

        return [
            'status' => $status,
            'checks' => [
                'area_registered' => true,
                'owner_docs_total' => $total,
                'owner_docs_present' => $present,
                'owner_docs_all_present' => $allPresent,
                'finding_source_available' => true,
            ],
        ];
    }

    /**
     * Coarse, read-only finding seeds. AP-716 seeds point at WHERE deep findings
     * come from; it never runs a deep scan (that is AP-717's job) and never
     * drafts specs (that is Self-Directed Evolution's job).
     *
     * @param  array<string,mixed>  $contract
     * @param  list<array<string,mixed>>  $ownerDocsResolved
     * @return list<array<string,mixed>>
     */
    private function findingSeeds(array $contract, array $ownerDocsResolved): array
    {
        $areaId = (string) $contract['area_id'];
        $seeds = [];

        // 1. Missing owner docs are concrete, evidence-backed seeds.
        foreach ($ownerDocsResolved as $doc) {
            if (($doc['exists'] ?? false) === true) {
                continue;
            }
            $seeds[] = $this->seed(
                $areaId,
                'missing_owner_doc',
                'high',
                'Area owner doc is missing: '.(string) $doc['path'],
                'morning_inbox',
                [(string) $doc['path']],
            );
        }

        // 2. Pointer seeds at the canonical deep-scan owners (not executed here).
        $seeds[] = $this->seed(
            $areaId,
            'deep_finding_scan_available',
            'medium',
            'Deep area finding scan is owned by the Agentic Engineering OS Finding Engine (AP-717).',
            'finding_engine_ap717',
            [self::FINDING_ENGINE_AP717, 'AP-717'],
        );
        $seeds[] = $this->seed(
            $areaId,
            'gap_spec_proposal_available',
            'medium',
            'Canonical gap/spec proposal is owned by the Self-Directed Evolution Layer.',
            'self_directed_evolution',
            [self::SELF_DIRECTED_EVOLUTION_DOC],
        );

        return $this->sortSeeds($seeds);
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function seed(string $areaId, string $kind, string $severity, string $summary, string $recommendedOwner, array $evidenceRefs): array
    {
        $raw = hash('sha256', implode('|', [$areaId, $kind, $summary]));

        return [
            'schema_version' => self::SEED_SCHEMA,
            'seed_id' => 'afs_'.substr($raw, 0, 16),
            'seed_hash' => 'sha256:'.$raw,
            'area_id' => $areaId,
            'kind' => $kind,
            'severity' => $severity,
            'summary' => $summary,
            'recommended_owner' => $recommendedOwner,
            'evidence_refs' => $evidenceRefs,
            'is_seed' => true,
            'deep_scan_performed' => false,
            'requires_operator_review' => true,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $seeds
     * @return list<array<string,mixed>>
     */
    private function sortSeeds(array $seeds): array
    {
        $rank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        usort($seeds, static function (array $a, array $b) use ($rank): int {
            return (($rank[$b['severity']] ?? 0) <=> ($rank[$a['severity']] ?? 0))
                ?: (((string) $a['kind']) <=> ((string) $b['kind']))
                ?: (((string) $a['seed_hash']) <=> ((string) $b['seed_hash']));
        });

        return array_values($seeds);
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  list<array<string,mixed>>  $ownerDocsResolved
     * @param  list<array<string,mixed>>  $seeds
     * @param  array<string,mixed>  $readiness
     * @return array<string,mixed>
     */
    private function healthSummary(array $contract, array $ownerDocsResolved, array $seeds, array $readiness): array
    {
        $bySeverity = [];
        foreach ($seeds as $seed) {
            $sev = (string) ($seed['severity'] ?? 'unknown');
            $bySeverity[$sev] = ($bySeverity[$sev] ?? 0) + 1;
        }
        ksort($bySeverity);

        return [
            'area_id' => (string) $contract['area_id'],
            'readiness_status' => (string) $readiness['status'],
            'owner_docs_total' => count($ownerDocsResolved),
            'owner_docs_present' => count(array_filter($ownerDocsResolved, static fn (array $d): bool => ($d['exists'] ?? false) === true)),
            'finding_seed_count' => count($seeds),
            'finding_seeds_by_severity' => $bySeverity,
            'autonomy_tier_active' => 0,
            'autonomy_tier_declared' => (int) $contract['autonomy_tier'],
        ];
    }

    // ---------- maps, governed mode, evidence, reuse ----------

    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    private function areaMap(array $contract): array
    {
        return [
            'area_id' => $contract['area_id'],
            'area_name' => $contract['area_name'],
            'owner_docs' => $contract['owner_docs'],
            'repo_scope' => $contract['repo_scope'],
            'covers' => $contract['covers'] ?? [],
            'objective' => $contract['objective'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,mixed>
     */
    private function governedMode(array $contract): array
    {
        return [
            'mode' => 'max_governed',
            'definition' => 'maximum useful throughput inside the canonical safety boundary; not permissionless autonomy',
            'autonomy_tier_declared' => (int) $contract['autonomy_tier'],
            'autonomy_tier_active' => 0,
            'read_only_slice' => true,
            'invariants' => [
                'merge_without_operator' => false,
                'deploy_without_operator' => false,
                'secret_access' => false,
                'destructive_change' => false,
                'branch_isolation_required' => true,
                'budget_required' => true,
                'wip_limit_required' => true,
                'kill_switch_required' => true,
                'evidence_pack_required' => true,
                'morning_inbox_required' => true,
            ],
            'dev_budget' => $contract['dev_budget'],
            'forge_budget' => $contract['forge_budget'],
            'wip_limit' => $contract['wip_limit'],
            'stop_conditions' => $contract['stop_conditions'],
            'inbox_destination' => $contract['inbox_destination'] ?? 'morning_inbox',
        ];
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return list<string>
     */
    private function evidenceRefs(array $contract): array
    {
        $refs = [
            'docs/ap/AP-716-area-focus-loop-core-read-model-contract.md',
            'docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md',
            'docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md',
        ];
        foreach ((array) ($contract['owner_docs'] ?? []) as $doc) {
            if (is_string($doc) && $doc !== '') {
                $refs[] = $doc;
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @return list<string>
     */
    private function nextActions(array $readiness): array
    {
        $actions = [];
        if (($readiness['status'] ?? '') !== self::STATUS_READY) {
            $actions[] = 'Restore any missing owner docs so the area resolves to ready.';
        }
        $actions[] = 'Run the Agentic Engineering OS Finding Engine (AP-717) for a deep area scan.';
        $actions[] = 'Route gap/spec proposals to the Self-Directed Evolution Layer (operator-curated).';
        $actions[] = 'Surface seeds and findings in the Morning Inbox for operator decision; nothing auto-executes.';

        return $actions;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function ownerReuseMatrix(): array
    {
        return [
            'area_contract' => [
                'owner_service' => AtlasNightShiftAreaFocusContractRegistry::class,
                'reused_methods' => ['resolve'],
                'role' => 'canonical Area Contract source (AP-712 registry; not duplicated inline)',
            ],
            'finding_engine' => [
                'ap' => 'AP-717',
                'owner_file' => self::FINDING_ENGINE_AP717,
                'role' => 'deep area finding scan (referenced, not invoked by this core read-model)',
            ],
            'self_directed_evolution' => [
                'owner_doc' => self::SELF_DIRECTED_EVOLUTION_DOC,
                'role' => 'canonical gap/spec proposal (operator-curated)',
            ],
            'atlas_dev' => [
                'owner_doc' => 'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md',
                'role' => 'execution owner for small/local/verifiable work (recommendation only)',
            ],
            'forge' => [
                'owner_doc' => 'docs/engineering-knowledge-base/atlas-forge-operating-system.md',
                'role' => 'execution owner for long-horizon/cross-system work (recommendation only)',
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    private function stewardshipStack(): array
    {
        return [
            'stack' => 'Atlas Software Company Stewardship Stack',
            'level' => 'Area Focus Loop',
            'capability' => 'area_focus_loop_core_read_model',
            'parent_runtime' => 'Atlas Autonomous Software Company Runtime',
            'ap' => 'AP-716',
            'umbrella_doc' => 'docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md',
            'note' => 'Atlas Software Company Stewardship Stack is a stack/capability family inside the Atlas Autonomous Software Company Runtime, not a new OS.',
        ];
    }

    // ---------- blocked / finalize / policy ----------

    /**
     * @return array<string,mixed>
     */
    private function blockedUnregisteredArea(string $areaId): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'status' => self::STATUS_BLOCKED,
            'stewardship_stack' => $this->stewardshipStack(),
            'area_contract' => null,
            'finding_seed_count' => 0,
            'finding_seeds' => [],
            'blockers' => [[
                'reason' => 'area_not_registered',
                'detail' => "Area '{$areaId}' is not a registered canonical area. Registered: ".implode(', ', $this->registeredAreaIds()).'.',
                'area_id' => $areaId,
            ]],
            'owner_reuse_matrix' => $this->ownerReuseMatrix(),
            'next_actions' => ['Register the area contract before running the Area Focus Loop.'],
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * Stamp the deterministic report hash (excludes generated_at) then the
     * timestamp, exactly like the canonical read-model envelopes.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'writes_state' => false,
            'provider_invoked' => false,
            'runtime_authority_reconciled' => true,
            'canonical_core_read_model' => true,
            'slice_ap' => self::SLICE_AP,
            'runtime_authority' => self::RUNTIME_AUTHORITY,
            'sibling_control_plane' => self::SIBLING_CONTROL_PLANE,
            'parallel_runtime_created' => false,
            'parallel_finding_detector_created' => false,
            'new_os_created' => false,
            'deep_scan_performed' => false,
            'drafts_spec' => false,
            'creates_branch' => false,
            'routes_for_execution' => false,
            'merge_without_operator' => false,
            'deploy_without_operator' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'autoapproval_allowed' => false,
            'external_side_effect_allowed' => false,
            'requires_operator_review' => true,
            'evidence_required' => true,
            'morning_inbox_required' => true,
        ];
    }
}
