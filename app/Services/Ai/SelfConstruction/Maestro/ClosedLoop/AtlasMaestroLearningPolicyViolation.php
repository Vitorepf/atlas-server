<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

use RuntimeException;

/**
 * Thrown by {@see AtlasMaestroLearningPolicyGuard} when a learning artifact violates the pétreo anti-Goodhart
 * contract on its way to the Replenisher. The $reason is a stable machine token for the violated rule.
 */
final class AtlasMaestroLearningPolicyViolation extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function compositeScore(string $key): self
    {
        return new self('composite_score', "learning artifact carries a composite score/ranking field: {$key}");
    }

    public static function imperativeAdvice(string $token): self
    {
        return new self('imperative_advice', "learning artifact contains imperative advice token: {$token}");
    }

    public static function subSupportBucket(int $total): self
    {
        return new self('sub_support_bucket', "learning artifact carries a bucket below MIN_SUPPORT (total={$total})");
    }

    public static function forbiddenScope(string $scope): self
    {
        return new self('forbidden_scope', "learning artifact references a forbidden scope: {$scope}");
    }

    public static function taskFieldOverride(string $field): self
    {
        return new self('task_field_override', "learning artifact tries to override task quality field: {$field}");
    }
}
