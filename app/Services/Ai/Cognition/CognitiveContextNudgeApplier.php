<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Cognitive Context Nudge Applier — extracted collaborator of
 * {@see AtlasCognitiveFunctionDecomposerService}.
 *
 * Applies the framework + role context nudges to the keyword-scored hits map,
 * relocated VERBATIM from AtlasCognitiveFunctionDecomposerService::decompose()
 * (the framework if/elseif ladder + the role if/elseif ladder). Pure function:
 * no host state, no rules, no normalize(), no I/O. First-match / single-arm
 * semantics are preserved exactly (elseif short-circuit), so the resulting
 * hits map — and therefore the downstream weights, dominant axis and
 * decomposition_hash — are byte-identical to the in-line original.
 */
final class CognitiveContextNudgeApplier
{
    public const FIELD_CODE = 'code';
    public const FIELD_REASONING = 'reasoning';
    public const FIELD_AUDIT = 'audit';
    public const FIELD_RETRIEVAL = 'retrieval';
    public const FIELD_VISION = 'vision';
    public const FIELD_GENERATION = 'generation';
    public const FIELD_FRAMEWORK = 'framework';
    public const FIELD_ROLE = 'role';
    public const FIELD_AUDITOR = 'auditor';
    public const FIELD_BDD = 'bdd';
    public const FIELD_CARTOGRAPHY = 'cartography';
    public const FIELD_DEVELOPER = 'developer';
    public const FIELD_EDITOR = 'editor';
    public const FIELD_ENGINEER = 'engineer';
    public const FIELD_HYPERFLOW = 'hyperflow';
    public const FIELD_KERNEL_VAULT = 'kernel_vault';
    public const FIELD_LIBRARIAN = 'librarian';
    public const FIELD_MISSION_MODE = 'mission_mode';
    public const FIELD_PROGRAMMER = 'programmer';
    public const FIELD_PROGRAMMING = 'programming';
    public const FIELD_RESEARCHER = 'researcher';
    public const FIELD_REVIEWER = 'reviewer';
    public const FIELD_SDD = 'sdd';
    public const FIELD_VISUAL = 'visual';
    /**
     * Apply framework + role nudges to the keyword-scored hits map.
     *
     * @param  array<string,int>  $hits
     * @param  array<string,mixed>  $context
     * @return array<string,int>
     */
    public function applyNudges(array $hits, array $context): array
    {
        // Framework nudges.
        $framework = AiValueNormalizer::lowerTrimmedString($context[self::FIELD_FRAMEWORK] ?? '');
        $hits = $this->applyFrameworkNudge($hits, $framework);

        // Role nudges.
        $role = AiValueNormalizer::lowerTrimmedString($context[self::FIELD_ROLE] ?? '');
        $hits = $this->applyRoleNudge($hits, $role);

        return $hits;
    }

    /**
     * @param  array<string,int>  $hits
     * @return array<string,int>
     */
    private function applyFrameworkNudge(array $hits, string $framework): array
    {
        if ($framework !== '') {
            if (str_starts_with($framework, self::FIELD_CARTOGRAPHY) || $framework === self::FIELD_KERNEL_VAULT) {
                $hits[self::FIELD_AUDIT] += 2;
            } elseif (str_starts_with($framework, self::FIELD_PROGRAMMING) || $framework === self::FIELD_SDD || $framework === self::FIELD_BDD) {
                $hits[self::FIELD_CODE] += 2;
                $hits[self::FIELD_AUDIT] += 1;
            } elseif ($framework === self::FIELD_MISSION_MODE || $framework === self::FIELD_HYPERFLOW) {
                $hits[self::FIELD_REASONING] += 1;
                $hits[self::FIELD_RETRIEVAL] += 1;
            } elseif ($framework === 'vision' || str_starts_with($framework, self::FIELD_VISUAL)) {
                $hits[self::FIELD_VISION] += 2;
            }
        }

        return $hits;
    }

    /**
     * @param  array<string,int>  $hits
     * @return array<string,int>
     */
    private function applyRoleNudge(array $hits, string $role): array
    {
        if ($role === self::FIELD_AUDITOR || $role === self::FIELD_REVIEWER) {
            $hits[self::FIELD_AUDIT] += 1;
        } elseif ($role === self::FIELD_RESEARCHER || $role === self::FIELD_LIBRARIAN) {
            $hits[self::FIELD_RETRIEVAL] += 1;
        } elseif ($role === 'writer' || $role === self::FIELD_EDITOR) {
            $hits[self::FIELD_GENERATION] += 1;
        } elseif ($role === self::FIELD_ENGINEER || $role === self::FIELD_DEVELOPER || $role === self::FIELD_PROGRAMMER) {
            $hits[self::FIELD_CODE] += 1;
        }

        return $hits;
    }
}
