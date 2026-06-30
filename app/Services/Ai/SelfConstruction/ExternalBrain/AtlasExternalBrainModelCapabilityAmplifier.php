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
 * Escalation triggers (checked before scaffold, evaluated from input context):
 *   - proxy_leak_detected = true
 *   - confidence < evidence_confidence_floor for the profile
 *   - architectural_risk = 'high'
 *
 * When escalation triggers, the output signals escalation_recommendation.escalate=true
 * and callers MUST route to a frontier model or human review before proceeding.
 *
 * OUTPUT includes:
 *   scaffold_steps, mandatory_evidence, critique_passes, frontier_multiplier_hooks,
 *   proxy_guard_strength, evidence_confidence_floor, critique_passes_minimum,
 *   examples, verification_checklist, expected_lift, guardrails, proof_required,
 *   escalation_recommendation
 *
 * PURE / DETERMINISTIC. No I/O. Provider-agnostic.
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
            'examples'                  => [
                'cross-domain bridge: wiring two previously isolated organs via a new adapter',
                'novel origination: designing a capability that does not exist in any current file',
            ],
            'verification_checklist'    => [
                'critique_pass_logged',
                'gate_check_passed',
            ],
            'expected_lift'             => 'frontier_native_quality:minimal_rail_overhead',
            'guardrails'                => [
                'do_not_self_declare_completion_without_test_evidence',
                'do_not_fabricate_compounding_signals',
            ],
            'proof_required'            => 'critique_passed:gate_check_green',
        ],
        'mid' => [
            'scaffold_steps'            => ['read_canonical_docs', 'grep_existing_symbols', 'check_duplicate', 'write_spec', 'run_critique', 'gate_check'],
            'mandatory_evidence'        => ['canonical_doc_read', 'critique_passed', 'symbol_grep_result'],
            'critique_passes'           => ['duplicate_target', 'operator_dependency', 'proxy_risk'],
            'frontier_multiplier_hooks' => ['optional_cross_domain_expansion'],
            'proxy_guard_strength'      => 'medium',
            'evidence_confidence_floor' => 0.60,
            'critique_passes_minimum'   => 3,
            'examples'                  => [
                'add a service method that passes all existing tests and adds at least one new assertion',
                'close a documentation gap by linking a canonical doc to an orphaned symbol',
            ],
            'verification_checklist'    => [
                'canonical_doc_read_logged',
                'symbol_grep_returned_results',
                'no_duplicate_target_detected',
                'critique_passed',
                'gate_check_green',
            ],
            'expected_lift'             => 'structured_quality:reduces_hallucination_by_canonical_grounding',
            'guardrails'                => [
                'do_not_skip_duplicate_check',
                'do_not_omit_symbol_grep',
                'do_not_self_declare_completion_without_test_evidence',
                'do_not_fabricate_compounding_signals',
            ],
            'proof_required'            => 'canonical_doc_read:symbol_grep:critique_passed:gate_check_green',
        ],
        'small' => [
            'scaffold_steps'            => ['check_duplicate', 'gate_check', 'grep_existing_symbols', 'read_canonical_docs', 'run_critique', 'verify_evidence', 'write_spec'],
            'mandatory_evidence'        => ['canonical_doc_read', 'critique_passed', 'duplicate_check', 'gate_passed', 'symbol_grep_result'],
            'critique_passes'           => ['duplicate_target', 'false_green_acceptance', 'low_leverage', 'operator_dependency', 'proxy_risk'],
            'frontier_multiplier_hooks' => [],
            'proxy_guard_strength'      => 'high',
            'evidence_confidence_floor' => 0.80,
            'critique_passes_minimum'   => 5,
            'examples'                  => [
                'add a final class with one pure method, matching one existing test pattern exactly',
                'fix a single mismatched return type documented in the canonical spec',
                'wire an orphaned organ by adding exactly one caller with a test proving the call',
            ],
            'verification_checklist'    => [
                'duplicate_check_clean',
                'gate_check_green',
                'symbol_grep_found_no_conflict',
                'canonical_doc_read_confirmed',
                'all_five_critique_lenses_passed',
                'evidence_confidence_above_0.80',
                'spec_matches_implementation',
            ],
            'expected_lift'             => 'maximum_scaffold:closes_hallucination_gap_for_small_models:all_critique_lenses_active',
            'guardrails'                => [
                'do_not_skip_any_scaffold_step',
                'do_not_use_frontier_hooks',
                'do_not_claim_completion_below_confidence_0.80',
                'do_not_skip_duplicate_check',
                'do_not_omit_symbol_grep',
                'do_not_self_declare_completion_without_test_evidence',
                'do_not_fabricate_compounding_signals',
            ],
            'proof_required'            => 'all_mandatory_evidence_logged:all_five_critique_passes:gate_green:evidence_confidence>=0.80',
        ],
    ];

    /**
     * @param  array<string,mixed>  $input
     *   model_size:            'frontier'|'mid'|'small'  (default 'mid')
     *   confidence:            float 0–1  (default 1.0 — unknown = full trust, caller must supply real value)
     *   proxy_leak_detected:   bool  (default false)
     *   architectural_risk:    'low'|'medium'|'high'  (default 'low')
     * @return array<string,mixed>
     */
    public function amplify(array $input): array
    {
        $modelSize          = (string) ($input['model_size'] ?? 'mid');
        $profile            = self::PROFILES[$modelSize] ?? self::PROFILES['mid'];
        $confidence         = (float) ($input['confidence'] ?? 1.0);
        $proxyLeakDetected  = (bool) ($input['proxy_leak_detected'] ?? false);
        $architecturalRisk  = (string) ($input['architectural_risk'] ?? 'low');

        $escalation = $this->evaluateEscalation(
            $proxyLeakDetected,
            $confidence,
            $architecturalRisk,
            $profile['evidence_confidence_floor'],
        );

        return [
            'schema_version'             => self::SCHEMA,
            'model_profile'              => $modelSize,
            'scaffold_steps'             => $profile['scaffold_steps'],
            'mandatory_evidence'         => $profile['mandatory_evidence'],
            'critique_passes'            => $profile['critique_passes'],
            'frontier_multiplier_hooks'  => $profile['frontier_multiplier_hooks'],
            'proxy_guard_strength'       => $profile['proxy_guard_strength'],
            'evidence_confidence_floor'  => $profile['evidence_confidence_floor'],
            'critique_passes_minimum'    => $profile['critique_passes_minimum'],
            'examples'                   => $profile['examples'],
            'verification_checklist'     => $profile['verification_checklist'],
            'expected_lift'              => $profile['expected_lift'],
            'guardrails'                 => $profile['guardrails'],
            'proof_required'             => $profile['proof_required'],
            'escalation_recommendation'  => $escalation,
        ];
    }

    /**
     * @return array{escalate:bool, reason:string}
     */
    private function evaluateEscalation(
        bool $proxyLeakDetected,
        float $confidence,
        string $architecturalRisk,
        float $confidenceFloor,
    ): array {
        if ($proxyLeakDetected) {
            return ['escalate' => true, 'reason' => 'proxy_leak_detected:route_to_frontier_or_human_review'];
        }

        if ($confidence < $confidenceFloor) {
            return [
                'escalate' => true,
                'reason'   => sprintf(
                    'confidence_%.2f_below_floor_%.2f:insufficient_evidence_for_autonomous_execution',
                    $confidence,
                    $confidenceFloor,
                ),
            ];
        }

        if ($architecturalRisk === 'high') {
            return ['escalate' => true, 'reason' => 'high_architectural_risk:requires_frontier_or_operator_review'];
        }

        return ['escalate' => false, 'reason' => ''];
    }
}
