<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

final class ProviderRouteFallbackDecisionEvaluator
{
    private const SCHEMA_VERSION = 'atlas.provider.route_fallback_decision.v1';

    private const ADML_FOLLOW_LEARNED_VALUES = [
        'VERDICT_FOLLOW_LEARNED',
        'follow_learned',
        true,
        'allow',
    ];

    public function decide(array $job, array $learnedRoute, array $defaultRoute): array
    {
        if ($this->isEmptyRoute($learnedRoute)) {
            return $this->buildFallbackResult($defaultRoute, 'learned_route_empty');
        }

        $taskCategoryReason = $this->checkTaskCategory($job, $learnedRoute);
        if ($taskCategoryReason !== null) {
            return $this->buildFallbackResult($defaultRoute, $taskCategoryReason);
        }

        $roleReason = $this->checkRole($job, $learnedRoute);
        if ($roleReason !== null) {
            return $this->buildFallbackResult($defaultRoute, $roleReason);
        }

        $privacyReason = $this->checkPrivacy($job, $learnedRoute);
        if ($privacyReason !== null) {
            return $this->buildFallbackResult($defaultRoute, $privacyReason);
        }

        $availabilityReason = $this->checkAvailability($learnedRoute);
        if ($availabilityReason !== null) {
            return $this->buildFallbackResult($defaultRoute, $availabilityReason);
        }

        $verdictReason = $this->checkAdmlVerdict($learnedRoute);
        if ($verdictReason !== null) {
            return $this->buildFallbackResult($defaultRoute, $verdictReason);
        }

        return $this->buildSuccessResult($learnedRoute);
    }

    private function isEmptyRoute(array $route): bool
    {
        return empty($route) || empty($route['provider'] ?? null);
    }

    private function checkTaskCategory(array $job, array $learnedRoute): ?string
    {
        $jobCategory = $job['task_category'] ?? null;
        $routeCategory = $learnedRoute['task_category'] ?? null;

        if ($jobCategory === null || $jobCategory === '') {
            return null;
        }

        if ($routeCategory === null) {
            return 'task_category_missing_in_learned_route';
        }

        if ($jobCategory !== $routeCategory) {
            return 'task_category_mismatch';
        }

        return null;
    }

    private function checkRole(array $job, array $learnedRoute): ?string
    {
        $jobRole = $job['role'] ?? null;
        $routeRole = $learnedRoute['role'] ?? null;

        if ($jobRole === null || $jobRole === '') {
            return null;
        }

        if ($routeRole === null) {
            return 'role_missing_in_learned_route';
        }

        if ($jobRole !== $routeRole) {
            return 'role_mismatch';
        }

        return null;
    }

    private function checkPrivacy(array $job, array $learnedRoute): ?string
    {
        $jobPrivacy = $job['privacy'] ?? null;
        $routePrivacy = $learnedRoute['privacy_class']
            ?? $learnedRoute['privacy'] ?? null;

        if ($jobPrivacy === null) {
            return null;
        }

        if ($routePrivacy === null) {
            return 'privacy_class_missing_in_learned_route';
        }

        if ($jobPrivacy !== $routePrivacy) {
            return 'privacy_mismatch';
        }

        return null;
    }

    private function checkAvailability(array $learnedRoute): ?string
    {
        $available = $learnedRoute['available']
            ?? $learnedRoute['availability'] ?? true;

        if ($available === false) {
            return 'learned_route_unavailable';
        }

        return null;
    }

    private function checkAdmlVerdict(array $learnedRoute): ?string
    {
        $verdict = $learnedRoute['adml_verdict']
            ?? $learnedRoute['verdict'] ?? null;

        if ($verdict === null) {
            return 'adml_verdict_missing';
        }

        if (! in_array($verdict, self::ADML_FOLLOW_LEARNED_VALUES, true)) {
            return 'adml_verdict_not_follow_learned';
        }

        return null;
    }

    private function buildFallbackResult(array $route, string $reason): array
    {
        $isDefaultEmpty = $this->isEmptyRoute($route);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'selected_provider' => $isDefaultEmpty ? null : ($route['provider'] ?? null),
            'route_source' => 'default',
            'fallback_reason' => $isDefaultEmpty ? 'default_route_unavailable' : $reason,
            'receipt_required' => true,
            'provider_invoked' => false,
        ];
    }

    private function buildSuccessResult(array $route): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'selected_provider' => $route['provider'],
            'route_source' => 'learned',
            'fallback_reason' => null,
            'receipt_required' => true,
            'provider_invoked' => false,
        ];
    }
}
