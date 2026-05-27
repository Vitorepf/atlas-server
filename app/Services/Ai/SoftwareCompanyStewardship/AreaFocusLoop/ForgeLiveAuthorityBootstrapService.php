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
            return $this->report(self::STATUS_BLOCKED, '', 'primary_builder', [], null, null, null, [], ['forge_obra_required'], [
                'Supply a real governed Obra UUID via --forge-obra; AP-789 never fabricates one.',
            ]);
        }
        if (! $this->isValidObraId($obraId)) {
            return $this->report(self::STATUS_BLOCKED, $obraId, 'primary_builder', [], null, null, null, [], ['forge_obra_invalid'], [
                'forge_obra must be a valid Obra UUID; AP-789 refuses fake/placeholder/zero identifiers.',
            ]);
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

        $status = match (true) {
            $topologyOk && $decisionOk && $awisOk => self::STATUS_READY,
            $topologyOk && $decisionOk => self::STATUS_PARTIAL,
            default => self::STATUS_BLOCKED,
        };

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
                $nextActions[] = 'Atlas Decide receipt probe failed: '.$this->safe($e->getMessage());
            }
        }

        if (! $live || $receiptId === null) {
            $blockers[] = 'live_decide_receipt_required';
            $nextActions[] = 'Produce a real live Atlas Decide decision receipt (run with a live provider topology / fast-path decision) before forge dispatch; AP-789 never simulates a decision receipt.';

            return ['ok' => false, 'source' => 'no_live_decide_receipt'];
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
            $handoff = $this->handoffPack->build($workspace, $task, 'forge');
            $handoffReady = (string) ($handoff['status'] ?? '') === 'ready' || (bool) ($handoff['ready'] ?? false);
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
            $nextActions[] = 'Build a ready AWIS workspace handoff pack before forge dispatch consumes the workspace.';
        }

        return [
            'ok' => $allowed && $handoffReady,
            'source' => 'awis',
            'allowed' => $allowed,
            'handoff_ready' => $handoffReady,
            'gate_blockers' => $gateBlockers,
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
     * @param  array<string,mixed>  $forgeInputs
     * @param  array<string,mixed>|null  $topology
     * @param  array<string,mixed>|null  $decision
     * @param  array<string,mixed>|null  $awis
     * @param  list<string>  $evidenceRefs
     * @param  list<string>  $blockers
     * @param  list<string>  $nextActions
     * @return array<string,mixed>
     */
    private function report(string $status, string $obraId, string $role, array $forgeInputs, ?array $topology, ?array $decision, ?array $awis, array $evidenceRefs, array $blockers, array $nextActions, bool $ready = false): array
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
