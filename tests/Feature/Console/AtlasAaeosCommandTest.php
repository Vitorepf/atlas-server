<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class AtlasAaeosCommandTest extends TestCase
{
    public function test_department_status_action_succeeds(): void
    {
        $this->artisan('atlas:aaeos', ['action' => 'department-status', '--json' => true])
            ->expectsOutputToContain('"schema_fields_12_present": true')
            ->assertExitCode(0);
    }

    public function test_runbook_action_lists_17_phases(): void
    {
        $this->artisan('atlas:aaeos', ['action' => 'runbook', '--json' => true])
            ->expectsOutputToContain('"phase_count": 17')
            ->assertExitCode(0);
    }

    public function test_phase_handoff_requires_intent(): void
    {
        $this->artisan('atlas:aaeos', ['action' => 'phase-handoff'])
            ->assertExitCode(1);
    }

    public function test_phase_handoff_emits_envelope(): void
    {
        $this->artisan('atlas:aaeos', [
            'action' => 'phase-handoff',
            '--intent' => 'i-1',
            '--phase-in' => 'intent_capture',
            '--phase-out' => 'disambiguation',
            '--json' => true,
        ])
            ->expectsOutputToContain('"schema": "atlas.aaeos.phase.v1"')
            ->assertExitCode(0);
    }

    public function test_universal_gates_requires_intent(): void
    {
        $this->artisan('atlas:aaeos', ['action' => 'universal-gates'])
            ->assertExitCode(1);
    }

    public function test_universal_gates_derives_delivery_pack_completeness_signal(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-delivery-pack-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'changed_files' => 1,
            'test_evidence' => ['t'],
            'no_test_reason' => '',
            'evidence_hashes' => ['h'],
            'risk_register_present' => true,
            'receipt_present' => true,
            'delivery_hash' => 'sha256:signed',
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-delivery',
                '--delivery-pack' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('delivery_pack_completeness_min_0_95')
                ->assertExitCode(1); // other gates still missing → pending/non-green
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_spec_completeness_signal(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-spec-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-spec',
                '--spec' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"spec_completeness": false')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_quality_bar_telemetry(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-qb-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'department_id' => 'qa',
            'breach_count' => 1,
            'evidence_hash' => 'sha256:qb',
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-qb',
                '--quality-bar' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"quality_bar_telemetry"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_architect_spec_pack(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-asp-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'risk_scope' => 'R4',
            'spec_pack_hash' => 'sha256:sp',
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-asp',
                '--architect-spec-pack' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"architect_spec_pack_gate"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_predicted_impact_band(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pib-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'rung' => 'slice',
            'rank' => 2,
            'path_yield' => 0.4,
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pib',
                '--predicted-impact' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"predicted_impact_band"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_predicted_impact_calibration(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pic-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'rows' => [
                ['band' => 'sweet', 'status' => 'resolved', 'realized' => false],
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pic',
                '--predicted-impact-calibration' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"predicted_impact_calibration"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_pre_review_advisory(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pra-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'target_class' => 'ops',
            'n_similar' => 2,
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pra',
                '--pre-review' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"pre_review_advisory"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_reality_compiler_slice(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rcs-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'intent' => 'slice-1',
            'autonomy_level' => 'L1',
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rcs',
                '--reality-compiler-slice' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"reality_compiler_slice"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_esp09_challenger(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-esp09-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'author_engine_id' => 'a',
            'challenger_engine_id' => 'b',
            'decision_kind' => 'ordinary_route',
            'operator_alignment' => 0.1,
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-esp09',
                '--esp09-challenger' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"esp09_challenger"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_esp09_promotion_gate(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-esp09pg-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'requires_challenger' => true,
            'challenger_block_present' => true,
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-esp09pg',
                '--esp09-promotion-gate' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"esp09_promotion_gate"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_esp09_refutation_series(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-esp09rs-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'events' => [
                ['outcome' => 'accepted', 'window' => 'w1'],
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-esp09rs',
                '--esp09-refutation-series' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"esp09_refutation_series"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_dogfooding_leads(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dog-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'events' => [
                ['signature' => 'x', 'target' => 'y'],
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dog',
                '--dogfooding-leads' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"dogfooding_friction_leads"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_reactive_saturation(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rs-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'windows' => [
                ['n' => 1, 'yield' => 0.5],
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rs',
                '--reactive-saturation' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"reactive_saturation"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_blocker_severity(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-bs-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'blockers' => [
                ['id' => 'x', 'severity' => 'low', 'owner' => 'atlas-ai'],
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-bs',
                '--blocker-severity' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"blocker_severity"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_phase_advance(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pa-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'phase_out' => 'spec',
            'gates' => ['required' => [], 'passed' => [], 'blocked' => []],
            'blockers' => [],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pa',
                '--phase-advance' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"phase_advance_verdict"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_required_gate_coverage(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rgc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'required' => ['a'],
            'passed' => ['a'],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rgc',
                '--required-gate-coverage' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"required_gate_coverage"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_outcome_causality(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-oc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'outcome' => 'failed',
            'has_evidence_refs' => true,
            'tests_passed' => false,
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-oc',
                '--outcome-causality' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"outcome_causality"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_summary_fidelity(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-sf-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'required_items' => [
                ['id' => 'dec-1', 'kind' => 'decision', 'digest' => 'catalogue stays 15'],
            ],
            'summary_text' => 'The catalogue stays 15.',
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-sf',
                '--summary-fidelity' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"summary_fidelity_coverage"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_memory_injection_budget(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-mib-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'ranked_items' => [
                ['ref' => 'a', 'priority' => 90, 'estimated_chars' => 100],
            ],
            'total_budget_chars' => 200,
            'per_item_cap_chars' => 100,
            'min_excerpt_chars' => 20,
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-mib',
                '--memory-injection-budget' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"memory_injection_budget"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_memory_feedback_decay(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-mfd-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'positive_count' => 1,
            'negative_count' => 0,
            'wrong_context_count' => 0,
            'stale_count' => 0,
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-mfd',
                '--memory-feedback-decay' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"memory_feedback_decay"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_segment_importance(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-si-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'segments' => [
                [
                    'id' => 's1',
                    'kind' => 'fact',
                    'recency_rank' => 0,
                    'token_estimate' => 5,
                    'has_evidence_ref' => false,
                    'links_decision_or_blocker' => false,
                ],
            ],
            'token_budget' => 20,
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-si',
                '--segment-importance' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"segment_importance"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_context_pareto(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-cp-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'variants' => [
                ['id' => 'a', 'quality' => 1.0, 'cost' => 0.1],
            ],
            'objective_direction' => [
                'quality' => 'maximize',
                'cost' => 'minimize',
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-cp',
                '--context-pareto' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"context_pareto_dominance"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_memory_recall_rank(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-mrr-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'rows' => [
                [
                    'title' => 'A',
                    'type' => 'memory',
                    'scope_type' => 'global',
                    'priority' => 50,
                    'importance' => 3,
                    'confidence' => 0.7,
                    'hybrid_score' => 0,
                ],
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-mrr',
                '--memory-recall-rank' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"memory_recall_rank"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_portfolio_budget(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pb-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'default_mix' => ['reactive' => 0.5, 'originated' => 0.3, 'maintenance' => 0.2],
            'operator_weights' => ['reactive' => 0.5, 'originated' => 0.3, 'maintenance' => 0.2],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pb',
                '--portfolio-budget' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"portfolio_budget"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_ambition_rung(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ar-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'candidates' => [
                ['id' => 't1', 'rung' => 'task', 'leverage' => 1.0],
            ],
            'context' => ['enabled' => false],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ar',
                '--ambition-rung' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"ambition_rung"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_domain_lexical(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dl-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'query' => 'memoria',
            'fields' => ['memory store'],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dl',
                '--domain-lexical' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"domain_lexical"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_gated_corpus(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-gc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'sources' => [
                ['ref' => 'doc:1', 'text' => 'ok', 'privacy_class' => 'normal'],
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-gc',
                '--gated-corpus' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"gated_corpus_candidates"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_structured_facts(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-sfacts-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'memory_type' => 'gotcha',
            'facts' => ['sintoma' => 'x'],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-sfacts',
                '--structured-facts' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"structured_fact_schema"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_citation_grounding(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-cg-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'responses' => [
                ['response' => 'ref=memory:x', 'delivered_refs' => ['memory:x']],
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-cg',
                '--citation-grounding' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"citation_grounding"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_provenance_weight(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pw-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'evidence_refs' => ['ev:1', 'missing'],
            'verified_refs' => ['ev:1'],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pw',
                '--provenance-weight' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"provenance_weight"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_recall_gap(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rg-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'events' => [
                ['query' => 'missing concept', 'top_score' => 0.0],
                ['query' => 'missing concept', 'top_score' => 0.1],
                ['query' => 'missing concept', 'top_score' => 0.2],
            ],
            'min_occurrences' => 3,
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rg',
                '--recall-gap' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"recall_gap"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_belief_cascade(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-bc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'origin' => 'A',
            'graph' => ['A' => ['B']],
            'depth_cap' => 2,
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-bc',
                '--belief-cascade' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"belief_cascade"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_ledger_rotation(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-lr-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'series' => 'atlas.evidence_ledger.hash_chain.v1',
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-lr',
                '--ledger-rotation' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"ledger_rotation"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_evidence_vision(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ev-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'thesis' => [
                'claim' => 'ok',
                'death_criterion' => ['described_at_birth' => 'x'],
                'evidence' => [['source' => 'series', 'ref' => 'series:a']],
            ],
            'forbidden' => [],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ev',
                '--evidence-vision' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"evidence_vision"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_gate_signal_spec_pack(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-gssp-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'acceptance_criteria' => ['a', 'b', 'c'],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-gssp',
                '--gate-signal-spec-pack' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"gate_signal_spec_pack"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_gate_signal_intent(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-gsi-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'resolved_target' => 'app/Services/Ai/Aaeos/Foo.php',
            'scope_bounded' => true,
            'ambiguity_tokens' => [],
            'missing_answers' => [],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-gsi',
                '--gate-signal-intent' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"gate_signal_intent"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_gate_signal_task_pack(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-gstp-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'tasks' => [['scope' => 'build login', 'acceptance' => 'renders']],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-gstp',
                '--gate-signal-task-pack' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"gate_signal_task_pack"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_gate_signal_phase(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-gsp-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'intent' => [
                'resolved_target' => 'app/Services/Ai/Aaeos/Foo.php',
                'scope_bounded' => true,
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-gsp',
                '--gate-signal-phase' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"gate_signal_phase"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_threshold_ladder(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-tl-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'band_ladder' => [
                [
                    'level' => 'green',
                    'thresholds' => [
                        ['metric' => 'coverage', 'comparator' => '>=', 'value' => 0.9],
                    ],
                ],
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-tl',
                '--threshold-ladder' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"threshold_ladder"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_kb_embedding_coverage(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-kb-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-kb',
                '--kb-embedding-coverage' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"kb_embedding_coverage"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_code_symbol_embedding_coverage(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-cs-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-cs',
                '--code-symbol-embedding-coverage' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"code_symbol_embedding_coverage"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_predicted_revert_digest(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-prd-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'items' => [
                [
                    'id' => 'r1',
                    'title' => 'Review me',
                    'predicted_revert_band' => 'high',
                ],
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-prd',
                '--predicted-revert-digest' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"predicted_revert_digest"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_jina_dual_read_ledger(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-jina-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'path' => sys_get_temp_dir().'/atlas-jina-observe.jsonl',
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-jina',
                '--jina-dual-read-ledger' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"jina_dual_read_ledger"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_unknown_action_fails(): void
    {
        $this->artisan('atlas:aaeos', ['action' => 'wibble'])
            ->assertExitCode(1);
    }
}
