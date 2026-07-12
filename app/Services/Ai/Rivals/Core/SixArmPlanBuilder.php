<?php

namespace App\Services\Ai\Rivals\Core;

use InvalidArgumentException;

/**
 * Build the canonical six-arm comparison plan for a trial unit.
 *
 * Arms (where available): same model bare, same model with Atlas, strongest
 * competitor in its native harness, frontier model bare, accepted historical human
 * artifact, and Atlas full-power. A model that is not usable — or an absent human
 * artifact — is recorded as `unavailable` (an explicit ineligibility) and never
 * blocks the arms that CAN run. Reuses ArmRegistry; adds no execution.
 */
final class SixArmPlanBuilder
{
    public const SCHEMA = 'atlas.rivals2.six_arm_plan.v1';

    public const ROLE_SAME_MODEL_BARE = 'same_model_bare';

    public const ROLE_SAME_MODEL_ATLAS = 'same_model_atlas';

    public const ROLE_COMPETITOR_NATIVE = 'competitor_native';

    public const ROLE_FRONTIER_BARE = 'frontier_bare';

    public const ROLE_HISTORICAL_HUMAN = 'historical_human_baseline';

    public const ROLE_ATLAS_FULL_POWER = 'atlas_full_power';

    public function __construct(private readonly ArmRegistry $arms = new ArmRegistry) {}

    /**
     * @param  array<string,mixed>  $spec  same_model (req), atlas_runtime, competitor_model,
     *                                     competitor_runtime, frontier_model,
     *                                     historical_human_artifact, suite_id
     * @return array{schema_version:string, arms:list<array<string,mixed>>, unavailable:list<array{arm_role:string,reason:string}>}
     */
    public function build(array $spec): array
    {
        $sameModel = (string) ($spec['same_model'] ?? '');
        if ($sameModel === '') {
            throw new InvalidArgumentException('six_arm_same_model_required');
        }
        $suiteId = $spec['suite_id'] ?? null;
        $atlasRuntime = (string) ($spec['atlas_runtime'] ?? 'atlas_dev');

        $targets = [
            [self::ROLE_SAME_MODEL_BARE, $sameModel, 'bare'],
            [self::ROLE_SAME_MODEL_ATLAS, $sameModel, $atlasRuntime],
            [self::ROLE_COMPETITOR_NATIVE, $spec['competitor_model'] ?? null, (string) ($spec['competitor_runtime'] ?? 'bare')],
            [self::ROLE_FRONTIER_BARE, $spec['frontier_model'] ?? null, 'bare'],
            [self::ROLE_ATLAS_FULL_POWER, $sameModel, $atlasRuntime],
        ];

        $arms = [];
        $unavailable = [];
        foreach ($targets as [$role, $modelId, $runtime]) {
            if (! is_string($modelId) || $modelId === '') {
                $unavailable[] = ['arm_role' => $role, 'reason' => 'model_not_provided'];

                continue;
            }
            try {
                $arm = $this->arms->makeArm($modelId, $runtime, $suiteId);
            } catch (InvalidArgumentException $e) {
                $unavailable[] = ['arm_role' => $role, 'reason' => $e->getMessage()];

                continue;
            }
            $arm['arm_role'] = $role;
            $arm['provider_version'] = $this->providerVersion($modelId);
            // full-power shares model+runtime with the atlas arm; keep arm_id unique
            if ($role === self::ROLE_ATLAS_FULL_POWER) {
                $arm['arm_id'] .= '#full_power';
                $arm['full_power'] = true;
            }
            $arms[] = $arm;
        }

        $artifact = $spec['historical_human_artifact'] ?? null;
        if (is_array($artifact) && (string) ($artifact['artifact_sha'] ?? '') !== '') {
            $arms[] = [
                'arm_id' => 'historical_human@artifact',
                'arm_role' => self::ROLE_HISTORICAL_HUMAN,
                'model_id' => 'historical_human',
                'runtime' => 'artifact',
                'artifact_sha' => (string) $artifact['artifact_sha'],
                'provider_version' => 'human_artifact',
            ];
        } else {
            $unavailable[] = ['arm_role' => self::ROLE_HISTORICAL_HUMAN, 'reason' => 'no_accepted_human_artifact'];
        }

        return [
            'schema_version' => self::SCHEMA,
            'arms' => $arms,
            'unavailable' => $unavailable,
        ];
    }

    private function providerVersion(string $modelId): ?string
    {
        $provider = (string) (config("atlas_rivals.models.{$modelId}.provider") ?? '');
        $version = config("atlas_rivals.provider_versions.{$provider}");

        return is_string($version) && $version !== '' ? $version : null;
    }
}
