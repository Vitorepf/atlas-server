<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge\Qa;

use App\Models\AiForgeIntake;
use App\Services\Ai\Programming\Forge\ForgeIntakeCanon;

/**
 * Evaluates whether an `AiForgeIntake` carries the minimum SDD spec needed for
 * a heavy Obra to advance: 9 canonical sections (problem_statement, scope,
 * non_goals, constraints, architecture_notes, acceptance_criteria,
 * verification_plan, risks, required_evidence).
 *
 * The gate returns a structured projection — never throws — so the caller
 * (QA gate runner / certification loop) decides whether a missing section is
 * a blocker or a warning. For heavy Obras every required section is enforced;
 * for light Obras the gate downgrades fails to warnings.
 *
 * Schema: see {@see ForgeIntakeCanon::SDD_SPEC_SCHEMA_VERSION}.
 */
final class ForgeSddSpecGate
{
    public const STATUS_PASSED = 'passed';

    public const STATUS_WARN = 'warn';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string,mixed>
     */
    public function evaluate(AiForgeIntake $intake): array
    {
        $isHeavy = ForgeIntakeCanon::isHeavyObra(
            (string) $intake->origin,
            (string) $intake->risk_band,
            (string) $intake->recommended_forge_mode,
        );

        $spec = is_array($intake->sdd_spec) ? $intake->sdd_spec : null;
        $sectionsReport = [];
        $missing = [];
        $present = [];

        foreach (ForgeIntakeCanon::SDD_REQUIRED_SECTIONS as $section) {
            $isPresent = $this->sectionIsPresent($spec, $section);
            $sectionsReport[$section] = $isPresent;
            if ($isPresent) {
                $present[] = $section;
            } else {
                $missing[] = $section;
            }
        }

        if ($spec === null) {
            $status = $isHeavy ? self::STATUS_FAILED : self::STATUS_WARN;
            $reason = $isHeavy
                ? 'heavy_obra_requires_sdd_spec'
                : 'sdd_spec_absent_light_obra_advisory_only';

            return [
                'gate_id' => 'spec_complete',
                'status' => $status,
                'is_heavy_obra' => $isHeavy,
                'sections' => $sectionsReport,
                'missing_sections' => $missing,
                'present_sections' => [],
                'reasons' => [$reason],
                'remediation' => $isHeavy
                    ? 'Attach an `sdd_spec` with the 9 canonical sections before requesting certification.'
                    : 'Optionally attach an `sdd_spec` to lift this advisory.',
            ];
        }

        if ($missing === []) {
            return [
                'gate_id' => 'spec_complete',
                'status' => self::STATUS_PASSED,
                'is_heavy_obra' => $isHeavy,
                'sections' => $sectionsReport,
                'missing_sections' => [],
                'present_sections' => $present,
                'reasons' => [],
                'remediation' => null,
            ];
        }

        $status = $isHeavy ? self::STATUS_FAILED : self::STATUS_WARN;
        $reason = $isHeavy
            ? sprintf('heavy_obra_missing_required_sections:%s', implode(',', $missing))
            : sprintf('light_obra_missing_sections:%s', implode(',', $missing));

        return [
            'gate_id' => 'spec_complete',
            'status' => $status,
            'is_heavy_obra' => $isHeavy,
            'sections' => $sectionsReport,
            'missing_sections' => $missing,
            'present_sections' => $present,
            'reasons' => [$reason],
            'remediation' => sprintf(
                'Fill the SDD sections: %s. They are mandatory for heavy Obras and recommended for light ones.',
                implode(', ', $missing),
            ),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $spec
     */
    private function sectionIsPresent(?array $spec, string $section): bool
    {
        if ($spec === null || ! array_key_exists($section, $spec)) {
            return false;
        }
        $value = $spec[$section];

        if (in_array($section, ForgeIntakeCanon::SDD_LIST_SECTIONS, true)) {
            if (! is_array($value)) {
                return false;
            }
            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    return true;
                }
                if (is_array($item) && $item !== []) {
                    return true;
                }
            }

            return false;
        }

        return is_string($value) && trim($value) !== '';
    }
}
