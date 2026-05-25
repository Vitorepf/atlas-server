<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;

final class AtlasFrontendExecutionGateService
{
    public const SCHEMA_VERSION = 'atlas.frontend.execution_gate.v1';

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function evaluate(array $options = []): array
    {
        $task = trim((string) ($options['task'] ?? ''));
        $workspace = trim((string) ($options['workspace'] ?? ''));
        $profilePath = trim((string) ($options['company_profile'] ?? ''));
        $taskSpec = app(AtlasFrontendTaskSpecCompilerService::class)->compile([
            'task' => $task,
            'surface' => (string) ($options['surface'] ?? 'programming.frontend'),
            'workspace' => $workspace,
            'acceptance' => (bool) ($options['acceptance_criteria'] ?? false),
            'asset_context' => (bool) ($options['asset_context'] ?? false),
            'company_profile' => $profilePath !== '' || (bool) ($options['company_profile_ready'] ?? false),
            'prototype' => (bool) ($options['prototype'] ?? false),
            'live' => (bool) ($options['live'] ?? false),
        ]);
        $contract = app(AtlasFrontendDesignRuntimeService::class)->contract([
            'task' => $task,
            'workspace' => $workspace,
            'surface' => (string) ($options['surface'] ?? 'programming.frontend'),
            'acceptance_criteria' => (bool) ($options['acceptance_criteria'] ?? false),
        ]);

        $inventory = $this->inventory($workspace);
        $profile = $profilePath !== '' ? app(AtlasFrontendCompanyDesignProfileService::class)->inspect($profilePath) : null;
        $blockers = [];
        $warnings = [];
        $declaredTaskSpecHash = trim((string) ($options['task_spec_hash'] ?? ''));

        if ($task === '') {
            $blockers[] = $this->issue('task_missing', 'Frontend execution needs a concrete task or user intent.');
        }
        if (! (bool) ($options['acceptance_criteria'] ?? false)) {
            $blockers[] = $this->issue('acceptance_criteria_missing', 'Frontend execution needs acceptance criteria before provider or patch work.');
        }
        if (! (bool) ($options['test_plan'] ?? false)) {
            $blockers[] = $this->issue('test_plan_missing', 'Frontend code changes need a test or verification plan.');
        }
        if (! (bool) ($options['visual_quality_plan'] ?? false)) {
            $blockers[] = $this->issue('visual_quality_plan_missing', 'Frontend work needs a visual quality plan before execution.');
        }
        if (! (bool) ($options['evidence_plan'] ?? false)) {
            $blockers[] = $this->issue('evidence_plan_missing', 'Frontend work needs expected evidence outputs before execution.');
        }

        foreach ((array) ($contract['blockers'] ?? []) as $contractBlocker) {
            $blockers[] = $this->issue(
                'runtime_contract_'.$this->slug((string) ($contractBlocker['id'] ?? 'blocked')),
                (string) ($contractBlocker['reason'] ?? 'Atlas Frontend runtime contract blocked execution.'),
            );
        }
        foreach ((array) ($taskSpec['blockers'] ?? []) as $taskSpecBlocker) {
            $blockers[] = $this->issue(
                'task_spec_'.$this->slug((string) ($taskSpecBlocker['id'] ?? 'blocked')),
                (string) ($taskSpecBlocker['reason'] ?? 'Atlas Frontend task spec blocked execution.'),
            );
        }
        if ($declaredTaskSpecHash !== '' && ! preg_match('/^[a-f0-9]{64}$/', strtolower($declaredTaskSpecHash))) {
            $blockers[] = $this->issue('task_spec_hash_invalid', 'Declared task spec hash must be a 64-character sha256 hex string.');
        }
        if ($declaredTaskSpecHash !== '' && preg_match('/^[a-f0-9]{64}$/', strtolower($declaredTaskSpecHash)) && $declaredTaskSpecHash !== ($taskSpec['task_spec_hash'] ?? null)) {
            $blockers[] = $this->issue('task_spec_hash_mismatch', 'Declared task spec hash does not match the canonical frontend task spec.');
        }

        $broadOrEnterprise = (bool) data_get($contract, 'signals.broad_visual_change')
            || (bool) data_get($contract, 'signals.enterprise_multi_company');
        $profileReady = is_array($profile) && in_array((string) ($profile['status'] ?? ''), ['ready', 'partial'], true);
        $inventoryReady = is_array($inventory) && ($inventory['status'] ?? null) === 'ready';

        if ($broadOrEnterprise && ! $inventoryReady && ! $profileReady) {
            $blockers[] = $this->issue('design_system_context_missing', 'Broad or multi-company frontend work needs a ready design-system inventory or company profile.');
        }
        if ((bool) data_get($contract, 'signals.enterprise_multi_company') && ! $profileReady) {
            $blockers[] = $this->issue('company_design_profile_required', 'Multi-company frontend work needs an inspected company design profile.');
        }
        if ($broadOrEnterprise && ! (bool) ($options['senior_design_review'] ?? false)) {
            $blockers[] = $this->issue('senior_design_review_missing', 'Broad visual or multi-company work needs senior design review before execution.');
        }
        if (($inventory['status'] ?? null) === 'partial') {
            $warnings[] = $this->issue('design_system_inventory_sparse', 'Inventory is sparse; use company profile or design refs before visual claims.');
        }
        if (is_array($profile) && ($profile['status'] ?? null) === 'blocked') {
            $blockers[] = $this->issue('company_design_profile_blocked', 'Company design profile exists but is not usable.');
        }

        $blockers = $this->uniqueIssues($blockers);
        $warnings = $this->uniqueIssues($warnings);
        $status = $blockers === [] ? ($warnings === [] ? 'passed' : 'warning') : 'blocked';
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'execution_allowed' => $status !== 'blocked',
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'task_spec' => $this->summarizeTaskSpec($taskSpec, $declaredTaskSpecHash),
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'runtime_contract_hash' => $contract['contract_hash'] ?? null,
            'inventory' => $this->summarizeInventory($inventory),
            'company_profile' => $this->summarizeProfile($profile),
            'required_gates' => $contract['required_gates'] ?? [],
            'required_evidence' => $contract['required_evidence'] ?? [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'required_next_actions' => $this->requiredNextActions($blockers),
            'claim_policy' => [
                'provider_dispatch_allowed' => $status !== 'blocked',
                'provider_dispatch_requires_matching_task_spec_hash' => true,
                'completion_claim_allowed' => false,
                'visual_completion_still_requires_visual_quality_gate' => true,
                'world_best_claim_allowed' => false,
                'raw_task_or_customer_source_returned' => false,
            ],
        ];
        $payload['gate_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $taskSpec
     * @return array<string,mixed>
     */
    private function summarizeTaskSpec(array $taskSpec, string $declaredTaskSpecHash): array
    {
        return [
            'schema_version' => $taskSpec['schema_version'] ?? null,
            'status' => $taskSpec['status'] ?? 'unknown',
            'task_spec_hash' => $taskSpec['task_spec_hash'] ?? null,
            'declared_task_spec_hash' => $declaredTaskSpecHash !== '' ? $declaredTaskSpecHash : null,
            'declared_hash_matches' => $declaredTaskSpecHash === '' ? null : $declaredTaskSpecHash === ($taskSpec['task_spec_hash'] ?? null),
            'ambiguity_level' => $taskSpec['ambiguity_level'] ?? null,
            'risk_level' => $taskSpec['risk_level'] ?? null,
            'task_types' => $taskSpec['task_types'] ?? [],
            'route_count' => count((array) ($taskSpec['routes'] ?? [])),
            'viewport_count' => count((array) ($taskSpec['viewports'] ?? [])),
            'state_count' => count((array) ($taskSpec['states'] ?? [])),
            'raw_task_returned' => $taskSpec['raw_task_returned'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function inventory(string $workspace): ?array
    {
        if ($workspace === '' || ! File::isDirectory($workspace)) {
            return null;
        }

        return app(AtlasFrontendDesignSystemInventoryService::class)->inspect($workspace);
    }

    /**
     * @param  array<string,mixed>|null  $inventory
     * @return array<string,mixed>
     */
    private function summarizeInventory(?array $inventory): array
    {
        if ($inventory === null) {
            return ['status' => 'missing'];
        }

        return [
            'schema_version' => $inventory['schema_version'] ?? null,
            'status' => $inventory['status'] ?? 'unknown',
            'inventory_hash' => $inventory['inventory_hash'] ?? null,
            'file_count' => data_get($inventory, 'scanned.file_count'),
            'component_count' => data_get($inventory, 'components.count'),
            'raw_source_returned' => data_get($inventory, 'scanned.raw_source_returned'),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $profile
     * @return array<string,mixed>
     */
    private function summarizeProfile(?array $profile): array
    {
        if ($profile === null) {
            return ['status' => 'missing'];
        }

        return [
            'schema_version' => $profile['schema_version'] ?? null,
            'status' => $profile['status'] ?? 'unknown',
            'profile_hash' => $profile['profile_hash'] ?? null,
            'can_drive_multi_company_frontend' => data_get($profile, 'readiness.can_drive_multi_company_frontend'),
            'can_claim_brand_adaptation' => data_get($profile, 'readiness.can_claim_brand_adaptation'),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function issue(string $id, string $reason): array
    {
        return ['id' => $id, 'reason' => $reason];
    }

    /**
     * @param  array<int,array<string,string>>  $issues
     * @return array<int,array<string,string>>
     */
    private function uniqueIssues(array $issues): array
    {
        return collect($issues)->unique('id')->values()->all();
    }

    /**
     * @param  array<int,array<string,string>>  $blockers
     * @return array<int,string>
     */
    private function requiredNextActions(array $blockers): array
    {
        return collect($blockers)
            ->map(fn (array $blocker): string => match ($blocker['id']) {
                'task_missing' => 'provide_frontend_task',
                'acceptance_criteria_missing' => 'provide_acceptance_criteria',
                'test_plan_missing' => 'provide_test_or_verification_plan',
                'visual_quality_plan_missing' => 'declare_visual_quality_plan',
                'evidence_plan_missing' => 'declare_expected_evidence',
                'task_spec_acceptance_context_required' => 'compile_task_spec_with_acceptance_context',
                'task_spec_asset_context_required' => 'compile_task_spec_with_asset_context',
                'task_spec_hash_invalid', 'task_spec_hash_mismatch' => 'recompile_or_attach_matching_task_spec_hash',
                'design_system_context_missing' => 'run_atlas_frontend_inventory_or_attach_company_profile',
                'company_design_profile_required', 'company_design_profile_blocked' => 'inspect_ready_company_design_profile',
                'senior_design_review_missing' => 'obtain_senior_design_review',
                default => 'resolve_'.$blocker['id'],
            })
            ->unique()
            ->values()
            ->all();
    }

    private function slug(string $value): string
    {
        return str_replace('-', '_', str($value)->snake()->toString());
    }
}
