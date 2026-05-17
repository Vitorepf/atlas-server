<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals\Arms;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderModelRegistryService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsModeRegistry;

/**
 * Atlas Forge Rivals · Arm Contract.
 *
 * Resolves a concrete contract for a specific `arm_id + model + task_category`
 * tuple. Validates the inputs against the registry, attaches a clean
 * description of what the run will (and won't) be allowed to do, and maps
 * the choice onto the legacy RunBatteryService inputs (`atlas_model` /
 * `rival`).
 *
 * Contract guarantees (NEVER weakened by any flag combination):
 *   - safety_contract.never_promotes_completion_claim = true
 *   - safety_contract.never_unlocks_external_rivals_certification = true
 *   - requires_external_provider_call ⇒ requires 3 operator confirmations
 *   - not_yet_executable arms ⇒ honest blocker `arm_runner_not_yet_executable:<arm>`
 *   - placeholder arms ⇒ honest blocker `future_runner_is_placeholder_only`
 *   - task_category must be in registry.allowed_task_categories for the arm
 *
 * Schema: atlas.forge.rivals.arm_contract.v1
 */
final class AtlasForgeRivalsArmContractService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.arm_contract.v1';

    public function __construct(
        private readonly AtlasForgeRivalsArmRegistryService $registry,
        private readonly AtlasForgeRivalsProviderModelRegistryService $models,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array{
     *   schema_version:string,
     *   arm_role:string,
     *   arm:array<string,mixed>,
     *   resolved_model:?string,
     *   task_category:string,
     *   blockers:list<string>,
     *   legacy_model_id:?string,
     *   safety_contract:array<string,mixed>,
     *   external_provider_call:bool,
     *   note:string
     * }
     */
    public function contract(string $armRole, array $input): array
    {
        $armId = strtolower(trim((string) ($input['arm_id'] ?? '')));
        if ($armId === AtlasForgeRivalsArmRegistryService::ARM_ATLAS_DEV_LIGHT) {
            $armId = AtlasForgeRivalsArmRegistryService::ARM_ATLAS_DEV;
        }
        $model = strtolower(trim((string) ($input['model'] ?? '')));
        $taskCategory = strtolower(trim((string) ($input['task_category'] ?? '')));
        $mode = strtolower(trim((string) ($input['mode'] ?? '')));
        $dryRun = (bool) ($input['dry_run'] ?? false);
        $blockers = [];

        if (! in_array($armId, AtlasForgeRivalsArmRegistryService::ARMS, true)) {
            $blockers[] = $armRole.'_arm_unknown:'.$armId;

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'arm_role' => $armRole,
                'arm' => [],
                'resolved_model' => null,
                'task_category' => $taskCategory,
                'blockers' => $blockers,
                'legacy_model_id' => null,
                'safety_contract' => [],
                'external_provider_call' => false,
                'note' => 'Unknown arm id; refusing to continue.',
            ];
        }

        $arm = $this->registry->arm($armId);
        $safety = (array) $arm['safety_contract'];

        if (! in_array($taskCategory, AtlasForgeRivalsArmRegistryService::TASK_CATEGORIES, true)) {
            $blockers[] = 'task_category_unknown:'.$taskCategory;
        } else {
            $allowed = (array) $arm['allowed_task_categories'];
            if ($allowed !== [] && ! in_array($taskCategory, $allowed, true)) {
                $blockers[] = $armRole.'_task_category_not_supported_by_arm:'.$armId.':'.$taskCategory;
            }
        }

        if ($arm['status'] === AtlasForgeRivalsArmRegistryService::STATUS_PLACEHOLDER) {
            $blockers[] = 'future_runner_is_placeholder_only';
        }
        if ($arm['status'] === AtlasForgeRivalsArmRegistryService::STATUS_NOT_YET_EXECUTABLE
            && $mode !== AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE
            && ! $dryRun
        ) {
            $blockers[] = 'arm_runner_not_yet_executable:'.$armId;
        }

        $resolved = $this->resolveModel($arm, $model, $blockers);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'arm_role' => $armRole,
            'arm' => $arm,
            'provider' => $arm['provider'] ?? null,
            'requested_model' => $model,
            'resolved_model' => $resolved['canonical_model'],
            'resolved_model_id' => $resolved['model_id'],
            'resolved_model_label' => $resolved['model_label'],
            'task_category' => $taskCategory,
            'blockers' => array_values(array_unique($blockers)),
            'legacy_model_id' => $resolved['legacy_model_id'],
            'safety_contract' => $safety,
            'external_provider_call' => (bool) $arm['requires_external_provider_call'],
            'note' => $arm['status'] === AtlasForgeRivalsArmRegistryService::STATUS_AVAILABLE
                ? 'Arm contract resolved successfully.'
                : 'Arm declared but not fully executable in v1 — honest blocker applies for real runs.',
        ];
    }

    /**
     * @param  array<string,mixed>  $arm
     * @param  list<string>  $blockers
     * @return array{canonical_model:?string,model_id:?string,model_label:?string,legacy_model_id:?string}
     */
    private function resolveModel(array $arm, string $model, array &$blockers): array
    {
        $provider = (string) ($arm['provider'] ?? '');
        if ($provider === '') {
            return ['canonical_model' => null, 'model_id' => null, 'model_label' => null, 'legacy_model_id' => null];
        }

        $resolved = $this->models->resolve($provider, $model);
        foreach ($resolved['blockers'] as $blocker) {
            $blockers[] = 'arm_model_unknown:'.$arm['arm_id'].':'.$model;
            $blockers[] = $blocker;
        }

        return [
            'canonical_model' => $resolved['canonical_model'],
            'model_id' => $resolved['model_id'],
            'model_label' => $resolved['model_label'],
            'legacy_model_id' => $resolved['legacy_model_id'],
        ];
    }
}
