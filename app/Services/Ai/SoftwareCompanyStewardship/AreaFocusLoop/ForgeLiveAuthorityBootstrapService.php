<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\AwisExecutionGatePort;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\AwisHandoffPackPort;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\ForgeLiveDecideReceiptPort;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\ForgeProviderTopologyPort;
use Throwable;

/**
 * AP-789 · Forge Live Decide + AWIS Authority Bootstrap.
 *
 * Prepares, for a REAL governed Obra, the live authority that AP-787/AP-788 need
 * to dispatch the Forge owner runtime. It DERIVES every piece from the real
 * services behind ports — it does not accept synthetic authority shape:
 *   - real provider topology ({@see AtlasForgeProviderTopologyService});
 *   - real live Atlas Decide decision receipt (id/hash);
 *   - real AWIS workspace readiness (execution gate + handoff pack).
 *
 * Honesty rules (no false positives):
 *   - Runtime authority is REAL or it blocks. `status=ready` can never be emitted
 *     from synthetic/operator-supplied JSON shape — only from real derivation.
 *   - It NEVER fabricates an Obra (forge_obra_required / forge_obra_invalid /
 *     forge_obra_not_found) and NEVER fabricates operator authorization
 *     (forge_operator_actor_required).
 *   - If a live Atlas Decide receipt cannot be produced it blocks with
 *     live_decide_receipt_required — never simulated.
 *   - If AWIS cannot release workspace readiness it returns precise blockers
 *     (awis_execution_gate_blocked / workspace_handoff_pack_blocked) and a
 *     next_action — never success.
 *   - It NEVER calls a provider driver router.
 *   - Mocks/Fakes/TestDoubles are forbidden in runtime; they may only stand in
 *     for the real ports inside unit tests and never cross into runtime,
 *     canonical docs or claim_policy as authority.
 *
 * Status: partial/blocked is the CORRECT result when real authority does not yet
 * exist. ready means real topology + real live decision + real AWIS readiness.
 */
final class ForgeLiveAuthorityBootstrapService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.ap789_forge_live_authority_bootstrap.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    /** @var list<string> */
    private const PRIMARY_BLOCKER_ORDER = [
        'forge_obra_required',
        'forge_obra_invalid',
        'forge_obra_not_found',
        'forge_topology_probe_failed',
        'forge_live_topology_unavailable',
        'forge_operator_actor_required',
        'live_decide_receipt_required',
        'awis_probe_failed',
        'awis_execution_gate_blocked',
        'workspace_handoff_pack_blocked',
    ];

    public function __construct(
        private readonly ForgeProviderTopologyPort $topologyPort,
        private readonly ForgeLiveDecideReceiptPort $decidePort,
        private readonly AwisExecutionGatePort $awisGate,
        private readonly AwisHandoffPackPort $handoffPack,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function bootstrap(array $input): array
    {
        $obraId = trim((string) ($input['forge_obra'] ?? $input['obra_id'] ?? ''));
        if ($obraId === '') {
            $blockers = ['forge_obra_required'];
            $nextActions = ['Supply a real governed Obra UUID via --forge-obra; AP-789 never fabricates one.'];

            return $this->report(
                self::STATUS_BLOCKED,
                '',
                'primary_builder',
                [],
                null,
                null,
                null,
                [],
                $blockers,
                $nextActions,
                false,
                $this->readinessChecks('', [], [], []),
                'forge_obra_required',
                $nextActions[0],
            );
        }
        if (! $this->isValidObraId($obraId)) {
            $blockers = ['forge_obra_invalid'];
            $nextActions = ['forge_obra must be a valid Obra UUID; AP-789 refuses fake/placeholder/zero identifiers.'];

            return $this->report(
                self::STATUS_BLOCKED,
                $obraId,
                'primary_builder',
                [],
                null,
                null,
                null,
                [],
                $blockers,
                $nextActions,
                false,
                $this->readinessChecks($obraId, [], [], []),
                'forge_obra_invalid',
                $nextActions[0],
            );
        }

        $role = $this->role($input);
        $actor = trim((string) ($input['forge_operator_actor'] ?? $input['actor'] ?? ''));
        $workspace = $this->stringOrNull($input['workspace'] ?? $input['worktree_path'] ?? null);
        $task = trim((string) ($input['task'] ?? 'AP-786 forge owner runtime dispatch')) ?: 'AP-786 forge owner runtime dispatch';

        $blockers = [];
        $nextActions = [];

        $topology = $this->resolveTopology($obraId, $role, $blockers, $nextActions);
        $decision = $this->resolveDecision($obraId, $actor, $task, $topology, $blockers, $nextActions);
        $awis = $this->resolveAwis($workspace, $task, $blockers, $nextActions);

        $topologyOk = (bool) ($topology['ok'] ?? false);
        $decisionOk = (bool) ($decision['ok'] ?? false);
        $awisOk = (bool) ($awis['ok'] ?? false);

        $forgeInputs = [];
        if ($topologyOk) {
            $forgeInputs['forge_live_topology'] = $topology['forge_live_topology'];
        }
        if ($decisionOk) {
            $forgeInputs['forge_live_decision'] = $decision['forge_live_decision'];
        }
        // SEC-004: emit the REAL AWIS readiness so the AP-787 dispatch seam can
        // re-check it. Without this, a status=partial bootstrap (topology+decision
        // ok, AWIS blocked) would still inject forge_inputs and let owner=forge
        // reach mutative provider-invoke on an uncertified workspace. The gate
        // only matters alongside live authority, so it rides with forge_inputs.
        if ($topologyOk && $decisionOk) {
            $forgeInputs['forge_awis_ready'] = $awisOk;
        }

        $status = match (true) {
            $topologyOk && $decisionOk && $awisOk => self::STATUS_READY,
            $topologyOk && $decisionOk => self::STATUS_PARTIAL,
            default => self::STATUS_BLOCKED,
        };

        $readinessChecks = $this->readinessChecks($obraId, $topology, $decision, $awis);
        $primaryBlocker = $this->primaryBlocker($blockers);
        $primaryNextAction = $this->primaryNextAction($primaryBlocker, $nextActions, $blockers);

        return $this->report(
            $status,
            $obraId,
            $role,
            $forgeInputs,
            $topology,
            $decision,
            $awis,
            $this->evidenceRefs($obraId, $topology, $decision, $awis),
            $blockers,
            $nextActions,
            $topologyOk && $decisionOk && $awisOk,
            $readinessChecks,
            $primaryBlocker,
            $primaryNextAction,
        );
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $nextActions
     * @return array<string,mixed>
     */
    private function resolveTopology(string $obraId, string $role, array &$blockers, array &$nextActions): array
    {
        try {
            $topo = $this->topologyPort->topology(['obra_id' => $obraId, 'role' => $role]);
        } catch (Throwable $e) {
            $blockers[] = 'forge_topology_probe_failed';
            $nextActions[] = 'Resolve the Forge provider topology probe error before bootstrap: '.$this->safe($e->getMessage());

            return ['ok' => false, 'source' => 'probe_failed', 'error' => $this->safe($e->getMessage())];
        }

        if ((bool) ($topo['obra_present'] ?? false) !== true) {
            $blockers[] = 'forge_obra_not_found';
            $nextActions[] = 'Register/create the governed Obra (AtlasProject) for '.$obraId.' before bootstrap; AP-789 never fabricates one.';

            return ['ok' => false, 'source' => 'topology', 'obra_present' => false, 'topology_status' => (string) ($topo['status'] ?? '')];
        }

        if ((bool) ($topo['runtime_dispatch_allowed'] ?? false) !== true) {
            $blockers[] = 'forge_live_topology_unavailable';
            $nextActions[] = (string) ($topo['next_action'] ?? 'Restore live Forge provider capacity/topology before bootstrap.');

            return [
                'ok' => false,
                'source' => 'topology',
                'topology_status' => (string) ($topo['status'] ?? ''),
                'topology_blockers' => $this->stringList($topo['blockers'] ?? []),
            ];
        }

        return [
            'ok' => true,
            'source' => 'topology',
            'forge_live_topology' => [
                'status' => 'live',
                'live' => true,
                'provider_topology_id' => (string) ($topo['provider_topology_id'] ?? ''),
                'decision_source' => (string) ($topo['decision_source'] ?? ''),
                'runtime_dispatch_allowed' => true,
                'role' => $role,
                'obra_id' => $obraId,
            ],
            'provider_topology_id' => (string) ($topo['provider_topology_id'] ?? ''),
            'decision_receipt_id' => $this->stringOrNull($topo['decision_receipt_id'] ?? null),
            'decision_receipt_hash' => $this->stringOrNull($topo['decision_receipt_hash'] ?? null),
            'decision_source' => (string) ($topo['decision_source'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $topology
     * @param  list<string>  $blockers
     * @param  list<string>  $nextActions
     * @return array<string,mixed>
     */
    private function resolveDecision(string $obraId, string $actor, string $task, array $topology, array &$blockers, array &$nextActions): array
    {
        if ($actor === '') {
            $blockers[] = 'forge_operator_actor_required';
            $nextActions[] = 'Supply --forge-operator-actor (or session actor); AP-789 never fabricates operator authorization.';

            return ['ok' => false, 'source' => 'missing_operator_actor'];
        }

        // Prefer the live receipt the real topology already resolved from Atlas Decide.
        $receiptId = $this->stringOrNull($topology['decision_receipt_id'] ?? null);
        $receiptHash = $this->stringOrNull($topology['decision_receipt_hash'] ?? null);
        $live = $receiptId !== null && (string) ($topology['decision_source'] ?? '') === 'live_atlas_decide';

        $probeError = null;

        if (! $live) {
            try {
                $receipt = $this->decidePort->receiptForTrace([
                    'task' => $task,
                    'obra_id' => $obraId,
                ], 'atlas_forge');
                $candidate = $this->stringOrNull($receipt['decision_id'] ?? null);
                if ($candidate !== null) {
                    $receiptId = $candidate;
                    $receiptHash = 'sha256:'.MissionCanonicalHash::sha256($receipt);
                    $live = true;
                }
            } catch (Throwable $e) {
                $probeError = $this->safe($e->getMessage());
                $nextActions[] = 'Atlas Decide receipt probe failed: '.$probeError;
            }
        }

        if (! $live || $receiptId === null) {
            $blockers[] = 'live_decide_receipt_required';
            $nextActions[] = 'Produce a real live Atlas Decide decision receipt (run with a live provider topology / fast-path decision) before forge dispatch; AP-789 never simulates a decision receipt.';

            return [
                'ok' => false,
                'source' => $probeError !== null ? 'decide_probe_failed' : 'no_live_decide_receipt',
                'probe_error' => $probeError,
            ];
        }

        return [
            'ok' => true,
            'source' => 'live_atlas_decide',
            'forge_live_decision' => [
                'decision' => 'dispatch_forge_owner_runtime',
                'operator_actor' => $actor,
                'decision_receipt_id' => $receiptId,
                'decision_receipt_hash' => $receiptHash,
                'decision_source' => 'live_atlas_decide',
                'obra_id' => $obraId,
            ],
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @param  list<string>  $nextActions
     * @return array<string,mixed>
     */
    private function resolveAwis(?string $workspace, string $task, array &$blockers, array &$nextActions): array
    {
        try {
            $gate = $this->awisGate->gate($workspace, 'execute', $task);
            $allowed = (bool) ($gate['allowed'] ?? false);
            $gateBlockers = $this->stringList($gate['blockers'] ?? []);
            $handoff = $this->handoffPack->build($workspace, $task, 'atlas_forge');
            $handoffReady = (string) ($handoff['status'] ?? '') === 'ready' || (bool) ($handoff['ready'] ?? false);
            $handoffBlockers = $this->resolveHandoffBlockers($handoff);
        } catch (Throwable $e) {
            $blockers[] = 'awis_probe_failed';
            $nextActions[] = 'Resolve the AWIS readiness probe error before bootstrap: '.$this->safe($e->getMessage());

            return ['ok' => false, 'source' => 'awis_probe_failed', 'error' => $this->safe($e->getMessage())];
        }

        if (! $allowed) {
            $blockers[] = 'awis_execution_gate_blocked';
            foreach ($gateBlockers as $g) {
                $blockers[] = 'awis_gate:'.$g;
            }
            $nextActions[] = 'Certify the AWIS workspace (run the AWIS workspace certification/readiness flow) before the Forge owner runtime can execute mutatively.';
        }
        if (! $handoffReady) {
            $blockers[] = 'workspace_handoff_pack_blocked';
            foreach ($handoffBlockers as $handoffBlocker) {
                $blockers[] = 'awis_handoff:'.$handoffBlocker;
            }
            $missingArtifacts = $this->stringList($handoff['missing_artifacts'] ?? []);
            $nextActions[] = $handoffBlockers !== []
                ? 'Build a ready AWIS workspace handoff pack (atlas_forge consumer) before forge dispatch; resolve handoff blockers: '.implode(', ', $handoffBlockers).'.'
                : ($missingArtifacts !== []
                    ? 'Build a ready AWIS workspace handoff pack (atlas_forge consumer) before forge dispatch; missing artifacts: '.implode(', ', $missingArtifacts).'.'
                    : 'Build a ready AWIS workspace handoff pack (atlas_forge consumer) before forge dispatch consumes the workspace.');
        }

        return [
            'ok' => $allowed && $handoffReady,
            'source' => 'awis',
            'allowed' => $allowed,
            'handoff_ready' => $handoffReady,
            'gate_blockers' => $gateBlockers,
            'handoff_blockers' => $handoffBlockers,
            'handoff_missing_artifacts' => $this->stringList($handoff['missing_artifacts'] ?? []),
            'handoff_consumer' => (string) ($handoff['consumer'] ?? 'atlas_forge'),
            'handoff_status' => (string) ($handoff['status'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $topology
     * @param  array<string,mixed>  $decision
     * @param  array<string,mixed>  $awis
     * @return list<string>
     */
    private function evidenceRefs(string $obraId, array $topology, array $decision, array $awis): array
    {
        $refs = ['obra:'.$obraId];
        $topoId = $this->stringOrNull($topology['provider_topology_id'] ?? null);
        if ($topoId !== null) {
            $refs[] = 'provider_topology:'.$topoId;
        }
        $receiptId = $this->stringOrNull(data_get($decision, 'forge_live_decision.decision_receipt_id'));
        if ($receiptId !== null) {
            $refs[] = 'atlas_decide_receipt:'.$receiptId;
        }
        if ((bool) ($awis['ok'] ?? false) === true) {
            $refs[] = 'awis_execution_gate:ready';
            $refs[] = 'awis_handoff_pack:ready';
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  array<string,mixed>  $topology
     * @param  array<string,mixed>  $decision
     * @param  array<string,mixed>  $awis
     * @return array<string,array<string,mixed>>
     */
    private function readinessChecks(string $obraId, array $topology, array $decision, array $awis): array
    {
        $topologyOk = (bool) ($topology['ok'] ?? false);
        $decisionOk = (bool) ($decision['ok'] ?? false);
        $gateAllowed = (bool) ($awis['allowed'] ?? false);
        $handoffReady = (bool) ($awis['handoff_ready'] ?? false);

        return [
            'forge_obra' => [
                'ok' => $obraId !== '' && $this->isValidObraId($obraId),
                'detail' => $obraId === '' ? 'missing' : ($this->isValidObraId($obraId) ? 'obra:'.$obraId : 'invalid:'.$obraId),
            ],
            'provider_topology' => [
                'ok' => $topologyOk,
                'detail' => $topologyOk
                    ? 'live_topology_ready'
                    : (string) ($topology['source'] ?? 'unavailable'),
                'topology_status' => (string) ($topology['topology_status'] ?? data_get($topology, 'forge_live_topology.status', '')),
                'topology_blockers' => $this->stringList($topology['topology_blockers'] ?? []),
                'probe_error' => $this->stringOrNull($topology['error'] ?? null),
            ],
            'live_decide_receipt' => [
                'ok' => $decisionOk,
                'detail' => $decisionOk
                    ? 'receipt:'.(string) data_get($decision, 'forge_live_decision.decision_receipt_id', '')
                    : (string) ($decision['source'] ?? 'no_live_receipt'),
                'decision_source' => (string) ($decision['source'] ?? ''),
                'probe_error' => $this->stringOrNull($decision['probe_error'] ?? null),
            ],
            'awis_execution_gate' => [
                'ok' => $gateAllowed && (string) ($awis['source'] ?? '') !== 'awis_probe_failed',
                'detail' => (string) ($awis['source'] ?? '') === 'awis_probe_failed'
                    ? 'awis_probe_failed'
                    : ($gateAllowed ? 'execution_gate_allowed' : 'execution_gate_blocked'),
                'gate_blockers' => $this->stringList($awis['gate_blockers'] ?? []),
                'probe_error' => (string) ($awis['source'] ?? '') === 'awis_probe_failed'
                    ? $this->stringOrNull($awis['error'] ?? null)
                    : null,
            ],
            'awis_handoff_pack' => [
                'ok' => $handoffReady,
                'detail' => $handoffReady ? 'handoff_pack_ready' : 'handoff_pack_blocked',
                'handoff_status' => (string) ($awis['handoff_status'] ?? ''),
                'handoff_consumer' => (string) ($awis['handoff_consumer'] ?? 'atlas_forge'),
                'handoff_blockers' => $this->stringList($awis['handoff_blockers'] ?? []),
                'missing_artifacts' => $this->stringList($awis['handoff_missing_artifacts'] ?? []),
            ],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $readinessChecks
     * @return array{passed:int,total:int,blocked_pillars:list<string>}
     */
    private function readinessSummary(array $readinessChecks): array
    {
        $passed = 0;
        $blockedPillars = [];

        foreach ($readinessChecks as $pillar => $check) {
            if ((bool) ($check['ok'] ?? false)) {
                $passed++;
            } else {
                $blockedPillars[] = (string) $pillar;
            }
        }

        return [
            'passed' => $passed,
            'total' => count($readinessChecks),
            'blocked_pillars' => $blockedPillars,
        ];
    }

    /**
     * Real AWIS handoff packs often expose missing_artifacts without blockers[].
     *
     * @param  array<string,mixed>  $handoff
     * @return list<string>
     */
    private function resolveHandoffBlockers(array $handoff): array
    {
        $blockers = $this->stringList($handoff['blockers'] ?? []);
        if ($blockers !== []) {
            return $blockers;
        }

        $missing = $this->stringList($handoff['missing_artifacts'] ?? []);
        if ($missing !== []) {
            return array_map(
                static fn (string $artifact): string => 'missing_'.$artifact,
                $missing,
            );
        }

        if ((string) ($handoff['status'] ?? '') === 'blocked') {
            return ['handoff_pack_not_ready'];
        }

        return [];
    }

    /**
     * @param  list<string>  $blockers
     */
    private function primaryBlocker(array $blockers): ?string
    {
        foreach (self::PRIMARY_BLOCKER_ORDER as $candidate) {
            if (in_array($candidate, $blockers, true)) {
                return $candidate;
            }
        }

        return $blockers[0] ?? null;
    }

    /**
     * @param  list<string>  $nextActions
     * @param  list<string>  $blockers
     */
    private function primaryNextAction(?string $primaryBlocker, array $nextActions, array $blockers = []): ?string
    {
        if ($nextActions === []) {
            return null;
        }

        if ($primaryBlocker === 'workspace_handoff_pack_blocked') {
            foreach ($nextActions as $action) {
                if (str_contains($action, 'handoff pack')) {
                    return $action;
                }
            }
        }

        if ($primaryBlocker === 'awis_execution_gate_blocked') {
            foreach ($nextActions as $action) {
                if (str_contains($action, 'Certify the AWIS workspace')) {
                    return $action;
                }
            }
        }

        if ($primaryBlocker === 'forge_topology_probe_failed') {
            foreach ($nextActions as $action) {
                if (str_contains($action, 'topology probe error')) {
                    return $action;
                }
            }
        }

        if ($primaryBlocker === 'live_decide_receipt_required') {
            foreach ($nextActions as $action) {
                if (str_contains($action, 'Atlas Decide receipt probe failed')) {
                    return $action;
                }
            }
        }

        if ($primaryBlocker === 'awis_probe_failed') {
            foreach ($nextActions as $action) {
                if (str_contains($action, 'AWIS readiness probe error')) {
                    return $action;
                }
            }
        }

        // next_actions are appended in pillar resolution order (topology -> decision -> awis).
        return $nextActions[0];
    }

    /**
     * @param  array<string,mixed>  $forgeInputs
     * @param  array<string,mixed>|null  $topology
     * @param  array<string,mixed>|null  $decision
     * @param  array<string,mixed>|null  $awis
     * @param  list<string>  $evidenceRefs
     * @param  list<string>  $blockers
     * @param  list<string>  $nextActions
     * @param  array<string,array<string,mixed>>  $readinessChecks
     * @return array<string,mixed>
     */
    private function report(string $status, string $obraId, string $role, array $forgeInputs, ?array $topology, ?array $decision, ?array $awis, array $evidenceRefs, array $blockers, array $nextActions, bool $ready = false, array $readinessChecks = [], ?string $primaryBlocker = null, ?string $primaryNextAction = null): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-789',
            'status' => $status,
            'forge_obra' => $obraId,
            'role' => $role,
            'ready_for_forge_owner_runtime' => $ready,
            'forge_inputs' => $forgeInputs,
            'topology' => $topology,
            'live_decision' => $decision,
            'awis' => $awis,
            'readiness_checks' => $readinessChecks,
            'readiness_summary' => $this->readinessSummary($readinessChecks),
            'primary_blocker' => $primaryBlocker,
            'primary_next_action' => $primaryNextAction,
            'evidence_refs' => $evidenceRefs,
            'blockers' => array_values(array_unique($blockers)),
            'next_actions' => array_values(array_unique($nextActions)),
            'fabricated_obra' => false,
            'provider_router_used' => false,
            'claim_policy' => [
                'mode' => 'forge_live_authority_bootstrap',
                'authority_must_be_real' => true,
                'ready_requires_real_topology_decision_and_awis' => true,
                'ready_from_synthetic_shape' => false,
                'fabricates_obra' => false,
                'fabricates_operator_authorization' => false,
                'simulates_decision_receipt' => false,
                'mocks_or_test_doubles_in_runtime' => false,
                'provider_router_invoked' => false,
                'direct_provider_driver_used' => false,
                'awis_readiness_required_for_mutative_execution' => true,
            ],
            'generated_at' => gmdate('c'),
        ];
    }

    private function isValidObraId(string $obraId): bool
    {
        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $obraId) !== 1) {
            return false;
        }
        $lower = strtolower($obraId);

        return ! str_contains($lower, 'fake')
            && ! str_contains($lower, 'placeholder')
            && $lower !== '00000000-0000-0000-0000-000000000000';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function role(array $input): string
    {
        $role = strtolower(trim((string) ($input['forge_role'] ?? 'primary_builder')));
        $canonical = [
            AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER,
            AtlasForgeProviderTopologyService::ROLE_CRITICAL_REVIEWER,
            AtlasForgeProviderTopologyService::ROLE_CONTEXT_SCOUT,
            AtlasForgeProviderTopologyService::ROLE_REPAIR_AGENT,
            AtlasForgeProviderTopologyService::ROLE_LOCAL_TOOL_RUNNER,
        ];

        return in_array($role, $canonical, true) ? $role : AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    private function safe(string $message): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $message)), 0, 200);
    }
}
