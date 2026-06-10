<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusScalarNormalizer;

final class AtlasDevRunProfileGuardEvaluator
{
    private const SCHEMA_VERSION = 'atlas.dev.run_profile_guard.v1';

    private const ROUTE_BLOCKED = 'blocked';

    private const ROUTE_ESCALATE = 'escalate_promotion_preview';

    private const ROUTE_FAST_PATH = 'fast_path';

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $job
     * @param  array<string, mixed>  $decision
     * @return array{
     *     schema_version: string,
     *     run_allowed: bool,
     *     escalation_required: bool,
     *     route_decision: string,
     *     blockers: list<string>,
     *     packet_required: bool,
     * }
     */
    public function evaluate(array $profile, array $job, array $decision): array
    {
        $blockers = [];

        if ($this->profileScope($profile) !== 'scoped') {
            $blockers[] = 'run_profile_not_scoped';
        }

        if (AreaFocusScalarNormalizer::payloadBool($profile, 'run_enabled', true) !== true) {
            $blockers[] = 'run_profile_disabled';
        }

        if (AreaFocusScalarNormalizer::payloadBool($job, 'pre_provider_receipt') !== true) {
            $blockers[] = 'pre_provider_receipt_missing';
        }

        $runAllowed = $blockers === [];

        $escalationRequired = $runAllowed && $this->isPromotionPreview($decision);

        $routeDecision = $this->resolveRoute($runAllowed, $escalationRequired);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'run_allowed' => $runAllowed,
            'escalation_required' => $escalationRequired,
            'route_decision' => $routeDecision,
            'blockers' => $blockers,
            'packet_required' => $escalationRequired,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function profileScope(array $profile): string
    {
        $scope = $profile['scope'] ?? null;

        if (is_string($scope) && $scope !== '') {
            return $scope;
        }

        if (AreaFocusScalarNormalizer::payloadBool($profile, 'global') === true) {
            return 'global';
        }

        return 'unscoped';
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function isPromotionPreview(array $decision): bool
    {
        if (($decision['mode'] ?? null) === 'promotion_preview') {
            return true;
        }

        return AreaFocusScalarNormalizer::payloadBool($decision, 'promotion_preview') === true;
    }

    private function resolveRoute(bool $runAllowed, bool $escalationRequired): string
    {
        if (! $runAllowed) {
            return self::ROUTE_BLOCKED;
        }

        if ($escalationRequired) {
            return self::ROUTE_ESCALATE;
        }

        return self::ROUTE_FAST_PATH;
    }
}
