<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;

final class AtlasFrontendWorkOrderService
{
    public const SCHEMA_VERSION = 'atlas.frontend.work_order.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compile(array $input): array
    {
        $task = trim((string) ($input['task'] ?? ''));
        $workspace = trim((string) ($input['workspace'] ?? ''));
        $surface = trim((string) ($input['surface'] ?? 'programming.frontend')) ?: 'programming.frontend';
        $gauntlet = app(AtlasFrontendGauntletService::class)->run($input + [
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
        ]);
        $controlPlane = app(AtlasFrontendControlPlaneService::class)->snapshot($input + [
            'task' => $task,
            'workspace' => $workspace,
            'surface' => $surface,
        ]);
        $providerDispatchAllowed = (bool) data_get($gauntlet, 'claim_policy.provider_dispatch_allowed');
        $status = (in_array($gauntlet['status'] ?? null, ['ready', 'warning'], true) && $providerDispatchAllowed && ($controlPlane['status'] ?? null) !== 'blocked')
            ? 'ready'
            : 'blocked';
        $packets = $status === 'ready'
            ? $this->executionPackets($gauntlet)
            : $this->contextRepairPackets($gauntlet, $controlPlane);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'source' => self::class,
            'work_order_type' => 'company_frontend_execution_work_order',
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'task_spec_hash' => $gauntlet['task_spec_hash'] ?? null,
            'frontend_app_scope' => $gauntlet['frontend_app_scope'] ?? [
                'status' => 'repo_root',
                'relative_name' => null,
                'relative_name_hash' => null,
            ],
            'gauntlet_hash' => $gauntlet['gauntlet_hash'] ?? null,
            'control_plane_hash' => $controlPlane['control_plane_hash'] ?? null,
            'dispatch_policy' => [
                'provider_dispatch_allowed' => $status === 'ready',
                'forge_escalation_recommended' => $this->forgeEscalationRecommended($gauntlet, $controlPlane),
                'human_clarification_required' => $this->humanClarificationRequired($gauntlet, $controlPlane),
                'world_best_claim_allowed' => false,
                'raw_customer_source_returned' => false,
            ],
            'work_packets' => $packets,
            'required_next_actions' => $status === 'ready'
                ? $this->readyNextActions($packets)
                : array_values(array_unique(array_merge(
                    (array) ($gauntlet['required_next_actions'] ?? []),
                    (array) ($controlPlane['required_next_actions'] ?? []),
                ))),
            'claim_policy' => [
                'work_order_is_not_completion_evidence' => true,
                'completion_requires_run_certification_and_handoff' => true,
                'premium_claim_requires_all_packets_evidenced' => true,
                'world_best_claim_allowed' => false,
            ],
            'blockers' => $status === 'ready' ? [] : array_values(array_unique(array_merge(
                $this->phaseBlockers($gauntlet),
                (array) ($controlPlane['blockers'] ?? []),
            ))),
            'warnings' => array_values(array_unique((array) ($controlPlane['warnings'] ?? []))),
        ];
        $payload['work_order_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $gauntlet
     * @return array<int,array<string,mixed>>
     */
    private function executionPackets(array $gauntlet): array
    {
        $taskSpecHash = (string) ($gauntlet['task_spec_hash'] ?? '<task-spec-hash>');
        $frontendAppArg = data_get($gauntlet, 'frontend_app_scope.status') === 'subscope_selected'
            ? ' --frontend-app='.(string) data_get($gauntlet, 'frontend_app_scope.relative_name')
            : '';

        return [
            $this->packet('repo_context_lock', 10, 'Lock product context, repo map, design dossier and design system inventory before editing.', [
                'frontend_repo_intake',
                'company_design_dossier',
                'design_system_inventory',
                'product_blueprint',
            ], [
                'php artisan atlas:frontend:intake --workspace=<local-company-repo> --json --strict',
                'php artisan atlas:frontend:blueprint write --task="<brief>" --workspace=<local-company-repo> --json',
            ]),
            $this->packet('implementation_patch_or_prototype', 20, 'Implement the smallest product-correct UI patch or prototype without design-system drift.', [
                'changed_files_or_prototype_artifacts',
                'design_system_drift_report',
                'asset_pack_if_needed',
            ], [
                'php artisan atlas:frontend:design-system-drift inspect --report=<report> --json --strict',
                'php artisan atlas:frontend:detect --path=<changed-files-or-workspace> --strict --json',
            ]),
            $this->packet('visual_quality_verification', 30, 'Verify routes, states, viewports, accessibility, console, performance and anti-slop findings.', [
                'evidence_collection_kit',
                'scenario_matrix',
                'visual_quality_report',
                'quality_budget_report',
                'screenshots_by_route_viewport_state',
                'console_a11y_perf_receipts',
            ], [
                'php artisan atlas:frontend:evidence-kit prepare --task="<brief>" --workspace=<local-company-repo>'.$frontendAppArg.' --acceptance --output=<evidence-dir> --json --strict',
                'php artisan atlas:frontend:scenarios --task="<brief>" --workspace=<local-company-repo>'.$frontendAppArg.' --acceptance --json --strict',
                'php artisan atlas:frontend:visual-quality inspect --report=<report> --json --strict',
                'php artisan atlas:frontend:quality-budget inspect --report=<report> --json --strict',
            ]),
            $this->packet('certified_handoff', 40, 'Certify the run, record outcome memory and compile customer-safe handoff.', [
                'run_certification',
                'outcome_memory_record',
                'delivery_handoff',
            ], [
                'php artisan atlas:frontend:run-certify --provider-packet=<provider-packet> --visual-report=<report> --design-review-report=<report> --quality-budget-report=<report> --evidence-manifest=<manifest> --outcome-store=<jsonl> --json --strict',
                'php artisan atlas:frontend:handoff compile --run-certification=<report> --evidence-manifest=<manifest> --json --strict',
            ], $taskSpecHash),
        ];
    }

    /**
     * @param  array<string,mixed>  $gauntlet
     * @param  array<string,mixed>  $controlPlane
     * @return array<int,array<string,mixed>>
     */
    private function contextRepairPackets(array $gauntlet, array $controlPlane): array
    {
        $actions = array_values(array_unique(array_merge(
            (array) ($gauntlet['required_next_actions'] ?? []),
            (array) ($controlPlane['required_next_actions'] ?? []),
        )));

        return [
            [
                'id' => 'context_repair_before_provider_dispatch',
                'sequence' => 0,
                'status' => 'blocked',
                'objective' => 'Repair missing repo, design, acceptance, test, quality or evidence context before any provider edits.',
                'required_evidence' => ['ready_gauntlet', 'ready_repo_intake', 'ready_design_dossier'],
                'commands' => $gauntlet['recommended_command_sequence'] ?? [],
                'next_actions' => $actions,
                'claim_policy' => [
                    'provider_dispatch_allowed' => false,
                    'completion_claim_allowed' => false,
                ],
            ],
        ];
    }

    /**
     * @param  array<int,string>  $evidence
     * @param  array<int,string>  $commands
     * @return array<string,mixed>
     */
    private function packet(string $id, int $sequence, string $objective, array $evidence, array $commands, ?string $taskSpecHash = null): array
    {
        return [
            'id' => $id,
            'sequence' => $sequence,
            'status' => 'ready',
            'objective' => $objective,
            'task_spec_hash' => $taskSpecHash,
            'required_evidence' => $evidence,
            'commands' => $commands,
            'claim_policy' => [
                'packet_done_requires_evidence_refs' => true,
                'raw_customer_source_returned' => false,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $packets
     * @return array<int,string>
     */
    private function readyNextActions(array $packets): array
    {
        return collect($packets)
            ->map(fn (array $packet): string => 'execute_packet_'.$packet['id'])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $gauntlet
     * @return array<int,string>
     */
    private function phaseBlockers(array $gauntlet): array
    {
        return collect((array) ($gauntlet['phase_results'] ?? []))
            ->filter(fn (array $phase): bool => in_array($phase['status'] ?? null, ['blocked', 'failed', 'missing'], true) || (int) ($phase['blocker_count'] ?? 0) > 0)
            ->map(fn (array $phase): string => 'phase_blocked_'.(string) ($phase['id'] ?? 'unknown'))
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $gauntlet
     * @param  array<string,mixed>  $controlPlane
     */
    private function forgeEscalationRecommended(array $gauntlet, array $controlPlane): bool
    {
        return in_array('world_best_claim_not_allowed', (array) ($controlPlane['warnings'] ?? []), true)
            || collect((array) ($gauntlet['phase_results'] ?? []))->contains(fn (array $phase): bool => (int) ($phase['blocker_count'] ?? 0) > 2);
    }

    /**
     * @param  array<string,mixed>  $gauntlet
     * @param  array<string,mixed>  $controlPlane
     */
    private function humanClarificationRequired(array $gauntlet, array $controlPlane): bool
    {
        $actions = array_merge((array) ($gauntlet['required_next_actions'] ?? []), (array) ($controlPlane['required_next_actions'] ?? []));

        return collect($actions)->contains(fn (mixed $action): bool => is_string($action) && str_contains($action, 'acceptance'));
    }
}
