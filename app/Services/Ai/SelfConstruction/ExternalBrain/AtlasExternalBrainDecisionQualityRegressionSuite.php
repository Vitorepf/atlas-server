<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure local gate. Scores external-brain decision outputs against a compact
 * scenario suite so regressions are detected before decisions reach the queue.
 *
 * Scenarios covered:
 *   template_farm         — low-value farming; must be REJECTED
 *   duplicate_target      — dedup conflict; must be REJECTED
 *   weak_evidence         — no runnable acceptance or missing evidence; must be REJECTED
 *   poison_packet         — poisoned / unrecoverable; must be REJECTED
 *   high_leverage_genuine — real high-impact task; must be ADMITTED
 *   consolidation_needed  — system-quality consolidation task; must be ADMITTED
 *
 * Decision outputs supplied to score() must each carry:
 *   scenario_id: string   — one of the scenario keys above
 *   admitted:    bool     — whether the upstream decision admitted the candidate
 *
 * Additional content fields (weakness_labels[], is_duplicate, is_poison,
 * leverage_score) are also inspected so the suite detects regressions even when
 * scenario_id is unknown or missing.
 *
 * Pure: no I/O, no provider calls, deterministic.
 */
final class AtlasExternalBrainDecisionQualityRegressionSuite
{
    public const SCHEMA = 'atlas.external_brain.decision_quality_regression_suite.v1';

    // Scenarios that must be REJECTED
    public const SCENARIO_TEMPLATE_FARM    = 'template_farm';
    public const SCENARIO_DUPLICATE_TARGET = 'duplicate_target';
    public const SCENARIO_WEAK_EVIDENCE    = 'weak_evidence';
    public const SCENARIO_POISON_PACKET    = 'poison_packet';

    // Scenarios that must be ADMITTED
    public const SCENARIO_HIGH_LEVERAGE_GENUINE = 'high_leverage_genuine';
    public const SCENARIO_CONSOLIDATION_NEEDED  = 'consolidation_needed';

    private const EXPECTED_ADMITTED = [
        self::SCENARIO_TEMPLATE_FARM         => false,
        self::SCENARIO_DUPLICATE_TARGET      => false,
        self::SCENARIO_WEAK_EVIDENCE         => false,
        self::SCENARIO_POISON_PACKET         => false,
        self::SCENARIO_HIGH_LEVERAGE_GENUINE => true,
        self::SCENARIO_CONSOLIDATION_NEEDED  => true,
    ];

    private const REGRESSION_RULES = [
        self::SCENARIO_TEMPLATE_FARM    => 'template-farm candidate must not be admitted; it produces low-value tasks',
        self::SCENARIO_DUPLICATE_TARGET => 'duplicate-target candidate must not be admitted; it wastes queue cycles',
        self::SCENARIO_WEAK_EVIDENCE    => 'weak-evidence candidate must not be admitted; task is unverifiable',
        self::SCENARIO_POISON_PACKET    => 'poison packet must not be admitted; it is unrecoverable',
    ];

    private const CONTENT_LOW_VALUE_LABELS = ['template_farming', 'shallow_duplication', 'fake_confidence'];

    /**
     * @param  list<array<string,mixed>>  $decisions
     * @return array{schema:string, passed_scenarios:list<string>, failed_scenarios:list<string>, quality_score:float, regression_reasons:list<string>}
     */
    public function score(array $decisions): array
    {
        $passed            = [];
        $failed            = [];
        $regressionReasons = [];

        foreach ($decisions as $decision) {
            if (! is_array($decision)) {
                continue;
            }

            $scenarioId = trim((string) ($decision['scenario_id'] ?? ''));
            $admitted   = (bool) ($decision['admitted'] ?? false);

            // Resolve expected outcome: from scenario map first, then content
            $expectedAdmitted = $this->resolveExpected($scenarioId, $decision);

            if ($expectedAdmitted === null) {
                // Unrecognized scenario with no content signal — skip scoring
                continue;
            }

            $label = $scenarioId !== '' ? $scenarioId : $this->inferScenarioLabel($decision);

            if ($admitted === $expectedAdmitted) {
                $passed[] = $label;
            } else {
                $failed[]            = $label;
                $regressionReasons[] = $this->regressionReason($label, $admitted, $expectedAdmitted, $decision);
            }
        }

        $total        = count($passed) + count($failed);
        $qualityScore = $total > 0 ? round(count($passed) / $total, 4) : 1.0;

        return [
            'schema'             => self::SCHEMA,
            'passed_scenarios'   => $passed,
            'failed_scenarios'   => $failed,
            'quality_score'      => $qualityScore,
            'regression_reasons' => $regressionReasons,
        ];
    }

    private function resolveExpected(string $scenarioId, array $decision): ?bool
    {
        // Known scenario — authoritative
        if (isset(self::EXPECTED_ADMITTED[$scenarioId])) {
            return self::EXPECTED_ADMITTED[$scenarioId];
        }

        // Content-based fallback: bad signals → must NOT be admitted
        if ($this->contentSignalsBad($decision)) {
            return false;
        }

        // Content-based fallback: strong leverage + no bad signals → SHOULD be admitted
        $leverage = (float) ($decision['leverage_score'] ?? 0.0);
        if ($leverage >= 0.70) {
            return true;
        }

        return null;
    }

    private function contentSignalsBad(array $decision): bool
    {
        if ((bool) ($decision['is_poison']    ?? false)) {
            return true;
        }
        if ((bool) ($decision['is_duplicate'] ?? false)) {
            return true;
        }

        $weaknesses  = (array) ($decision['weakness_labels'] ?? []);
        $lowValueHit = array_intersect($weaknesses, self::CONTENT_LOW_VALUE_LABELS);
        if ($lowValueHit !== []) {
            return true;
        }

        return false;
    }

    private function inferScenarioLabel(array $decision): string
    {
        if ((bool) ($decision['is_poison']    ?? false)) {
            return self::SCENARIO_POISON_PACKET;
        }
        if ((bool) ($decision['is_duplicate'] ?? false)) {
            return self::SCENARIO_DUPLICATE_TARGET;
        }
        $weaknesses = (array) ($decision['weakness_labels'] ?? []);
        if (array_intersect($weaknesses, self::CONTENT_LOW_VALUE_LABELS) !== []) {
            return self::SCENARIO_TEMPLATE_FARM;
        }

        return 'unknown_content_signal';
    }

    private function regressionReason(string $label, bool $actualAdmitted, bool $expectedAdmitted, array $decision): string
    {
        $action   = $actualAdmitted ? 'admitted'  : 'rejected';
        $expected = $expectedAdmitted ? 'admission' : 'rejection';

        $hint = self::REGRESSION_RULES[$label]
            ?? "scenario={$label} expected {$expected} but got {$action}";

        return "[{$label}] {$action} but expected {$expected}: {$hint}";
    }
}
