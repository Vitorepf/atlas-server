<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusScalarNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;

final class MeasuredLearningApplyGateEvaluator
{
    private const SCHEMA_VERSION = 'atlas.loop.measured_learning_apply_gate.v1';

    /**
     * @return array{schema_version: string, apply_allowed: bool, decision: string, blockers: list<string>, required_next_action: string}
     */
    public function evaluate(array $proposal, array $proof, array $gate): array
    {
        $blockers = [];

        if (! $this->isApprovedOrProven($proposal, $proof)) {
            $blockers[] = 'not_approved_or_proven';
        }

        if (! $this->rsiMetaJudgePasses($gate)) {
            $blockers[] = 'rsi_meta_judge_failed';
        }

        if (! $this->scopeIsBounded($proposal, $gate)) {
            $blockers[] = 'scope_out_of_bounds';
        }

        if (! $this->rollbackProofExists($proof)) {
            $blockers[] = 'rollback_proof_missing';
        }

        $applyAllowed = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'apply_allowed' => $applyAllowed,
            'decision' => $applyAllowed ? 'apply' : 'hold',
            'blockers' => $blockers,
            'required_next_action' => $applyAllowed
                ? 'apply_via_mutation_runtime'
                : 'remediate_blockers',
        ];
    }

    private function isApprovedOrProven(array $proposal, array $proof): bool
    {
        if (($proposal['approved'] ?? false) === true) {
            return true;
        }

        return $this->isProvenWithPositiveLift($proof);
    }

    private function isProvenWithPositiveLift(array $proof): bool
    {
        if (($proof['proven'] ?? false) !== true) {
            return false;
        }

        return AreaFocusScalarNormalizer::payloadNumberOrDefault($proof, 'lift', 0.0) > 0.0;
    }

    private function rsiMetaJudgePasses(array $gate): bool
    {
        if (($gate['rsi_meta_judge_passed'] ?? null) === true) {
            return true;
        }

        $verdict = $gate['rsi_meta_judge'] ?? null;

        if ($verdict === true) {
            return true;
        }

        return is_string($verdict) && strtolower($verdict) === 'pass';
    }

    private function scopeIsBounded(array $proposal, array $gate): bool
    {
        $requestedPaths = AreaFocusStringListNormalizer::preserveNonBlankStrings($proposal['scope_paths'] ?? []);

        if ($requestedPaths === []) {
            return false;
        }

        $allowedPaths = AreaFocusStringListNormalizer::preserveNonBlankStrings($gate['allowed_paths'] ?? []);

        if ($allowedPaths === []) {
            return false;
        }

        foreach ($requestedPaths as $path) {
            if (! $this->pathWithinAllowed($path, $allowedPaths)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $allowedPaths
     */
    private function pathWithinAllowed(string $path, array $allowedPaths): bool
    {
        foreach ($allowedPaths as $allowed) {
            if ($path === $allowed) {
                return true;
            }

            if (str_starts_with($path, rtrim($allowed, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private function rollbackProofExists(array $proof): bool
    {
        if (($proof['rollback_proven'] ?? false) === true) {
            return true;
        }

        $plan = $proof['rollback_plan'] ?? null;

        return is_string($plan) && trim($plan) !== '';
    }
}
