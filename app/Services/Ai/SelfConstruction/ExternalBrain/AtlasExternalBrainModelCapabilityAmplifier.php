<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure amplifier: produces a model-agnostic scaffold packet that raises
 * task-origination quality for non-frontier providers without requiring
 * frontier access.
 *
 * Strictness increases as model size decreases:
 *   frontier — minimal rails; frontier-only expansion hooks enabled
 *   mid      — structured scaffold; optional expansion hook
 *   small    — maximum strictness; no frontier hooks; all critique lenses
 *
 * The output is a deterministic work packet the caller attaches to any
 * provider request. Frontier access is never required — expansion hooks are
 * optional slots the caller may ignore.
 */
final class AtlasExternalBrainModelCapabilityAmplifier
{
    public const SCHEMA = 'atlas.external_brain.model_capability_amplifier.v1';

    private const PROFILES = [
        'frontier' => [
            'scaffold_steps'            => ['write_spec', 'run_critique', 'gate_check'],
            'mandatory_evidence'        => ['critique_passed'],
            'critique_passes'           => ['proxy_risk'],
            'frontier_multiplier_hooks' => ['cross_domain_expansion', 'novel_capability_origination', 'recursive_improvement'],
            'proxy_guard_strength'      => 'low',
            'evidence_confidence_floor' => 0.40,
            'critique_passes_minimum'   => 1,
        ],
        'mid' => [
            'scaffold_steps'            => ['read_canonical_docs', 'grep_existing_symbols', 'check_duplicate', 'write_spec', 'run_critique', 'gate_check'],
            'mandatory_evidence'        => ['canonical_doc_read', 'critique_passed', 'symbol_grep_result'],
            'critique_passes'           => ['duplicate_target', 'operator_dependency', 'proxy_risk'],
            'frontier_multiplier_hooks' => ['optional_cross_domain_expansion'],
            'proxy_guard_strength'      => 'medium',
            'evidence_confidence_floor' => 0.60,
            'critique_passes_minimum'   => 3,
        ],
        'small' => [
            'scaffold_steps'            => ['check_duplicate', 'gate_check', 'grep_existing_symbols', 'read_canonical_docs', 'run_critique', 'verify_evidence', 'write_spec'],
            'mandatory_evidence'        => ['canonical_doc_read', 'critique_passed', 'duplicate_check', 'gate_passed', 'symbol_grep_result'],
            'critique_passes'           => ['duplicate_target', 'false_green_acceptance', 'low_leverage', 'operator_dependency', 'proxy_risk'],
            'frontier_multiplier_hooks' => [],
            'proxy_guard_strength'      => 'high',
            'evidence_confidence_floor' => 0.80,
            'critique_passes_minimum'   => 5,
        ],
    ];

    /**
     * @param  array<string,mixed>  $input  model_size (frontier|mid|small) + optional task/context
     * @return array<string,mixed>
     */
    public function amplify(array $input): array
    {
        $modelSize = (string) ($input['model_size'] ?? 'mid');
        $profile = self::PROFILES[$modelSize] ?? self::PROFILES['mid'];

        return [
            'schema_version'            => self::SCHEMA,
            'model_profile'             => $modelSize,
            'scaffold_steps'            => $profile['scaffold_steps'],
            'mandatory_evidence'        => $profile['mandatory_evidence'],
            'critique_passes'           => $profile['critique_passes'],
            'frontier_multiplier_hooks' => $profile['frontier_multiplier_hooks'],
            'proxy_guard_strength'      => $profile['proxy_guard_strength'],
            'evidence_confidence_floor' => $profile['evidence_confidence_floor'],
            'critique_passes_minimum'   => $profile['critique_passes_minimum'],
        ];
    }
}
