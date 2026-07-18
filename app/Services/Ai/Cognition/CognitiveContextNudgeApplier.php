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
            if (str_starts_with($framework, 'cartography') || $framework === 'kernel_vault') {
                $hits[self::FIELD_AUDIT] += 2;
            } elseif (str_starts_with($framework, 'programming') || $framework === 'sdd' || $framework === 'bdd') {
                $hits[self::FIELD_CODE] += 2;
                $hits[self::FIELD_AUDIT] += 1;
            } elseif ($framework === 'mission_mode' || $framework === 'hyperflow') {
                $hits[self::FIELD_REASONING] += 1;
                $hits[self::FIELD_RETRIEVAL] += 1;
            } elseif ($framework === 'vision' || str_starts_with($framework, 'visual')) {
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
        if ($role === 'auditor' || $role === 'reviewer') {
            $hits[self::FIELD_AUDIT] += 1;
        } elseif ($role === 'researcher' || $role === 'librarian') {
            $hits[self::FIELD_RETRIEVAL] += 1;
        } elseif ($role === 'writer' || $role === 'editor') {
            $hits[self::FIELD_GENERATION] += 1;
        } elseif ($role === 'engineer' || $role === 'developer' || $role === 'programmer') {
            $hits[self::FIELD_CODE] += 1;
        }

        return $hits;
    }
}
