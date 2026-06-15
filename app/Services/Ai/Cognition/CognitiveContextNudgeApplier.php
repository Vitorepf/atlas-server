<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

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
        $framework = (string) ($context['framework'] ?? '');
        $framework = strtolower($framework);
        $hits = $this->applyFrameworkNudge($hits, $framework);

        // Role nudges.
        $role = strtolower((string) ($context['role'] ?? ''));
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
                $hits['audit'] += 2;
            } elseif (str_starts_with($framework, 'programming') || $framework === 'sdd' || $framework === 'bdd') {
                $hits['code'] += 2;
                $hits['audit'] += 1;
            } elseif ($framework === 'mission_mode' || $framework === 'hyperflow') {
                $hits['reasoning'] += 1;
                $hits['retrieval'] += 1;
            } elseif ($framework === 'vision' || str_starts_with($framework, 'visual')) {
                $hits['vision'] += 2;
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
            $hits['audit'] += 1;
        } elseif ($role === 'researcher' || $role === 'librarian') {
            $hits['retrieval'] += 1;
        } elseif ($role === 'writer' || $role === 'editor') {
            $hits['generation'] += 1;
        } elseif ($role === 'engineer' || $role === 'developer' || $role === 'programmer') {
            $hits['code'] += 1;
        }

        return $hits;
    }
}
