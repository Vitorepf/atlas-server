<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AtlasForge\AtlasForgeParallelDurableCoordinatorService;
use App\Services\Ai\Programming\AtlasForgeMultiNodeL410ProofService;
use App\Services\Ai\Programming\Forge\ForgeMultiAgentScheduleCanon;
use App\Services\Ai\Programming\Forge\ForgeMultiAgentSchedulerService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * L5-2: governed Loop -> Obra bridge preflight.
 *
 * This composes the handoff packet for multi-file Loop intents. It does not
 * execute Forge or certify L5-2 until the L4-10 real Obra receipt is green.
 */
final class AtlasLoopObraBridgeService
{
    public const SCHEMA_VERSION = 'atlas.loop.obra_bridge.v1';

    public const PACKET_SCHEMA_VERSION = 'atlas.loop.obra_bridge.packet.v1';

    public const DELIVERY_RECEIPT_SCHEMA_VERSION = 'atlas.loop.obra_bridge.delivery_receipt.v1';

    public const DEFAULT_RECEIPT_PATH = 'app/atlas/evidence/fable-l5-2-loop-obra-bridge.json';

    private const DONE_RECEIPT_STATUSES = ['done', 'completed', 'certified', 'success', 'succeeded'];

    public function __construct(
        private readonly ForgeMultiAgentSchedulerService $scheduler,
        private readonly AtlasForgeParallelDurableCoordinatorService $parallelDurable,
        private readonly AtlasForgeMultiNodeL410ProofService $l410Proof,
        private readonly AtlasSelfImprovementProposalBacklogService $proposalBacklog,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function bridge(array $options = []): array
    {
        $intent = $this->stringOrNull($options['intent'] ?? null)
            ?? 'Fable L5-2 Loop to Obra bridge candidate';
        $files = $this->pathList($options['files'] ?? []);
        $minFiles = max(2, (int) ($options['min_files'] ?? config('atlas.loop.obra_bridge.min_files', 2)));
        $forceMulti = (bool) ($options['force_multi_file'] ?? false);
        $isMultiFile = $forceMulti || count($files) >= $minFiles;
        $realEvidencePath = $this->stringOrNull($options['l4_10_evidence_path'] ?? null);
        $deliveryReceiptPath = $this->stringOrNull($options['delivery_receipt_path'] ?? null);

        $workPackets = $this->workPackets($files, $intent);
        $schedule = $this->scheduler->plan(
            taskSummary: $intent,
            workPackets: $workPackets,
            riskBand: ForgeMultiAgentScheduleCanon::RISK_HIGH,
            options: [
                'verification_required' => true,
                'reviewer_required' => true,
                'mission_id' => 'fable-lista-5-l5-2',
                'work_order_id' => 'loop-to-obra-bridge:'.hash('sha256', $intent.'|'.implode('|', $files)),
            ],
        );
        $parallelAssignment = $this->parallelDurable->propose(
            tickets: $this->ticketsFor($workPackets),
            agents: $this->agentsFor(max(1, count($workPackets))),
            existingReservations: [],
        );
        $l410 = $this->l410Proof->report([
            'evidence_path' => $realEvidencePath,
            'hours' => 24,
        ]);

        $blockers = $this->blockers($isMultiFile, $schedule, $l410);
        $status = $this->status($isMultiFile, $schedule, $l410, $blockers);
        $packet = $this->packet($intent, $files, $isMultiFile, $workPackets, $schedule, $parallelAssignment, $l410);
        $deliveryReceipt = $this->deliveryReceipt($deliveryReceiptPath, $packet);
        $deliveryCertified = $status === 'ready_for_operator_review' && (bool) ($deliveryReceipt['certified'] ?? false);
        if ($deliveryCertified) {
            $status = 'delivered';
        } elseif ($deliveryReceiptPath !== null && $status === 'ready_for_operator_review') {
            $status = 'delivery_receipt_rejected';
            $blockers = array_values(array_unique(array_merge($blockers, (array) ($deliveryReceipt['blockers'] ?? []))));
        }
        $createdProposal = null;
        if ((bool) ($options['create_proposal'] ?? false) && in_array($status, ['ready_for_operator_review', 'delivered'], true)) {
            $createdProposal = $this->proposalBacklog->createProposal([
                'proposal' => $this->proposalPayload($packet, $l410),
                'source' => 'postmortem',
                'affected_domains' => ['autonomous_evolution', 'programming'],
                'constraints' => [
                    'operator_approval_required',
                    'no_provider_call_from_bridge_preflight',
                    'forge_execution_receipt_required_before_completion_claim',
                ],
            ]);

            if ((bool) ($options['prioritize_proposal'] ?? true)) {
                $createdProposal = $this->proposalBacklog->prioritize((string) $createdProposal['proposal_id'], [
                    'strategy_bucket' => 'core_runtime',
                ]);
            }
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'scope' => [
                'campaign' => 'fable-listas-4-5-6',
                'item' => 'L5-2',
                'claim' => 'multi-file Loop intents can be packaged for Forge/Obra handoff, but execution remains operator-gated',
            ],
            'bridge_packet' => $packet,
            'l4_10_gate' => [
                'status' => (string) ($l410['status'] ?? 'unknown'),
                'certified' => (bool) ($l410['certified'] ?? false),
                'blockers' => array_values((array) ($l410['blockers'] ?? [])),
                'real_evidence_path' => $realEvidencePath,
            ],
            'operator_approval' => [
                'required' => true,
                'auto_execute_allowed' => false,
                'created_proposal_id' => is_array($createdProposal) ? ($createdProposal['proposal_id'] ?? null) : null,
                'created_proposal_status' => is_array($createdProposal) ? ($createdProposal['status'] ?? null) : null,
                'next_safe_action' => $status === 'delivered'
                    ? 'operator_reviews_delivered_obra_branch_before_any_merge'
                    : ($status === 'ready_for_operator_review'
                    ? 'operator_reviews_bridge_packet_then_authorizes_real_forge_execution'
                    : 'obtain_l4_10_real_execution_receipt_before_bridge_activation'),
            ],
            'created_backlog_proposal' => $createdProposal === null ? null : $this->summarizeCreatedProposal($createdProposal),
            'delivery_receipt' => $deliveryReceipt,
            'blockers' => $blockers,
            'claim_policy' => [
                'provider_dispatches_now' => false,
                'provider_tokens_spent' => false,
                'obra_created' => $deliveryCertified,
                'auto_execution_started' => false,
                'synthetic_completion_claim_allowed' => false,
                'l5_2_completion_claim_allowed' => $deliveryCertified,
                'real_multi_file_delivery_receipt_required' => true,
            ],
        ];

        if ((bool) ($options['write_receipt'] ?? false)) {
            $path = $this->stringOrNull($options['receipt_path'] ?? null) ?? storage_path(self::DEFAULT_RECEIPT_PATH);
            $this->writeJson($path, $payload);
            $payload['written_receipt_path'] = $path;
        }

        return $payload;
    }

    /**
     * @param  list<string>  $files
     * @return list<array<string,mixed>>
     */
    private function workPackets(array $files, string $intent): array
    {
        if ($files === []) {
            return [[
                'packet_id' => 'loop-obra-unscoped-intent',
                'objective' => $intent,
                'expected_files' => [],
                'dependencies' => [],
                'suggested_tests' => ['operator_supplied_acceptance_gate'],
            ]];
        }

        $packets = [];
        foreach ($files as $index => $file) {
            $packets[] = [
                'packet_id' => sprintf('loop-obra-file-%02d', $index + 1),
                'objective' => 'Deliver the Loop intent change for '.$file,
                'expected_files' => [$file],
                'dependencies' => $index === 0 ? [] : ['loop-obra-file-01'],
                'suggested_tests' => ['targeted test or command covering '.$file],
            ];
        }

        return $packets;
    }

    /**
     * @param  list<array<string,mixed>>  $workPackets
     * @return list<array{ticket_id:string,locked_paths:list<string>,priority:int}>
     */
    private function ticketsFor(array $workPackets): array
    {
        $tickets = [];
        foreach ($workPackets as $index => $packet) {
            $tickets[] = [
                'ticket_id' => (string) $packet['packet_id'],
                'locked_paths' => array_values((array) ($packet['expected_files'] ?? [])),
                'priority' => 100 - $index,
            ];
        }

        return $tickets;
    }

    /**
     * @return list<array{agent_id:string,available:bool}>
     */
    private function agentsFor(int $count): array
    {
        $agents = [];
        for ($i = 1; $i <= $count; $i++) {
            $agents[] = [
                'agent_id' => sprintf('l5-2-bridge-worker-%02d', $i),
                'available' => true,
            ];
        }

        return $agents;
    }

    /**
     * @return list<string>
     */
    private function blockers(bool $isMultiFile, array $schedule, array $l410): array
    {
        $blockers = [];
        if (! $isMultiFile) {
            $blockers[] = 'multi_file_intent_not_detected';
        }
        if (($schedule['status'] ?? null) !== ForgeMultiAgentScheduleCanon::STATUS_READY) {
            $blockers[] = 'forge_schedule_not_ready:'.((string) ($schedule['status'] ?? 'unknown'));
        }
        if (($l410['certified'] ?? false) !== true) {
            $blockers[] = 'l4_10_real_execution_receipt_required';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  list<string>  $blockers
     */
    private function status(bool $isMultiFile, array $schedule, array $l410, array $blockers): string
    {
        if (! $isMultiFile) {
            return 'not_bridge_candidate';
        }
        if (($l410['certified'] ?? false) !== true) {
            return 'blocked_by_l4_10_real_execution';
        }
        if (($schedule['status'] ?? null) !== ForgeMultiAgentScheduleCanon::STATUS_READY) {
            return 'blocked_by_forge_schedule';
        }

        return $blockers === [] ? 'ready_for_operator_review' : 'blocked';
    }

    /**
     * @param  list<string>  $files
     * @param  list<array<string,mixed>>  $workPackets
     * @return array<string,mixed>
     */
    private function packet(string $intent, array $files, bool $isMultiFile, array $workPackets, array $schedule, array $parallelAssignment, array $l410): array
    {
        $packet = [
            'schema_version' => self::PACKET_SCHEMA_VERSION,
            'intent_hash' => hash('sha256', $intent),
            'intent_summary' => $intent,
            'multi_file_detected' => $isMultiFile,
            'target_files' => $files,
            'work_packets' => $workPackets,
            'forge_schedule' => $schedule,
            'parallel_durable_assignment' => $parallelAssignment,
            'l4_10_gate' => [
                'certified' => (bool) ($l410['certified'] ?? false),
                'status' => (string) ($l410['status'] ?? 'unknown'),
            ],
            'execution_policy' => [
                'operator_approval_required' => true,
                'auto_execute' => false,
                'provider_dispatches_now' => false,
                'real_forge_execution_receipt_required' => true,
            ],
        ];
        $packet['packet_hash'] = hash('sha256', json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return $packet;
    }

    /**
     * @return array<string,mixed>
     */
    private function proposalPayload(array $packet, array $l410): array
    {
        return [
            'title' => 'Fable L5-2 Loop to Obra bridge: '.$packet['intent_summary'],
            'problem_statement' => 'A Loop intent spans multiple files and should graduate to a governed Forge/Obra handoff instead of a micro-diff.',
            'business_rule' => 'Multi-file Loop work may be packaged for Forge only after L4-10 real multi-node evidence is certified; operator approval is required before execution.',
            'target_capability' => 'loop_to_obra_bridge',
            'why_now' => 'Lista 5 moves Atlas from maintaining backlog items to composing larger changes while preserving the L4 gates.',
            'expected_power_gain' => 'multi_file_loop_intents_become_reviewable_forge_work_packets',
            'canonical_docs' => [
                'docs/fable-lista-5-14-itens.md',
                'docs/engineering-knowledge-base/atlas-programming-forge-flow.md',
            ],
            'allowed_paths' => array_values((array) ($packet['target_files'] ?? [])),
            'forbidden_paths' => [
                '.env',
                'database/migrations',
            ],
            'success_metrics' => [
                'bridge packet has multiple target files',
                'Forge schedule is ready',
                'L4-10 real evidence gate is certified',
                'provider execution remains operator-authorized',
            ],
            'acceptance_gates' => [
                'atlas:loop:obra-bridge --json --strict',
                'atlas:forge:l4-10-proof --evidence=<receipt.json> --json --strict',
            ],
            'risk_level' => ($l410['certified'] ?? false) === true ? 'high' : 'critical',
            'human_review_required' => true,
            'autopromotion_requested' => false,
            'requires_provider_cost_approval' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function deliveryReceipt(?string $path, array $packet): array
    {
        $loaded = $this->loadJson($path, self::DELIVERY_RECEIPT_SCHEMA_VERSION);
        if (($loaded['status'] ?? null) !== 'loaded') {
            return $loaded + [
                'certified' => false,
                'blockers' => ['real_multi_file_delivery_receipt_missing'],
                'checks' => [],
            ];
        }

        $payload = is_array($loaded['payload'] ?? null) ? $loaded['payload'] : [];
        $obra = $this->obraPayload($payload);
        $targetFiles = array_values(array_filter((array) ($packet['target_files'] ?? []), 'is_string'));
        $packetHash = $this->stringOrNull($packet['packet_hash'] ?? null);
        $intentHash = $this->stringOrNull($packet['intent_hash'] ?? null);
        $workOrderId = $this->stringOrNull(data_get($packet, 'forge_schedule.work_order_id'));
        $blockers = [];

        $schema = $this->firstString($payload, ['schema_version', 'schema']);
        if (! in_array($schema, [self::DELIVERY_RECEIPT_SCHEMA_VERSION, 'atlas.obra.commission.v1', 'atlas.obra.executor.v1'], true)) {
            $blockers[] = 'delivery_receipt_schema_unrecognized';
        }

        $linkedHash = $this->firstString($payload, ['bridge_packet_hash', 'bridge.packet_hash', 'packet_hash']);
        $linkedIntentHash = $this->firstString($payload, ['bridge_intent_hash', 'bridge.intent_hash', 'intent_hash']);
        $linkedWorkOrderId = $this->firstString($payload, ['bridge_work_order_id', 'bridge.work_order_id', 'work_order_id']);
        $bridgeLinkMatches = ($packetHash !== null && $linkedHash === $packetHash)
            || ($intentHash !== null && $linkedIntentHash === $intentHash)
            || ($workOrderId !== null && $linkedWorkOrderId === $workOrderId);
        if (! $bridgeLinkMatches) {
            $blockers[] = 'bridge_packet_hash_missing_or_mismatch';
        }

        $status = $this->firstString($obra, ['status']);
        if (! in_array($this->normalizeIdentifier($status), self::DONE_RECEIPT_STATUSES, true)) {
            $blockers[] = 'real_delivery_done_status_missing';
        }

        $certified = $this->truthyFirst($obra, ['certified']);
        if (! $certified) {
            $blockers[] = 'real_delivery_not_certified';
        }

        $nodeCount = $this->firstInt($obra, ['node_count', 'evidence.node_count']) ?? count((array) ($obra['plan'] ?? $obra['nodes'] ?? []));
        $deliveredNodes = $this->firstInt($obra, ['delivered_nodes']) ?? count(array_filter(
            (array) ($obra['plan'] ?? $obra['nodes'] ?? []),
            static fn (mixed $node): bool => is_array($node) && ($node['status'] ?? null) === 'done',
        ));
        if ($nodeCount < 2 || $deliveredNodes < 2) {
            $blockers[] = 'multi_file_delivery_nodes_missing';
        }

        $branch = $this->firstString($obra, ['branch']);
        if ($branch === null || ! str_starts_with($branch, 'atlas/obra/')) {
            $blockers[] = 'governed_obra_branch_missing';
        }
        if (! $this->truthyFirst($obra, ['never_merged'])) {
            $blockers[] = 'never_merged_evidence_missing';
        }
        if (! $this->truthyFirst($obra, ['never_pushed'])) {
            $blockers[] = 'never_pushed_evidence_missing';
        }

        $provider = $this->firstString($payload, ['provider.name', 'provider', 'runtime.provider'])
            ?? $this->firstProviderFromNodes($obra);
        if (! $this->isHermesProvider($provider)) {
            $blockers[] = 'hermes_cli_provider_evidence_missing';
        }
        $model = $this->firstString($payload, ['provider.model', 'model', 'runtime.model']);
        if ($model === null || ! str_contains(strtolower($model), 'gpt-5.5')) {
            $blockers[] = 'gpt_5_5_model_evidence_missing';
        }

        $mode = $this->firstString($payload, ['execution_mode', 'mode', 'runtime.execution_mode']);
        if ($mode === null || ! in_array($this->normalizeIdentifier($mode), ['real_provider_obra_run', 'real_multi_node_obra_run', 'operator_submitted_real_obra_run'], true)) {
            $blockers[] = 'real_execution_mode_missing';
        }

        $changedFiles = $this->changedFilesFromObra($obra);
        $intersection = array_values(array_intersect($targetFiles, $changedFiles));
        if (count($intersection) < min(2, count($targetFiles))) {
            $blockers[] = 'bridge_target_files_not_delivered';
        }

        $integrated = $this->integratedResult($obra);
        if (! (bool) ($integrated['supplied'] ?? false) || ! (bool) ($integrated['ran'] ?? false) || ! (bool) ($integrated['passed'] ?? false)) {
            $blockers[] = 'integrated_check_pass_evidence_missing';
        }

        return $loaded + [
            'certified' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'checks' => [
                'schema' => $schema,
                'bridge_packet_hash' => $linkedHash,
                'bridge_intent_hash' => $linkedIntentHash,
                'bridge_work_order_id' => $linkedWorkOrderId,
                'status' => $status,
                'certified' => $certified,
                'node_count' => $nodeCount,
                'delivered_nodes' => $deliveredNodes,
                'branch' => $branch,
                'provider' => $provider,
                'model' => $model,
                'execution_mode' => $mode,
                'changed_files' => $changedFiles,
                'bridge_target_file_intersection' => $intersection,
                'integrated_check' => $integrated,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadJson(?string $path, string $schemaVersion): array
    {
        if ($path === null) {
            return [
                'schema_version' => $schemaVersion,
                'status' => 'missing',
                'path' => null,
                'payload' => null,
            ];
        }
        if (! is_file($path)) {
            return [
                'schema_version' => $schemaVersion,
                'status' => 'missing',
                'path' => $path,
                'payload' => null,
                'load_error' => 'file_not_found',
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [
                'schema_version' => $schemaVersion,
                'status' => 'invalid',
                'path' => $path,
                'payload' => null,
                'load_error' => 'invalid_json',
            ];
        }

        return [
            'schema_version' => $decoded['schema_version'] ?? $decoded['schema'] ?? $schemaVersion,
            'status' => 'loaded',
            'path' => $path,
            'payload' => $decoded,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function obraPayload(array $payload): array
    {
        foreach (['obra', 'output', 'result', 'execution'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                return (array) $payload[$key];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $obra
     * @return list<string>
     */
    private function changedFilesFromObra(array $obra): array
    {
        $files = [];
        foreach (['changed_files', 'delivered_files'] as $key) {
            foreach ($this->pathList($obra[$key] ?? []) as $path) {
                $files[$path] = true;
            }
        }
        foreach (['plan', 'nodes', 'evidence.nodes', 'certification.nodes'] as $path) {
            foreach ((array) data_get($obra, $path, []) as $node) {
                if (! is_array($node)) {
                    continue;
                }
                foreach ($this->pathList($node['files_changed'] ?? []) as $file) {
                    $files[$file] = true;
                }
            }
        }

        return array_keys($files);
    }

    /**
     * @param  array<string,mixed>  $obra
     * @return array<string,mixed>
     */
    private function integratedResult(array $obra): array
    {
        $integrated = data_get($obra, 'integrated_test_result');
        if (! is_array($integrated)) {
            $integrated = data_get($obra, 'evidence.integrated_test_result');
        }

        return is_array($integrated) ? $integrated : [];
    }

    /**
     * @param  array<string,mixed>  $obra
     */
    private function firstProviderFromNodes(array $obra): ?string
    {
        foreach (['evidence.nodes', 'certification.nodes', 'nodes', 'plan'] as $path) {
            foreach ((array) data_get($obra, $path, []) as $node) {
                if (is_array($node) && is_string($node['provider'] ?? null) && trim((string) $node['provider']) !== '') {
                    return trim((string) $node['provider']);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $paths
     */
    private function truthyFirst(array $payload, array $paths): bool
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if ($value === true || $value === 1 || $value === '1') {
                return true;
            }
            if (is_string($value) && in_array(strtolower(trim($value)), ['true', 'yes'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $paths
     */
    private function firstString(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $paths
     */
    private function firstInt(array $payload, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function normalizeIdentifier(?string $value): string
    {
        return strtolower(str_replace(['-', ' '], '_', trim((string) $value)));
    }

    private function isHermesProvider(?string $provider): bool
    {
        $normalized = $this->normalizeIdentifier($provider);

        return $normalized === 'hermes_cli' || $normalized === 'hermes';
    }

    /**
     * @return array<string,mixed>
     */
    private function summarizeCreatedProposal(array $proposal): array
    {
        return [
            'proposal_id' => $proposal['proposal_id'] ?? null,
            'status' => $proposal['status'] ?? null,
            'title' => $proposal['title'] ?? null,
            'proposal_packet_status' => data_get($proposal, 'proposal_packet.status'),
            'priority_score' => $proposal['priority_score'] ?? null,
            'linked_obra_id' => $proposal['linked_obra_id'] ?? null,
            'external_provider_call' => (bool) ($proposal['external_provider_call'] ?? false),
            'provider_tokens_spent' => (bool) ($proposal['provider_tokens_spent'] ?? false),
            'auto_fast_path_executed' => (bool) ($proposal['auto_fast_path_executed'] ?? false),
        ];
    }

    /**
     * @return list<string>
     */
    private function pathList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        $paths = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '') {
                $paths[] = $item;
            }
        }

        return array_values(array_unique($paths));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function writeJson(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }
}
