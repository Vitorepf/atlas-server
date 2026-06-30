<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Single pre-enqueue admission gate combining semantic dedup, anti-template-farm,
 * implementability, compound impact and give_back risk into one deterministic verdict.
 * Called before any task reaches muscle workers.
 *
 * REJECTION CHECKS (all applied; every failing check adds to rejection_reasons):
 *
 *   semantic_duplicate        — task target appears in known_targets (case-insensitive exact)
 *   template_farm             — objective is formulaic: length < MIN_OBJECTIVE_CHARS, or
 *                               is_template_farm=true on the input
 *   implementability_weak     — allowed_files has no implementation file (Service/Command/etc.)
 *                               or no test file (Test.php)
 *   compound_impact_low       — compound_impact_score < COMPOUND_IMPACT_FLOOR
 *   give_back_risk_high       — give_back_risk_score >= GIVE_BACK_RISK_CEILING
 *
 * ADMITTED = true only when rejection_reasons is empty.
 *
 * OUTPUT WHEN ADMITTED:
 *   value_score        = compound_impact_score × (1 − give_back_risk_score)
 *   risk_score         = give_back_risk_score
 *   required_followups = [] (or ["add_test_coverage", "add_implementation"] per gaps)
 *
 * DEFAULT THRESHOLDS:
 *   MIN_OBJECTIVE_CHARS    = 30
 *   COMPOUND_IMPACT_FLOOR  = 0.30
 *   GIVE_BACK_RISK_CEILING = 0.70
 *
 * INPUT:
 *   {
 *     target?:               string                   (semantic dedup key)
 *     objective?:            string
 *     allowed_files?:        list<string>
 *     compound_impact_score?: float                   (default 0.0)
 *     give_back_risk_score?:  float                   (default 0.0)
 *     known_targets?:        list<string>             (already-covered targets)
 *     is_template_farm?:     bool                     (explicit override)
 *     thresholds?:           { min_objective_chars, compound_impact_floor, give_back_risk_ceiling }
 *   }
 *
 * PURE / DETERMINISTIC / NO I/O. Does not enqueue, mutate queue or call Artisan.
 */
final class AtlasTaskFabricBrutalValueAdmissionGate
{
    public const SCHEMA = 'atlas.task_fabric.brutal_value_admission_gate.v1';

    private const MIN_OBJECTIVE_CHARS    = 30;
    private const COMPOUND_IMPACT_FLOOR  = 0.30;
    private const GIVE_BACK_RISK_CEILING = 0.70;

    private const IMPL_PATTERNS = [
        'Service.php', 'Command.php', 'Controller.php', 'Repository.php',
        'Handler.php', 'Listener.php', 'Job.php', 'Policy.php', 'Provider.php',
        '/Services/', '/Commands/', '/Controllers/', '/Repositories/',
    ];
    private const TEST_PATTERN = 'Test.php';

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function decide(array $candidate): array
    {
        $target         = trim((string) ($candidate['target'] ?? ''));
        $objective      = trim((string) ($candidate['objective'] ?? ''));
        $allowedFiles   = is_array($candidate['allowed_files'] ?? null) ? $candidate['allowed_files'] : [];
        $impactScore    = (float) ($candidate['compound_impact_score'] ?? 0.0);
        $giveBackRisk   = (float) ($candidate['give_back_risk_score'] ?? 0.0);
        $knownTargets   = is_array($candidate['known_targets'] ?? null)
            ? array_map('strtolower', array_map('trim', array_map('strval', $candidate['known_targets'])))
            : [];
        $isTemplateFarm = (bool) ($candidate['is_template_farm'] ?? false);

        $thresholds = is_array($candidate['thresholds'] ?? null) ? $candidate['thresholds'] : [];
        $minChars       = (int) ($thresholds['min_objective_chars']   ?? self::MIN_OBJECTIVE_CHARS);
        $impactFloor    = (float) ($thresholds['compound_impact_floor']  ?? self::COMPOUND_IMPACT_FLOOR);
        $giveBackCeil   = (float) ($thresholds['give_back_risk_ceiling'] ?? self::GIVE_BACK_RISK_CEILING);

        $rejectionReasons = [];

        // 1. Semantic duplicate.
        if ($target !== '' && in_array(strtolower($target), $knownTargets, true)) {
            $rejectionReasons[] = 'semantic_duplicate';
        }

        // 2. Template farm.
        if ($isTemplateFarm || ($objective !== '' && strlen($objective) < $minChars)) {
            $rejectionReasons[] = 'template_farm';
        }

        // 3. Implementability.
        $hasImpl = false;
        $hasTest = false;
        foreach ($allowedFiles as $file) {
            $file = (string) $file;
            if (str_contains($file, self::TEST_PATTERN)) {
                $hasTest = true;
            } else {
                foreach (self::IMPL_PATTERNS as $pattern) {
                    if (str_contains($file, $pattern)) {
                        $hasImpl = true;
                        break;
                    }
                }
            }
        }
        // Only apply the check when allowed_files was provided.
        if ($allowedFiles !== []) {
            if (! $hasImpl) {
                $rejectionReasons[] = 'implementability_weak:no_implementation_file';
            }
            if (! $hasTest) {
                $rejectionReasons[] = 'implementability_weak:no_test_file';
            }
        }

        // 4. Compound impact.
        if ($impactScore < $impactFloor) {
            $rejectionReasons[] = 'compound_impact_low';
        }

        // 5. Give_back risk.
        if ($giveBackRisk >= $giveBackCeil) {
            $rejectionReasons[] = 'give_back_risk_high';
        }

        $admitted = $rejectionReasons === [];

        if (! $admitted) {
            return [
                'schema'            => self::SCHEMA,
                'admitted'          => false,
                'rejection_reasons' => $rejectionReasons,
            ];
        }

        $valueScore = $impactScore * (1.0 - $giveBackRisk);
        $requiredFollowups = [];
        if (! $hasImpl && $allowedFiles !== []) {
            $requiredFollowups[] = 'add_implementation';
        }
        if (! $hasTest && $allowedFiles !== []) {
            $requiredFollowups[] = 'add_test_coverage';
        }

        return [
            'schema'             => self::SCHEMA,
            'admitted'           => true,
            'rejection_reasons'  => [],
            'value_score'        => round($valueScore, 6),
            'risk_score'         => $giveBackRisk,
            'required_followups' => $requiredFollowups,
        ];
    }
}
