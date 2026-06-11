<?php

namespace App\Services\Ai\Programming\Forge;

use App\Services\Ai\Hermes\HermesAdapterReceipt;
use App\Services\Ai\Hermes\Kanban\HermesKanbanSwarmService;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Consumer call-path: dispatches a DECOMPOSED Forge obra (work packets) as a
 * DURABLE Hermes Kanban swarm (workers→verifier→synthesizer), via the governed
 * {@see HermesKanbanSwarmService}.
 *
 * This is an OPTIONAL backend, not a replacement for Forge's proven single-
 * provider execution path — it adds nothing to that hot path. Forge/Mission code
 * calls this explicitly when a multi-packet obra should run as a durable campaign.
 *
 * Triple fail-closed (ON TOP of the kanban service's own policy+confirm gate):
 *  - kanban.policy === 'atlas_adapter' (substrate enabled at all)
 *  - kanban.dispatch_for_forge === true (a dedicated consent for Forge routing,
 *    separate from `policy` so enabling the substrate never silently lets Forge
 *    spawn campaigns)
 *  - an explicit $confirm (live workers = token spend)
 * Any gate off ⇒ a sealed `blocked` receipt, no board, no spawn. Atlas composes
 * the worker cards (decomposition stays in Atlas); the kanban service owns the
 * ephemeral board lifecycle.
 */
class ForgeKanbanSwarmDispatcher
{
    use HermesAdapterReceipt;

    public function __construct(
        private readonly HermesKanbanSwarmService $kanban,
    ) {}

    /**
     * Map a Forge decomposition to a kanban swarm spec and dispatch it.
     *
     * @param  array<int,mixed>  $workPackets  AiForgeWorkPacket models or arrays with objective/role_slot
     * @param  array{confirm?:bool,permission_mode?:string,verifier?:string,synthesizer?:string,mission_id?:string,hermes_home?:string,timeout?:int}  $options
     * @return array<string,mixed>  sealed atlas.forge.kanban_dispatch.v1
     */
    public function dispatch(string $taskSummary, array $workPackets, array $options = []): array
    {
        $spec = $this->composeSpec($taskSummary, $workPackets, $options);
        $workerCount = is_array($spec['workers'] ?? null) ? count($spec['workers']) : 0;

        if (! $this->forgeDispatchAllowed()) {
            return $this->receipt($spec, $workerCount, 'blocked', $this->forgeBlockReason(), null);
        }
        if (! (bool) ($options['confirm'] ?? false)) {
            return $this->receipt($spec, $workerCount, 'blocked', 'confirm_required', null);
        }

        $run = $this->kanban->run($spec, true, array_filter([
            'hermes_home' => AiValueNormalizer::trimmedStringOrNull($options['hermes_home'] ?? null),
            'timeout' => isset($options['timeout']) ? (int) $options['timeout'] : null,
        ], static fn ($v): bool => $v !== null));

        return $this->receipt($spec, $workerCount, (string) ($run['aggregate_status'] ?? 'unknown'), $run['blocked_reason'] ?? null, $run);
    }

    /**
     * Pure preview: the kanban plan + masked argv this obra WOULD dispatch, with
     * no board and no spawn. Safe regardless of policy/confirm.
     *
     * @param  array<int,mixed>  $workPackets
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function preview(string $taskSummary, array $workPackets, array $options = []): array
    {
        $spec = $this->composeSpec($taskSummary, $workPackets, $options);

        return [
            'forge_dispatch_allowed' => $this->forgeDispatchAllowed(),
            'forge_block_reason' => $this->forgeDispatchAllowed() ? null : $this->forgeBlockReason(),
            'worker_count' => is_array($spec['workers'] ?? null) ? count($spec['workers']) : 0,
            'preview' => $this->kanban->previewArgv($spec),
        ];
    }

    /**
     * @param  array<int,mixed>  $workPackets
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function composeSpec(string $taskSummary, array $workPackets, array $options): array
    {
        $workers = [];
        foreach ($workPackets as $packet) {
            $title = AiValueNormalizer::trimmedStringOrNull(data_get($packet, 'objective') ?? data_get($packet, 'title'));
            if ($title === null) {
                continue;
            }
            $profile = AiValueNormalizer::trimmedStringOrNull(data_get($packet, 'role_slot') ?? data_get($packet, 'role')) ?? 'worker';
            $workers[] = ['profile' => $profile, 'title' => $title, 'skills' => []];
        }

        $mode = AiValueNormalizer::trimmedStringOrNull($options['permission_mode'] ?? null);

        return [
            'goal' => trim($taskSummary),
            'workers' => $workers,
            'verifier' => AiValueNormalizer::trimmedStringOrNull($options['verifier'] ?? null) ?? (string) config('atlas.ai.providers.hermes_cli.kanban.forge_verifier_profile', 'verifier'),
            'synthesizer' => AiValueNormalizer::trimmedStringOrNull($options['synthesizer'] ?? null) ?? (string) config('atlas.ai.providers.hermes_cli.kanban.forge_synthesizer_profile', 'synthesizer'),
            'permission_mode' => in_array($mode, ['read', 'write', 'danger'], true) ? $mode : 'read',
            'mission_id' => AiValueNormalizer::trimmedStringOrNull($options['mission_id'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>|null  $run
     * @return array<string,mixed>
     */
    private function receipt(array $spec, int $workerCount, string $status, ?string $blockedReason, ?array $run): array
    {
        return $this->withReceiptHash([
            'schema_version' => 'atlas.forge.kanban_dispatch.v1',
            'authority' => 'atlas',
            'hermes_kanban_can_decide' => false,
            'goal_hash' => $spec['goal'] !== '' ? hash('sha256', (string) $spec['goal']) : null,
            'worker_count' => $workerCount,
            'verifier' => $spec['verifier'] ?? null,
            'synthesizer' => $spec['synthesizer'] ?? null,
            'permission_mode' => $spec['permission_mode'] ?? 'read',
            'status' => $status,
            'dispatched' => $run !== null,
            'blocked_reason' => $blockedReason,
            'kanban_run' => $run,
        ]);
    }

    private function forgeDispatchAllowed(): bool
    {
        return config('atlas.ai.providers.hermes_cli.kanban.policy') === 'atlas_adapter'
            && (bool) config('atlas.ai.providers.hermes_cli.kanban.dispatch_for_forge', false);
    }

    private function forgeBlockReason(): string
    {
        return config('atlas.ai.providers.hermes_cli.kanban.policy') !== 'atlas_adapter'
            ? 'kanban_policy_off'
            : 'forge_dispatch_disabled';
    }

}
