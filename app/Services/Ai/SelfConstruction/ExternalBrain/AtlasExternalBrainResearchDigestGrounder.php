<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure grounder. Converts research-inspired ideas into Atlas-safe task
 * candidates only when they are grounded in local code symbols, explicit
 * constraints, and non-hype implementation evidence.
 *
 * REJECTION HIERARCHY (first match wins):
 *   hype_only           — is_hype=true (explicitly flagged as speculative/buzzword-only)
 *   missing_local_symbol — local_symbols is empty; no concrete code anchor
 *   missing_allowed_files_candidate — allowed_files_candidate is empty
 *   no_runnable_gate    — runnable_evidence_path lacks a runnable command marker
 *   no_owner_file       — owner_files empty AND has_local_owner=false
 *   forbidden_scope     — forbidden_scope=true
 *   provider_dependency — provider_steady_state_dep=true
 *   no_capability_delta — atlas_capability_gap is empty (idea adds no measurable capability)
 *
 * PROMOTED CANDIDATE FIELDS:
 *   idea_id, local_symbols, allowed_files_candidate, acceptance_seed,
 *   atlas_capability_gap, risk_constraints, leverage_hint,         ← original fields
 *   grounded_symbols, owner_files, implementation_strategy,         ← new Task-Fabric fields
 *   risk_level, task_family
 *
 * An idea is promoted only when ALL conditions pass.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainResearchDigestGrounder
{
    public const SCHEMA = 'atlas.external_brain.research_digest_grounder.v1';

    // ── Canonical rejection reasons (short names, active in chain) ────────────
    public const REJECTION_HYPE_ONLY            = 'hype_only';
    public const REJECTION_MISSING_LOCAL_SYMBOL  = 'missing_local_symbol';
    public const REJECTION_NO_RUNNABLE_GATE      = 'no_runnable_gate';
    public const REJECTION_NO_OWNER_FILE         = 'no_owner_file';
    public const REJECTION_FORBIDDEN_SCOPE       = 'forbidden_scope';
    public const REJECTION_PROVIDER_DEPENDENCY   = 'provider_dependency';
    public const REJECTION_NO_CAPABILITY_DELTA   = 'no_capability_delta';

    // ── Retained constants (unchanged string values) ──────────────────────────
    public const REJECTION_MISSING_ALLOWED_FILES_CANDIDATE  = 'missing_allowed_files_candidate';
    // Deprecated aliases — old string values kept so external callers survive.
    public const REJECTION_HYPE_ONLY_NO_LOCAL_SYMBOL        = 'hype_only_no_local_symbol';
    public const REJECTION_NO_RUNNABLE_EVIDENCE_PATH        = 'no_runnable_evidence_path';
    public const REJECTION_NO_LOCAL_OWNER                   = 'no_local_owner';
    public const REJECTION_PROVIDER_STEADY_STATE_DEPENDENCY = 'provider_steady_state_dependency';

    private const RUNNABLE_MARKERS = ['/opt/homebrew/bin/php', 'artisan', 'vendor/bin', 'phpunit'];

    /**
     * @param  array{research_ideas?: list<array<string,mixed>>}  $input
     * @return array{schema:string, task_candidates:list<array<string,mixed>>, rejected:list<array<string,mixed>>, promoted_count:int, rejected_count:int}
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
        $isHype              = (bool) ($idea['is_hype']                    ?? false);
        $localSymbols        = array_filter(array_map('trim', (array) ($idea['local_symbols']           ?? [])));
        $allowedFiles        = array_filter(array_map('trim', (array) ($idea['allowed_files_candidate'] ?? [])));
        $evidencePath        = trim((string) ($idea['runnable_evidence_path']    ?? ''));
        $ownerFiles          = array_filter(array_map('trim', (array) ($idea['owner_files']             ?? [])));
        $hasLocalOwner       = (bool) ($idea['has_local_owner']            ?? false);
        $forbiddenScope      = (bool) ($idea['forbidden_scope']            ?? false);
        $providerSteadyState = (bool) ($idea['provider_steady_state_dep'] ?? false);
        $capabilityGap       = trim((string) ($idea['atlas_capability_gap'] ?? ''));

        if ($isHype) {
            return self::REJECTION_HYPE_ONLY;
        }

        if ($localSymbols === []) {
            return self::REJECTION_MISSING_LOCAL_SYMBOL;
        }

        if ($allowedFiles === []) {
            return self::REJECTION_MISSING_ALLOWED_FILES_CANDIDATE;
        }

        if (! $this->hasRunnableCommand($evidencePath)) {
            return self::REJECTION_NO_RUNNABLE_GATE;
        }

        if ($ownerFiles === [] && ! $hasLocalOwner) {
            return self::REJECTION_NO_OWNER_FILE;
        }

        if ($forbiddenScope) {
            return self::REJECTION_FORBIDDEN_SCOPE;
        }

        if ($providerSteadyState) {
            return self::REJECTION_PROVIDER_DEPENDENCY;
        }

        if ($capabilityGap === '') {
            return self::REJECTION_NO_CAPABILITY_DELTA;
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function buildCandidate(array $idea): array
    {
        $localSymbols = array_values(array_filter(array_map('trim', (array) ($idea['local_symbols']           ?? []))));
        $ownerFiles   = array_values(array_filter(array_map('trim', (array) ($idea['owner_files']             ?? []))));

        return [
            // Original fields (backward compat).
            'idea_id'                 => trim((string) ($idea['idea_id']                ?? '')),
            'local_symbols'           => $localSymbols,
            'allowed_files_candidate' => array_values(array_filter(array_map('trim', (array) ($idea['allowed_files_candidate'] ?? [])))),
            'acceptance_seed'         => trim((string) ($idea['acceptance_seed']         ?? '')),
            'atlas_capability_gap'    => trim((string) ($idea['atlas_capability_gap']    ?? '')),
            'risk_constraints'        => array_values(array_filter(array_map('trim', (array) ($idea['risk_constraints']        ?? [])))),
            'leverage_hint'           => trim((string) ($idea['leverage_hint']           ?? '')),
            // Task-Fabric fields (new).
            'grounded_symbols'        => $localSymbols,
            'owner_files'             => $ownerFiles,
            'implementation_strategy' => trim((string) ($idea['implementation_strategy'] ?? ($idea['implementation_detail'] ?? ''))),
            'risk_level'              => trim((string) ($idea['risk_level']              ?? 'medium')),
            'task_family'             => trim((string) ($idea['task_family']             ?? 'feature')),
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
