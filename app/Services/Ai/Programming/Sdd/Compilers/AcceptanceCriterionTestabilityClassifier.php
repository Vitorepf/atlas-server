<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Sdd\Compilers;


/**
 * Classifies a single acceptance criterion into a closed-set testability label.
 *
 * Pure first-match classifier: the criterion is lowercased and trimmed once,
 * then a fixed, ordered set of rules is evaluated TOP-DOWN. The first rule
 * that matches wins; later rules never override an earlier verdict. This
 * lets a criterion that is simultaneously runnable and visual (e.g. an
 * executable test that also mentions a screenshot) resolve to the strongest
 * verifiable category.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-reliability-testos-leap-backlog.md (S177)
 * @see \App\Services\Ai\Programming\Sdd\Compilers\SpecCritic VAGUE_WORDS source
 * @unwired-until 2026-08-05 (Obra #7 W2: capability testada aguardando consumidor; triagem 2026-07-06)
 */
final class AcceptanceCriterionTestabilityClassifier
{
    public const LABEL_EXECUTABLE_TEST = 'executable_test';

    public const LABEL_OBSERVABLE_METRIC = 'observable_metric';

    public const LABEL_MANUAL_INSPECTION = 'manual_inspection';

    public const LABEL_UNTESTABLE = 'untestable';

    /**
     * Minimum length (after trim) below which a criterion is too thin to test.
     */
    private const MIN_TESTABLE_LENGTH = 12;

    /**
     * Mirrored EXACTLY from {@see SpecCritic} VAGUE_WORDS. Keep byte-for-byte
     * in sync; any vague word makes the criterion non-deterministically scoped.
     *
     * @var list<string>
     */
    private const VAGUE_WORDS = ['various', 'maybe', 'something', 'stuff', 'qualquer', 'talvez', 'algo', 'meio que'];

    /**
     * Returns exactly one closed-set label for the given acceptance criterion.
     *
     * Ordered rules (first match wins):
     *  R1 executable-test markers (phpunit / --filter / artisan test / ::test / test_*)
     *  R2 numeric observable metric (\d+ ms|%|mb|files|count)
     *  R3 runnable green-state verbs (green / exit 0 / passes / sync / index-code)
     *  R4 human-eye verbs (looks / feels / review / screenshot / visual)
     *  R5 too short OR contains a vague word -> untestable
     *  R6 default -> manual_inspection
     */
    public function classify(string $criterion): string
    {
        $normalized = strtolower(trim($criterion));

        if ($this->isExecutableMarker($normalized)) {
            return self::LABEL_EXECUTABLE_TEST;
        }

        if ($this->isObservableMetric($normalized)) {
            return self::LABEL_OBSERVABLE_METRIC;
        }

        if ($this->isRunnableGreenState($normalized)) {
            return self::LABEL_EXECUTABLE_TEST;
        }

        if ($this->isHumanEyeCriterion($normalized)) {
            return self::LABEL_MANUAL_INSPECTION;
        }

        if ($this->isUntestable($normalized)) {
            return self::LABEL_UNTESTABLE;
        }

        return self::LABEL_MANUAL_INSPECTION;
    }

    private function isExecutableMarker(string $normalized): bool
    {
        return preg_match('/phpunit|--filter|artisan\s+test|::test|test_[a-z0-9_]+/', $normalized) === 1;
    }

    private function isObservableMetric(string $normalized): bool
    {
        return preg_match('/\d+\s*(ms|%|mb|files|count)\b/', $normalized) === 1;
    }

    private function isRunnableGreenState(string $normalized): bool
    {
        return preg_match('/\b(green|exit\s*0|passes|sync|index-code)\b/', $normalized) === 1;
    }

    private function isHumanEyeCriterion(string $normalized): bool
    {
        return preg_match('/\b(looks|feels|review|screenshot|visual)\b/', $normalized) === 1;
    }

    private function isUntestable(string $normalized): bool
    {
        if (strlen($normalized) < self::MIN_TESTABLE_LENGTH) {
            return true;
        }

        foreach (self::VAGUE_WORDS as $word) {
            if (str_contains($normalized, $word)) {
                return true;
            }
        }

        return false;
    }
}
