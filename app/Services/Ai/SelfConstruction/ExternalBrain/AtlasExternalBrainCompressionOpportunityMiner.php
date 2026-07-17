<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * High-leverage miner: turns raw entropy, hotspot, duplicate, reachability, and proof-debt facts
 * into a ranked list of compression candidates instead of a hand-invented refactor wishlist.
 * Only a candidate that is actually implementable — it names concrete allowed_files, and every
 * proof item it declares as required prework is already done — is ever ranked; everything else
 * is excluded with a concrete reason so the brain never proposes a task no muscle can execute.
 *
 * Input contract:
 *   candidates: list<array{
 *     id?:                     string,
 *     allowed_files?:          list<string>,
 *     entropy_score?:          float,  (0..1, higher = more disorder to collapse)
 *     hotspot_score?:          float,  (0..1, higher = more churn/complexity)
 *     duplicate_score?:        float,  (0..1, higher = more duplication)
 *     reachability_score?:     float,  (0..1, higher = more of the codebase depends on it)
 *     proof_debt_score?:       float,  (0..1, higher = more unproven risk — SUBTRACTS leverage)
 *     required_proof_prework?: list<string>,
 *     proof_prework_done?:     list<string>,
 *   }>
 *
 * leverage_score = average(entropy, hotspot, duplicate, reachability) - proof_debt_score,
 * clamped to >= 0. Candidates rank by leverage_score descending, id ascending as tiebreak.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainCompressionOpportunityMiner
{
    public const SCHEMA = 'atlas.external_brain.compression_opportunity_miner.v1';

    public const EXCLUSION_NO_IMPLEMENTATION_TARGET = 'no_implementable_allowed_files';
    public const EXCLUSION_MISSING_PROOF_PREWORK    = 'missing_required_proof_prework';

    /**
     * @param  array{candidates?: list<array<string,mixed>>}  $facts
     * @return array{schema:string, candidates:list<array<string,mixed>>, excluded:list<array{id:string, reason:string}>}
     */
    public function mine(array $facts): array
    {
        $candidates = is_array($facts['candidates'] ?? null) ? $facts['candidates'] : [];

        $ranked   = [];
        $excluded = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $id = trim((string) ($candidate['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $allowedFiles = array_values(array_filter(array_map('strval', (array) ($candidate['allowed_files'] ?? [])), static fn (string $f): bool => $f !== ''));
            if ($allowedFiles === []) {
                $excluded[] = ['id' => $id, 'reason' => self::EXCLUSION_NO_IMPLEMENTATION_TARGET];

                continue;
            }

            $requiredPrework = array_values(array_filter(array_map('strval', (array) ($candidate['required_proof_prework'] ?? []))));
            $preworkDone     = array_values(array_filter(array_map('strval', (array) ($candidate['proof_prework_done'] ?? []))));
            $missingPrework  = array_values(array_diff($requiredPrework, $preworkDone));
            if ($missingPrework !== []) {
                $excluded[] = ['id' => $id, 'reason' => self::EXCLUSION_MISSING_PROOF_PREWORK];

                continue;
            }

            $entropy      = $this->clamp((float) ($candidate['entropy_score'] ?? 0.0));
            $hotspot      = $this->clamp((float) ($candidate['hotspot_score'] ?? 0.0));
            $duplicate    = $this->clamp((float) ($candidate['duplicate_score'] ?? 0.0));
            $reachability = $this->clamp((float) ($candidate['reachability_score'] ?? 0.0));
            $proofDebt    = $this->clamp((float) ($candidate['proof_debt_score'] ?? 0.0));

            $leverageScore = max(0.0, round((($entropy + $hotspot + $duplicate + $reachability) / 4.0) - $proofDebt, 4));

            $ranked[] = [
                'id'                  => $id,
                'allowed_files'       => $allowedFiles,
                'leverage_score'      => $leverageScore,
                'entropy_score'       => $entropy,
                'hotspot_score'       => $hotspot,
                'duplicate_score'     => $duplicate,
                'reachability_score'  => $reachability,
                'proof_debt_score'    => $proofDebt,
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $a['leverage_score'] === $b['leverage_score']
            ? strcmp((string) $a['id'], (string) $b['id'])
            : $b['leverage_score'] <=> $a['leverage_score']);

        return [
            'schema'     => self::SCHEMA,
            'candidates' => $ranked,
            'excluded'   => $excluded,
        ];
    }

    private function clamp(float $score): float
    {
        return AiValueNormalizer::clampUnit($score);
    }
}
