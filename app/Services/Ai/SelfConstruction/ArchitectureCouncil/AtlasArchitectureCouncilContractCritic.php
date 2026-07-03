<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ArchitectureCouncil;

/**
 * Pure critic for proposed Self-Construction architecture contracts BEFORE they become Task Fabric
 * inputs. Examines a contract for separation-of-powers violations, missing invariants, broad-mutation
 * scope, no-evidence claims, and hidden execution side-effects.
 *
 * INPUT (contract):
 *   { organ, capability, responsibilities:list<string>, non_authority:list<string>,
 *     inputs:list<string>, outputs:list<string>, invariants:list<string>,
 *     forbidden_side_effects:list<string>, evidence_refs:list<string>,
 *     verifies?:array<string,mixed> }
 *
 * FINDING FAMILIES:
 *   - missing_non_authority      — non_authority is empty (every organ MUST declare what it does NOT own)
 *   - mixed_powers:designs_and_verifies — verifies[]['organ'] === this organ (designer is also verifier)
 *   - missing_invariants         — invariants is empty
 *   - broad_mutation:<key>       — responsibilities mention forbidden broad verbs (e.g. 'mutate global', 'edit constitution')
 *   - no_evidence_refs           — evidence_refs is empty
 *   - hidden_side_effects:<key>  — responsibilities mention 'execute', 'shell', 'git', 'commit', 'merge', 'push',
 *                                  'provider call' that is NOT covered by forbidden_side_effects
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (findings sorted).
 *   - PURE — no task packets produced, no scheduling, no commands run, no storage mutation.
 */
final class AtlasArchitectureCouncilContractCritic
{
    public const SCHEMA = 'atlas.architecturecouncil.contract_critic.v1';

    public const BROAD_MUTATION_PATTERNS = [
        '/mutate\s+global/i' => 'broad_mutation:mutate_global',
        '/edit\s+constitution/i' => 'broad_mutation:edit_constitution',
        '/edit\s+master[- ]?switch/i' => 'broad_mutation:edit_master_switch',
    ];

    public const HIDDEN_SIDE_EFFECT_PATTERNS = [
        '/(?<!do not |never |may not )execute(?!s? in sandbox|d only by)/i' => 'hidden_side_effects:execute',
        '/(?<!do not |never )invoke\s+shell|run\s+shell/i' => 'hidden_side_effects:shell',
        '/(?<!do not |never )git\s+(commit|push|merge|reset|rebase)/i' => 'hidden_side_effects:git',
        '/(?<!do not |never )(commit|merge|push)\s+to\s+main/i' => 'hidden_side_effects:main_mutation',
        '/(?<!do not |never )call\s+(an\s+)?external\s+provider/i' => 'hidden_side_effects:provider_call',
    ];

    /** generic placeholder owner names that mean "nobody concretely owns this" */
    private const VAGUE_OWNER_TERMS = ['organ', 'component', 'service', 'thing', 'module', 'system', 'unit'];

    /** finding prefixes severe enough to reject outright rather than merely request a revision. */
    private const HARD_BLOCKING_FINDING_PREFIXES = [
        'broad_mutation', 'hidden_side_effects', 'mixed_powers', 'non_atlas_steady_state_runtime',
    ];

    public const VERDICT_ACCEPT = 'accept';

    public const VERDICT_REVISE = 'revise';

    public const VERDICT_REJECT = 'reject';

    public const OVERENGINEERING_PATTERNS = [
        '/\bsingleton[\s\-]interface\b|\binterface\b.+\bone\s+implementation\b|\bone\s+implementation\b/i' => 'overengineering:singleton_interface',
        '/\bfactory[\s\-]for[\s\-]one\b|\bfactory\b.+\bone\s+product\b/i' => 'overengineering:factory_for_one',
        '/\bspeculative\s+config(uration)?\b/i' => 'overengineering:speculative_config',
        '/\btemplate[\s\-]farm\b/i' => 'template_farm',
        '/\b(operator|human|provider)\b.+\bsteady[\s\-]state\b|\bsteady[\s\-]state\b.+\b(operator|human|provider)\b/i' => 'non_atlas_steady_state_runtime',
    ];

    /**
     * @param  array{
     *     organ?:string,
     *     capability?:string,
     *     responsibilities?:list<string>,
     *     non_authority?:list<string>,
     *     inputs?:list<string>,
     *     outputs?:list<string>,
     *     invariants?:list<string>,
     *     forbidden_side_effects?:list<string>,
     *     evidence_refs?:list<string>,
     *     runtime_proof_hooks?:list<string>,
     *     outcome_learning_hooks?:list<string>,
     *     worker_feed_effects?:list<string>,
     *     verifies?:array{organ?:string}|list<array{organ?:string}>
     * }  $contract
     * @return array{schema:string, accepted:bool, findings:list<string>}
     */
    public function critique(array $contract): array
    {
        $findings = [];
        $organ = trim((string) ($contract['organ'] ?? ''));
        $resp = is_array($contract['responsibilities'] ?? null) ? array_values(array_map('strval', $contract['responsibilities'])) : [];
        $nonAuth = is_array($contract['non_authority'] ?? null) ? array_values(array_map('strval', $contract['non_authority'])) : [];
        $invariants = is_array($contract['invariants'] ?? null) ? array_values(array_map('strval', $contract['invariants'])) : [];
        $evidence = is_array($contract['evidence_refs'] ?? null) ? array_values(array_map('strval', $contract['evidence_refs'])) : [];
        $forbiddenSE = is_array($contract['forbidden_side_effects'] ?? null) ? array_values(array_map('strval', $contract['forbidden_side_effects'])) : [];
        $runtimeProofHooks = is_array($contract['runtime_proof_hooks'] ?? null) ? array_values(array_filter(array_map('strval', $contract['runtime_proof_hooks']))) : [];
        $outcomeLearningHooks = is_array($contract['outcome_learning_hooks'] ?? null) ? array_values(array_filter(array_map('strval', $contract['outcome_learning_hooks']))) : [];
        $workerFeedEffects = is_array($contract['worker_feed_effects'] ?? null) ? array_values(array_filter(array_map('strval', $contract['worker_feed_effects']))) : [];

        if ($nonAuth === []) {
            $findings[] = 'missing_non_authority';
        }

        // vague_owner: no concrete organ name, or a generic placeholder that owns nothing specific.
        if ($organ === '' || in_array(strtolower($organ), self::VAGUE_OWNER_TERMS, true)) {
            $findings[] = 'vague_owner';
        }

        // weak_rollback: every contract must declare how it is undone if it turns out to be wrong.
        if (trim((string) ($contract['rollback_plan'] ?? '')) === '') {
            $findings[] = 'weak_rollback';
        }

        // duplicate_responsibility: the same responsibility repeated (case/whitespace-insensitive)
        // inflates the contract's apparent scope without adding real distinct duties.
        $normalizedResponsibilities = array_map(static fn (string $r): string => strtolower(trim($r)), $resp);
        if (count($normalizedResponsibilities) !== count(array_unique($normalizedResponsibilities))) {
            $findings[] = 'duplicate_responsibility';
        }

        // A contract can look complete (non_authority, invariants, evidence_refs all present) while
        // never actually forcing runtime proof, outcome learning, or worker-feed effects — a proxy
        // that certifies nothing real. Any one of the three missing is enough to flag it.
        if ($runtimeProofHooks === [] || $outcomeLearningHooks === [] || $workerFeedEffects === []) {
            $findings[] = 'proxy_contract';
        }
        // missing_proof: narrower than proxy_contract — specifically no runtime_proof_hooks at all.
        if ($runtimeProofHooks === []) {
            $findings[] = 'missing_proof';
        }

        // Mixed-powers check: 'verifies' may be a single organ or a list of organs.
        $verifies = $contract['verifies'] ?? null;
        $verifierOrgans = [];
        if (is_array($verifies)) {
            if (isset($verifies['organ'])) {
                $verifierOrgans[] = (string) $verifies['organ'];
            } else {
                foreach ($verifies as $row) {
                    if (is_array($row) && isset($row['organ'])) {
                        $verifierOrgans[] = (string) $row['organ'];
                    }
                }
            }
        }
        if ($organ !== '' && in_array($organ, $verifierOrgans, true)) {
            $findings[] = 'mixed_powers:designs_and_verifies';
        }

        if ($invariants === []) {
            $findings[] = 'missing_invariants';
        }
        if ($evidence === []) {
            $findings[] = 'no_evidence_refs';
        }

        // Broad-mutation + hidden-side-effects detection over responsibilities.
        $haystack = strtolower(implode(' | ', $resp));
        foreach (self::BROAD_MUTATION_PATTERNS as $pattern => $key) {
            if (preg_match($pattern, $haystack)) {
                $findings[] = $key;
            }
        }
        foreach (self::HIDDEN_SIDE_EFFECT_PATTERNS as $pattern => $key) {
            if (preg_match($pattern, $haystack)) {
                // If the contract EXPLICITLY forbids the side-effect, the finding is suppressed.
                $parts = explode(':', $key, 2);
                $tag = $parts[1] ?? $key;
                $covered = false;
                foreach ($forbiddenSE as $f) {
                    if (stripos($f, $tag) !== false) {
                        $covered = true;
                        break;
                    }
                }
                if (! $covered) {
                    $findings[] = $key;
                }
            }
        }
        foreach (self::OVERENGINEERING_PATTERNS as $pattern => $key) {
            if (preg_match($pattern, $haystack)) {
                $findings[] = $key;
            }
        }

        $findings = array_values(array_unique($findings));
        sort($findings, SORT_STRING);

        $hasHardBlock = false;
        foreach ($findings as $finding) {
            foreach (self::HARD_BLOCKING_FINDING_PREFIXES as $prefix) {
                if (str_starts_with($finding, $prefix)) {
                    $hasHardBlock = true;
                    break 2;
                }
            }
        }
        $verdict = match (true) {
            $findings === [] => self::VERDICT_ACCEPT,
            $hasHardBlock => self::VERDICT_REJECT,
            default => self::VERDICT_REVISE,
        };

        return [
            'schema' => self::SCHEMA,
            'accepted' => $findings === [],
            'findings' => $findings,
            'verdict' => $verdict,
        ];
    }
}
