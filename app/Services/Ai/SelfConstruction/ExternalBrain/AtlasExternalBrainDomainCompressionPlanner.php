<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure domain-aware compression gate: compression-by-file-list misses bounded-context
 * differences — each Atlas domain needs its own simplification strategy, ranked and
 * plan-typed from REAL structured facts, never a static domain list or generic roadmap.
 *
 * PLAN TYPE (per domain, first matching rule wins):
 *   proof_first — maturity === 'immature' OR risk_level === 'high'. An immature or high-risk
 *                 domain is NEVER compressed directly; it must gather proof / mature first.
 *   compress    — every other domain: mature/maturing enough and not high-risk.
 *
 * RANKING SCORE (0.0-1.0, rounded 4dp, higher = more valuable to touch first):
 *   entropy_score          × 0.35   (raw duplication/sprawl signal, already 0..1)
 *   + maturity_bonus                 mature=0.25, maturing=0.10, immature=0.0
 *   + capability_owned_bonus         owned=0.15, unowned=0.0
 *   + line_reduction_bonus           min(1, expected_line_reduction / 500) × 0.25
 *   − proof_debt_penalty             min(1, proof_debt_count / 5) × 0.15
 *
 * Domains are ranked ACROSS both plan types on the same score — a proof_first domain can
 * still rank first if it is the highest-leverage target, it just gets proof_first as its
 * plan_type instead of compress.
 *
 * Pure. No I/O, no provider calls, deterministic.
 */
final class AtlasExternalBrainDomainCompressionPlanner
{
    public const SCHEMA = 'atlas.external_brain.domain_compression_planner.v1';

    public const PLAN_PROOF_FIRST = 'proof_first';

    public const PLAN_COMPRESS = 'compress';

    private const MATURITY_BONUS = [
        'mature' => 0.25,
        'maturing' => 0.10,
        'immature' => 0.0,
    ];

    private const LINE_REDUCTION_CAP = 500;

    private const PROOF_DEBT_CAP = 5;

    /**
     * @param  array{domains?: list<array<string,mixed>>}  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $domains = is_array($input['domains'] ?? null) ? $input['domains'] : [];

        $ranked = [];
        $counts = [self::PLAN_PROOF_FIRST => 0, self::PLAN_COMPRESS => 0];

        foreach ($domains as $domain) {
            if (! is_array($domain)) {
                continue;
            }
            $entry = $this->planOne($domain);
            $ranked[] = $entry;
            $counts[$entry['plan_type']]++;
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] !== $a['score']
            ? $b['score'] <=> $a['score']
            : strcmp((string) $a['domain_id'], (string) $b['domain_id']));

        return [
            'schema' => self::SCHEMA,
            'domains' => $ranked,
            'summary' => [
                'total' => count($ranked),
                'proof_first_count' => $counts[self::PLAN_PROOF_FIRST],
                'compress_count' => $counts[self::PLAN_COMPRESS],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $domain
     * @return array<string,mixed>
     */
    private function planOne(array $domain): array
    {
        $domainId = (string) ($domain['domain_id'] ?? '');
        $entropyScore = max(0.0, min(1.0, (float) ($domain['entropy_score'] ?? 0.0)));
        $maturity = strtolower(trim((string) ($domain['maturity'] ?? 'immature')));
        if (! array_key_exists($maturity, self::MATURITY_BONUS)) {
            $maturity = 'immature';
        }
        $riskLevel = strtolower(trim((string) ($domain['risk_level'] ?? 'medium')));
        $capabilityOwned = (bool) ($domain['capability_owned'] ?? false);
        $proofDebtCount = max(0, (int) ($domain['proof_debt_count'] ?? 0));
        $expectedLineReduction = max(0, (int) ($domain['expected_line_reduction'] ?? 0));

        $planType = ($maturity === 'immature' || $riskLevel === 'high')
            ? self::PLAN_PROOF_FIRST
            : self::PLAN_COMPRESS;

        $reasons = [];
        if ($maturity === 'immature') {
            $reasons[] = 'domain_immature';
        }
        if ($riskLevel === 'high') {
            $reasons[] = 'domain_high_risk';
        }
        if ($planType === self::PLAN_COMPRESS) {
            $reasons[] = 'domain_ready_for_compression';
        }

        $maturityBonus = self::MATURITY_BONUS[$maturity];
        $capabilityBonus = $capabilityOwned ? 0.15 : 0.0;
        $lineReductionBonus = min(1.0, $expectedLineReduction / self::LINE_REDUCTION_CAP) * 0.25;
        $proofDebtPenalty = min(1.0, $proofDebtCount / self::PROOF_DEBT_CAP) * 0.15;

        $score = round(max(0.0, min(1.0,
            $entropyScore * 0.35 + $maturityBonus + $capabilityBonus + $lineReductionBonus - $proofDebtPenalty,
        )), 4);

        return [
            'domain_id' => $domainId,
            'plan_type' => $planType,
            'score' => $score,
            'reasons' => $reasons,
            'entropy_score' => $entropyScore,
            'maturity' => $maturity,
            'risk_level' => $riskLevel,
            'capability_owned' => $capabilityOwned,
            'proof_debt_count' => $proofDebtCount,
            'expected_line_reduction' => $expectedLineReduction,
        ];
    }
}
