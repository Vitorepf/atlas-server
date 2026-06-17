<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ARBOR-GRAFT J3 — the Research Contract: Arbor's intake forces a goal into FIVE named components before a
 * run (METRIC / BASELINE / AMBITION / SCOPE / HARD-CONSTRAINTS). Atlas already freezes the metric +
 * constraints (via the acceptance contract / J1) and runs a RED-on-baseline preflight; what it lacks is the
 * bundled, readable contract object with the AMBITION and SCOPE dimensions surfaced. This VO projects the
 * existing frozen acceptance into all five components, defaulting honestly.
 *
 * FLOOR-SAFE: pure, read-only, advisory. SCOPE and AMBITION are ideation context only — they NEVER gate
 * certification. The out-of-process FrozenJudge + SemanticImplementationCertifier remain the sole authority.
 */
final class AtlasLoopResearchContract
{
    public const SCOPE_NOVELTY = 'novelty_leaning';

    public const SCOPE_EFFECT = 'effect_leaning';

    public const SCOPE_MIXED = 'mixed';

    public const AMBITION_BEAT = 'beat_baseline';

    public const AMBITION_REACH = 'reach_target';

    public const AMBITION_PUSH = 'push_to_budget';

    private const SCOPES = [self::SCOPE_NOVELTY, self::SCOPE_EFFECT, self::SCOPE_MIXED];

    private const AMBITIONS = [self::AMBITION_BEAT, self::AMBITION_REACH, self::AMBITION_PUSH];

    /**
     * @param  array{allowed_globs: list<string>, frozen_globs: list<string>, sealed_holdout: mixed}  $hardConstraints
     */
    private function __construct(
        public readonly AtlasLoopJObjective $metric,
        public readonly string $baseline,
        public readonly string $ambition,
        public readonly string $scope,
        public readonly array $hardConstraints,
    ) {
    }

    /**
     * Project a frozen acceptance contract + optional ambition/scope into the 5-component research contract.
     *
     * @param  array<string, mixed>  $acceptance
     * @param  array{ambition?: string, scope?: string}  $opts
     */
    public static function fromAcceptance(array $acceptance, array $opts = []): self
    {
        $metric = AtlasLoopJObjective::fromAcceptance($acceptance);

        $ambition = (string) ($opts['ambition'] ?? self::AMBITION_BEAT);
        if (! in_array($ambition, self::AMBITIONS, true)) {
            $ambition = self::AMBITION_BEAT;
        }

        $scope = (string) ($opts['scope'] ?? self::SCOPE_MIXED);
        if (! in_array($scope, self::SCOPES, true)) {
            $scope = self::SCOPE_MIXED;
        }

        $hardConstraints = [
            'allowed_globs' => array_values((array) ($acceptance['allowed_globs'] ?? [])),
            'frozen_globs' => array_values((array) ($acceptance['frozen_globs'] ?? [])),
            'sealed_holdout' => $acceptance['sealed_holdout'] ?? null,
        ];

        return new self(
            metric: $metric,
            baseline: $metric->baseline,
            ambition: $ambition,
            scope: $scope,
            hardConstraints: $hardConstraints,
        );
    }

    /**
     * The 5 components are all present and load-bearing. A contract is incomplete when the metric is not
     * gated (no metric_kind) or there is no hard constraint surface (no frozen path) — i.e. nothing the
     * judge could enforce. Advisory signal only.
     */
    public function isComplete(): bool
    {
        return $this->missingComponents() === [];
    }

    /** @return list<string> */
    public function missingComponents(): array
    {
        $missing = [];
        if ($this->metric->metricKind === '') {
            $missing[] = 'METRIC';
        }
        if ($this->baseline === '') {
            $missing[] = 'BASELINE';
        }
        if (! in_array($this->ambition, self::AMBITIONS, true)) {
            $missing[] = 'AMBITION';
        }
        if (! in_array($this->scope, self::SCOPES, true)) {
            $missing[] = 'SCOPE';
        }
        // HARD-CONSTRAINTS: there must be at least one frozen path (the test surface the loop may never edit).
        if (($this->hardConstraints['frozen_globs'] ?? []) === []) {
            $missing[] = 'HARD_CONSTRAINTS';
        }

        return $missing;
    }

    /**
     * Whether a separate sealed holdout exists, or the contract is iterating on its only signal (test-as-dev).
     * Advisory: when false the intake should scaffold-or-declare (J3 split discipline) — never auto-relax.
     */
    public function hasSealedHoldout(): bool
    {
        return ! empty($this->hardConstraints['sealed_holdout']);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric->toArray(),
            'baseline' => $this->baseline,
            'ambition' => $this->ambition,
            'scope' => $this->scope,
            'hard_constraints' => $this->hardConstraints,
            'complete' => $this->isComplete(),
            'has_sealed_holdout' => $this->hasSealedHoldout(),
        ];
    }
}
