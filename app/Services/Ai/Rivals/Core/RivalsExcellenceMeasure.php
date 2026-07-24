<?php

declare(strict_types=1);

namespace App\Services\Ai\Rivals\Core;

/**
 * P2g-MEAS / R107 path-core: dual-arm M_excellence measure + claim gate.
 *
 * Pure policy — no I/O. Same-μ raw vs atlas rates; claims only on non-saturated
 * frontier with dual-arm + R104 symmetry honesty. Map:
 * docs/engineering-knowledge-base/atlas-agent-qos-excellence-ceiling.md §0
 */
final class RivalsExcellenceMeasure
{
    public const SCHEMA = 'atlas.rivals.excellence_measure.v1';

    public const DEFAULT_EPSILON = 0.02;

    /**
     * @param  array{
     *   n_raw?:float|int|null,
     *   n_atlas?:float|int|null,
     *   epsilon?:float|int|null,
     *   curriculum_role?:string|null,
     *   curriculum_level_id?:string|null,
     *   frontier_saturated?:bool,
     *   dual_arm?:bool,
     *   same_mu?:bool,
     *   r104_symmetry_attested?:bool,
     *   r104_transport_open?:bool,
     *   sample_n?:int|null,
     *   min_sample_n?:int|null,
     *   claim_m_threshold?:float|int|null
     * }  $context
     * @return array<string,mixed>
     */
    public static function report(array $context): array
    {
        $nRaw = max(0.0, (float) ($context['n_raw'] ?? 0.0));
        $nAtlas = max(0.0, (float) ($context['n_atlas'] ?? 0.0));
        $epsilon = (float) ($context['epsilon'] ?? self::DEFAULT_EPSILON);
        if ($epsilon <= 0.0) {
            $epsilon = self::DEFAULT_EPSILON;
        }
        $m = $nAtlas / max($nRaw, $epsilon);
        $negative = $nAtlas < $nRaw;
        $role = RivalsCurriculumLadder::normalizeRole((string) ($context['curriculum_role'] ?? ''));
        $dualArm = (bool) ($context['dual_arm'] ?? false);
        $sameMu = (bool) ($context['same_mu'] ?? false);
        $saturated = (bool) ($context['frontier_saturated'] ?? false);
        $r104Open = (bool) ($context['r104_transport_open'] ?? true);
        $r104Sym = (bool) ($context['r104_symmetry_attested'] ?? false);
        $sampleN = (int) ($context['sample_n'] ?? 0);
        $minN = (int) ($context['min_sample_n'] ?? 3);
        $threshold = (float) ($context['claim_m_threshold'] ?? 50.0);

        $blockers = [];
        if (! $dualArm) {
            $blockers[] = 'dual_arm_required';
        }
        if (! $sameMu) {
            $blockers[] = 'same_mu_required';
        }
        if ($role !== RivalsCurriculumLadder::ROLE_FRONTIER) {
            $blockers[] = 'frontier_curriculum_required';
        }
        if ($saturated) {
            $blockers[] = 'saturated_frontier_no_claim';
        }
        if ($sampleN < $minN) {
            $blockers[] = 'sample_n_insufficient';
        }
        if ($r104Open && ! $r104Sym) {
            $blockers[] = 'r104_symmetry_not_attested';
        }
        if ($negative) {
            $blockers[] = 'multiplier_negative';
        }
        if ($m < $threshold) {
            $blockers[] = 'm_excellence_below_threshold';
        }

        $blockers = array_values(array_unique($blockers));

        return [
            'schema' => self::SCHEMA,
            'N_raw' => $nRaw,
            'N_atlas' => $nAtlas,
            'M_excellence' => $m,
            'epsilon' => $epsilon,
            'curriculum_level_id' => $context['curriculum_level_id'] ?? null,
            'curriculum_role' => $role,
            'frontier_saturated' => $saturated,
            'dual_arm' => $dualArm,
            'same_mu' => $sameMu,
            'sample_n' => $sampleN,
            'min_sample_n' => $minN,
            'claim_m_threshold' => $threshold,
            'multiplier_negative' => $negative,
            'stop_the_line' => $negative,
            'claim_allowed' => $blockers === [],
            'claim_blockers' => $blockers,
            'r104_transport_open' => $r104Open,
            'r104_symmetry_attested' => $r104Sym,
        ];
    }

    /**
     * Conjunctive EXCELLENCE_PASS predicates (path-core boolean rollup).
     *
     * @param  array{
     *   solution_applies?:bool,
     *   acceptance_criteria_pass?:bool,
     *   anti_fake_green?:bool,
     *   write_set_in_scope?:bool,
     *   arm?:string|null,
     *   court_floor_promote?:bool|null
     * }  $context
     */
    public static function excellencePass(array $context): bool
    {
        $base = (bool) ($context['solution_applies'] ?? false)
            && (bool) ($context['acceptance_criteria_pass'] ?? false)
            && (bool) ($context['anti_fake_green'] ?? false)
            && (bool) ($context['write_set_in_scope'] ?? false);

        if (! $base) {
            return false;
        }

        $arm = strtolower(trim((string) ($context['arm'] ?? 'raw')));
        if ($arm === 'atlas' || $arm === 'atlas_dev' || $arm === 'atlas_mutative') {
            // Atlas mutative path requires Court+Floor promote when applicable.
            if (array_key_exists('court_floor_promote', $context)
                && $context['court_floor_promote'] !== null
                && $context['court_floor_promote'] !== true) {
                return false;
            }
        }

        return true;
    }
}
