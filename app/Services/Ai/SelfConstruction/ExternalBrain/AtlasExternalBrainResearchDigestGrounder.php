<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure grounder. Converts research-inspired ideas into Atlas-safe task
 * candidates only when they are grounded in local code symbols, explicit
 * constraints, and non-hype implementation evidence.
 *
 * Rejection hierarchy per idea (first match wins):
 *   hype_only_no_local_symbol        — local_symbols is empty; no concrete code anchor
 *   no_runnable_evidence_path        — runnable_evidence_path has no artisan/phpunit/vendor command
 *   no_local_owner                   — has_local_owner is false
 *   forbidden_scope                  — forbidden_scope is true
 *   provider_steady_state_dependency — provider_steady_state_dep is true
 *
 * An idea is promoted only when ALL conditions pass.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainResearchDigestGrounder
{
    public const SCHEMA = 'atlas.external_brain.research_digest_grounder.v1';

    public const REJECTION_HYPE_ONLY_NO_LOCAL_SYMBOL        = 'hype_only_no_local_symbol';
    public const REJECTION_NO_RUNNABLE_EVIDENCE_PATH        = 'no_runnable_evidence_path';
    public const REJECTION_NO_LOCAL_OWNER                   = 'no_local_owner';
    public const REJECTION_FORBIDDEN_SCOPE                  = 'forbidden_scope';
    public const REJECTION_PROVIDER_STEADY_STATE_DEPENDENCY = 'provider_steady_state_dependency';

    private const RUNNABLE_MARKERS = ['artisan', 'vendor/bin', 'phpunit'];

    /**
     * @param  array{research_ideas?: list<array>}  $input
     * @return array{schema:string, task_candidates:list<array>, rejected:list<array>, promoted_count:int, rejected_count:int}
     */
    public function ground(array $input): array
    {
        $ideas = (array) ($input['research_ideas'] ?? []);

        $candidates = [];
        $rejected   = [];

        foreach ($ideas as $idea) {
            if (! is_array($idea)) {
                continue;
            }

            $reason = $this->reject($idea);

            if ($reason !== null) {
                $rejected[] = ['idea' => $idea, 'rejection_reason' => $reason];
            } else {
                $candidates[] = $this->buildCandidate($idea);
            }
        }

        return [
            'schema'          => self::SCHEMA,
            'task_candidates' => $candidates,
            'rejected'        => $rejected,
            'promoted_count'  => count($candidates),
            'rejected_count'  => count($rejected),
        ];
    }

    private function reject(array $idea): ?string
    {
        $localSymbols        = array_filter(array_map('trim', (array) ($idea['local_symbols']          ?? [])));
        $evidencePath        = trim((string) ($idea['runnable_evidence_path']    ?? ''));
        $hasLocalOwner       = (bool) ($idea['has_local_owner']                 ?? false);
        $forbiddenScope      = (bool) ($idea['forbidden_scope']                 ?? false);
        $providerSteadyState = (bool) ($idea['provider_steady_state_dep']       ?? false);

        if ($localSymbols === []) {
            return self::REJECTION_HYPE_ONLY_NO_LOCAL_SYMBOL;
        }

        if (! $this->hasRunnableCommand($evidencePath)) {
            return self::REJECTION_NO_RUNNABLE_EVIDENCE_PATH;
        }

        if (! $hasLocalOwner) {
            return self::REJECTION_NO_LOCAL_OWNER;
        }

        if ($forbiddenScope) {
            return self::REJECTION_FORBIDDEN_SCOPE;
        }

        if ($providerSteadyState) {
            return self::REJECTION_PROVIDER_STEADY_STATE_DEPENDENCY;
        }

        return null;
    }

    private function buildCandidate(array $idea): array
    {
        return [
            'idea_id'               => trim((string) ($idea['idea_id']               ?? '')),
            'research_note'         => trim((string) ($idea['research_note']         ?? '')),
            'local_symbols'         => array_values(array_filter(array_map('trim', (array) ($idea['local_symbols'] ?? [])))),
            'runnable_evidence_path' => trim((string) ($idea['runnable_evidence_path'] ?? '')),
            'atlas_capability_gap'  => trim((string) ($idea['atlas_capability_gap']  ?? '')),
            'risk_constraints'      => array_values(array_filter(array_map('trim', (array) ($idea['risk_constraints'] ?? [])))),
        ];
    }

    private function hasRunnableCommand(string $path): bool
    {
        $lower = strtolower($path);
        foreach (self::RUNNABLE_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }
}
