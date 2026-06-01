<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

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

        if ($this->boolValue($profile, 'run_enabled', true) !== true) {
            $blockers[] = 'run_profile_disabled';
        }

        if ($this->boolValue($job, 'pre_provider_receipt', false) !== true) {
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

        if ($this->boolValue($profile, 'global', false) === true) {
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

        return $this->boolValue($decision, 'promotion_preview', false) === true;
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function boolValue(array $payload, string $key, bool $default): bool
    {
        $value = $payload[$key] ?? $default;

        return $value === true;
    }
}
