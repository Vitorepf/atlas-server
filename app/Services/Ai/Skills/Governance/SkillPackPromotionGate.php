<?php

declare(strict_types=1);

namespace App\Services\Ai\Skills\Governance;

/**
 * Skills system — Enterprise Pack Promotion Gate.
 *
 * Closes the matrix gap "Skills system: Promotion para skill packs
 * enterprise por dominio ainda precisa APs".
 *
 * A "skill pack" is a bundled set of typed agent capabilities (e.g.,
 * `finance.accounts_payable`, `programming.repair_orchestrator`). New
 * enterprise packs MUST pass this gate before they join the catalog.
 *
 * The gate enforces:
 *
 *   - manifest with `schema_version`, `pack_id`, `domain`, `version`
 *   - declared `quality_gates` list (must include at least 3 gates)
 *   - declared `evidence_contracts` list (which receipts the pack emits)
 *   - per-skill `policy_class` (read|mutating|danger|tool)
 *   - canon docs cross-reference (`canon_docs` non-empty)
 *   - operator authority required for `danger` skills
 */
final class SkillPackPromotionGate
{
    public const SCHEMA_VERSION = 'atlas.skills.pack_promotion.v1';

    public const REQUIRED_FIELDS = [
        'pack_id',
        'schema_version',
        'domain',
        'version',
        'quality_gates',
        'evidence_contracts',
        'skills',
        'canon_docs',
    ];

    public const ALLOWED_POLICY_CLASSES = ['read', 'mutating', 'danger', 'tool'];

    public const MIN_QUALITY_GATES = 3;

    /**
     * @param  array<string,mixed>  $manifest
     * @return array{
     *   schema_version: string,
     *   pack_id: ?string,
     *   domain: ?string,
     *   promotion_decision: string,
     *   passed_checks: list<string>,
     *   failed_checks: array<string,string>,
     *   detail: string,
     *   evaluated_at: string
     * }
     */
    public function evaluate(array $manifest): array
    {
        $passed = [];
        $failed = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $manifest[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $failed[$field] = "required field '{$field}' missing or empty";
            } else {
                $passed[] = $field.'_present';
            }
        }

        $gates = (array) ($manifest['quality_gates'] ?? []);
        if (count($gates) < self::MIN_QUALITY_GATES) {
            $failed['quality_gates'] = sprintf(
                'pack must declare >= %d quality gates; got %d',
                self::MIN_QUALITY_GATES,
                count($gates),
            );
        } else {
            $passed[] = 'quality_gates_min_threshold';
        }

        $skills = (array) ($manifest['skills'] ?? []);
        if ($skills !== []) {
            foreach ($skills as $i => $skill) {
                if (! is_array($skill)) {
                    $failed["skills[{$i}]"] = 'must be an array';

                    continue;
                }
                $skillId = (string) ($skill['skill_id'] ?? '');
                if ($skillId === '') {
                    $failed["skills[{$i}].skill_id"] = 'required';
                }
                $policy = (string) ($skill['policy_class'] ?? '');
                if (! in_array($policy, self::ALLOWED_POLICY_CLASSES, true)) {
                    $failed["skills[{$i}].policy_class"] = sprintf(
                        'invalid "%s"; must be one of [%s]',
                        $policy,
                        implode(',', self::ALLOWED_POLICY_CLASSES),
                    );
                }
                if ($policy === 'danger' && ($skill['operator_authority_required'] ?? null) !== true) {
                    $failed["skills[{$i}].operator_authority_required"] = 'danger skill requires explicit operator_authority_required=true';
                }
            }
            if (! array_filter(array_keys($failed), static fn ($k) => str_starts_with($k, 'skills['))) {
                $passed[] = 'skills_individually_valid';
            }
        }

        $version = (string) ($manifest['version'] ?? '');
        if ($version !== '' && ! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            $failed['version'] = sprintf('version "%s" must follow semver MAJOR.MINOR.PATCH', $version);
        } elseif ($version !== '') {
            $passed[] = 'version_semver_valid';
        }

        $decision = $failed === [] ? 'promotion_approved' : 'promotion_blocked';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'pack_id' => isset($manifest['pack_id']) && is_string($manifest['pack_id']) ? $manifest['pack_id'] : null,
            'domain' => isset($manifest['domain']) && is_string($manifest['domain']) ? $manifest['domain'] : null,
            'promotion_decision' => $decision,
            'passed_checks' => $passed,
            'failed_checks' => $failed,
            'detail' => $decision === 'promotion_approved'
                ? sprintf('Skill pack "%s" approved for enterprise promotion.', $manifest['pack_id'] ?? 'unknown')
                : sprintf('Promotion blocked: %d check(s) failed.', count($failed)),
            'evaluated_at' => now()->toAtomString(),
        ];
    }
}
