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

    public function test_universal_gates_observe_resource_budget(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rb-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'budget' => [
                'schema_version' => 'atlas.resource_budget.v1',
                'host_ram_gib' => 4,
                'engine_floor_gib' => 1,
                'components' => [
                    [
                        'name' => 'cli_worker',
                        'purpose' => 'observe',
                        'ram_cap_mb' => 128,
                        'disk_cap_mb' => 32,
                        'cpu_share' => 'shared',
                    ],
                ],
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rb',
                '--resource-budget' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"resource_budget"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_model_capability_spec(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-mcs-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'function' => 'dense_embed',
            'model' => [
                'model_id' => 'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2',
                'dim' => 384,
                'pooling' => 'mean',
                'ctx_tokens' => 512,
                'multilingual_pt' => true,
                'deterministic' => true,
                'license' => 'apache-2.0',
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-mcs',
                '--model-capability-spec' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"model_capability_spec"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_measure_series_freshness(): void
    {
        $jsonl = sys_get_temp_dir().'/atlas-aaeos-msf-'.uniqid('', true).'.jsonl';
        file_put_contents($jsonl, json_encode(['recorded_at' => '2026-03-01T00:00:00Z'])."\n");
        $path = sys_get_temp_dir().'/atlas-aaeos-msf-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'series' => 'acos.cli.freshness',
            'source_type' => 'jsonl',
            'path' => $jsonl,
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-msf',
                '--measure-series-freshness' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"measure_series_freshness"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
            @unlink($jsonl);
        }
    }

    public function test_universal_gates_observe_verified_share(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-vs-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(['days' => 7]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-vs',
                '--verified-share' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"verified_share"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_ragx_chain(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ragx-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(['deps' => []]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ragx',
                '--ragx-chain' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"ragx_chain"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_procedural_skill_promoter(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-psp-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(['floor' => 8]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-psp',
                '--procedural-skill-promoter' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"procedural_skill_promoter"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_aaeos_phase_router(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-apr-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(['phase' => '3']));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-apr',
                '--aaeos-phase-router' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"aaeos_phase_router"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_aaeos_quality_bar(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-aqb-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-aqb',
                '--aaeos-quality-bar' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"aaeos_quality_bar"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_aaeos_department_maturity(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-adm-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-adm',
                '--aaeos-department-maturity' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"aaeos_department_maturity"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_veto_propagation_watchdog(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-vpw-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'events' => [
                ['department' => 'security'],
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-vpw',
                '--veto-propagation-watchdog' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"veto_propagation_watchdog"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_repair_loop_guard(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rlg-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(['current_iteration' => 1]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rlg',
                '--repair-loop-guard' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"repair_loop_guard"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_generated_contract_gate(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-gcg-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-gcg',
                '--generated-contract-gate' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"generated_contract_gate"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_maturity_band_classifier(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-mbc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'band_ladder' => [
                [
                    'band' => 'L1',
                    'rank' => 1,
                    'thresholds' => [
                        ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.5],
                    ],
                ],
            ],
            'metrics_snapshot' => ['obra_completion_rate' => 0.9],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-mbc',
                '--maturity-band-classifier' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"maturity_band_classifier"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_promotion_eligibility(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pe-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'department' => [
                'current_tier' => 2,
                'blockers_to_next' => [['id' => 'sec-audit', 'resolved' => true]],
                'last_evaluation' => '2026-05-25T00:00:00+00:00',
            ],
            'metrics' => [
                'current_score' => 85.0,
                'tier_thresholds' => [1 => 50.0, 2 => 65.0, 3 => 80.0],
            ],
            'options' => [
                'as_of' => '2026-05-30T00:00:00+00:00',
                'max_evidence_age_days' => 30,
                'max_tier' => 5,
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pe',
                '--promotion-eligibility' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"promotion_eligibility"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_debug_root_cause(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-drc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(['suspected_cause' => 'flaky_gate']));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-drc',
                '--debug-root-cause' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"debug_root_cause"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_cross_department_choreography(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-cdc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'mode' => 'veto',
            'department' => 'security',
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-cdc',
                '--cross-department-choreography' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"cross_department_choreography"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_docs_authority_locate(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dal-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(['needle' => 'aaeos']));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dal',
                '--docs-authority-locate' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"docs_authority_locate"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_department_level_classifier(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dlc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'department_id' => 'forge',
            'metrics_snapshot' => ['obra_completion_rate' => 0.95],
            'band_ladder' => [
                [
                    'level' => 'L1',
                    'thresholds' => [
                        ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.5],
                    ],
                ],
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dlc',
                '--department-level-classifier' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"department_level_classifier"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_quality_bar_level_classifier(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-qblc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'department_id' => 'forge',
            'measured_metrics' => ['obra_completion_rate' => 0.95],
            'band_ladder' => [
                [
                    'level' => 'L1',
                    'thresholds' => [
                        ['metric' => 'obra_completion_rate', 'comparator' => '>=', 'value' => 0.5],
                    ],
                ],
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-qblc',
                '--quality-bar-level-classifier' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"quality_bar_level_classifier"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_implementation_truth_evaluate(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ite-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'claimed_state' => 'verified',
            'resolutions' => [
                ['kind' => 'symbol', 'ref' => 'Foo', 'resolved' => true, 'matched' => 'Foo'],
                ['kind' => 'route', 'ref' => '/x', 'resolved' => true, 'matched' => '/x'],
            ],
            'green_test_run' => false,
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ite',
                '--implementation-truth-evaluate' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"implementation_truth_evaluate"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_phase_handoff_catalogue(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-phc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(['autonomy_level' => 'L3']));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-phc',
                '--phase-handoff-catalogue' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"phase_handoff_catalogue"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_golden_counterfactual_replay(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-gcr-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-gcr',
                '--golden-counterfactual-replay' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"golden_counterfactual_replay"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_composed_obra_arc(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-coa-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'candidates' => [],
            'context' => ['enabled' => false],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-coa',
                '--composed-obra-arc' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"composed_obra_arc"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_exploratory_bets_portfolio(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ebp-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'candidates' => [],
            'context' => ['enabled' => false],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ebp',
                '--exploratory-bets-portfolio' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"exploratory_bets_portfolio"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_n_capture_drill(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ncd-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(['days' => 7]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ncd',
                '--n-capture-drill' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"n_capture_drill"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_lote2_counterfactual_lift(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-l2c-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-l2c',
                '--lote2-counterfactual-lift' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"lote2_counterfactual_lift"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_string_list_normalize(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-sln-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(['values' => ['  alpha  ', 'beta']]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-sln',
                '--string-list-normalize' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"string_list_normalize"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_threshold_comparator(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-tc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'comparator' => '>=',
            'observed' => 1.0,
            'threshold' => 0.5,
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-tc',
                '--threshold-comparator' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"threshold_comparator"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_evidence_ref_normalize(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ern-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'evidence_refs' => ['ledger:abc'],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ern',
                '--evidence-ref-normalize' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"evidence_ref_normalize"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_doc_maturity_classify(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dmc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'sections' => [
                'mother_doc' => true,
                'contracts' => false,
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dmc',
                '--doc-maturity-classify' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"doc_maturity_classify"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_claim_definition_of_done(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-cdod-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'claim' => [
                'owner_doc' => 'docs/x.md',
                'documental_state' => 'complete',
                'runtime_state' => 'complete',
                'proof' => 'tests green',
                'code_command_applicable' => false,
            ],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-cdod',
                '--claim-definition-of-done' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"claim_definition_of_done"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_array_field_reader(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-afr-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'row' => ['id' => 'alpha'],
            'key' => 'id',
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-afr',
                '--array-field-reader' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"array_field_reader"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_veto_propagation_resolve(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-vpr-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'origin_department' => 'review',
            'veto_kind' => 'delivery',
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-vpr',
                '--veto-propagation-resolve' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"veto_propagation_resolve"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_department_registry_validate(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-drv-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'department' => ['id' => 'dev', 'human_name' => 'Dev'],
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-drv',
                '--department-registry-validate' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"department_registry_validate"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_cognitive_immune_classify(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-cic-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'text' => 'hello world',
        ]));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-cic',
                '--cognitive-immune-classify' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"cognitive_immune_classify"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_department_canonical_list(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dcl-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dcl',
                '--department-canonical-list' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"department_canonical_list"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_universal_gates_catalogue(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ugc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ugc',
                '--universal-gates-catalogue' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"universal_gates_catalogue"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_outcome_attribution_types(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-oat-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-oat',
                '--outcome-attribution-types' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"outcome_attribution_types"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_phase_router_valid_phases(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-prvp-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-prvp',
                '--phase-router-valid-phases' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"phase_router_valid_phases"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_choreography_handoff_kinds(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-chk-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-chk',
                '--choreography-handoff-kinds' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"choreography_handoff_kinds"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_reality_compiler_phases(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rcp-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rcp',
                '--reality-compiler-phases' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"reality_compiler_phases"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_scope_risk_classes(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-src-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-src',
                '--scope-risk-classes' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"scope_risk_classes"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_organ_mesh_phases(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-omp-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-omp',
                '--organ-mesh-phases' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"organ_mesh_phases"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_telemetry_surfaces(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ts-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ts',
                '--telemetry-surfaces' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"telemetry_surfaces"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_phase_signature_l4(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-psl4-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-psl4',
                '--phase-signature-l4' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"phase_signature_l4"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_blocker_severity_levels(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-bsl-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-bsl',
                '--blocker-severity-levels' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"blocker_severity_levels"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_scope_high_risks(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-shr-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-shr',
                '--scope-high-risks' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"scope_high_risks"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_architect_spec_catalogue(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-asc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-asc',
                '--architect-spec-catalogue' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"architect_spec_catalogue"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_surprise_gate_bands(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-sgb-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-sgb',
                '--surprise-gate-bands' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"surprise_gate_bands"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_immune_calibration_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-icc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-icc',
                '--immune-calibration-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"immune_calibration_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_cognitive_immune_check_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-cicc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-cicc',
                '--cognitive-immune-check-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"cognitive_immune_check_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_cognition_evidence_statuses(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ces-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ces',
                '--cognition-evidence-statuses' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"cognition_evidence_statuses"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_capture_hmac_lineage(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-chl-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-chl',
                '--capture-hmac-lineage' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"capture_hmac_lineage"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_cognitive_function_axes(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-cfa-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-cfa',
                '--cognitive-function-axes' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"cognitive_function_axes"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_gate_signal_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-gsc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-gsc',
                '--gate-signal-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"gate_signal_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_rollback_trigger_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rtc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rtc',
                '--rollback-trigger-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"rollback_trigger_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_long_horizon_gate_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-lhg-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-lhg',
                '--long-horizon-gate-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"long_horizon_gate_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_immune_signature_store_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-iss-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-iss',
                '--immune-signature-store-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"immune_signature_store_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_promotion_protocol_states(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pps-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pps',
                '--promotion-protocol-states' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"promotion_protocol_states"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_autonomous_work_cycle_stages(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-awc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-awc',
                '--autonomous-work-cycle-stages' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"autonomous_work_cycle_stages"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_immune_verdict_ledger_labels(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ivl-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ivl',
                '--immune-verdict-ledger-labels' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"immune_verdict_ledger_labels"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_flywheel_funnel_stages(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ffs-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ffs',
                '--flywheel-funnel-stages' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"flywheel_funnel_stages"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_mission_control_cockpit_schema(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-mcc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-mcc',
                '--mission-control-cockpit-schema' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"mission_control_cockpit_schema"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_evidence_vision_thesis_lifecycle(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-evtl-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-evtl',
                '--evidence-vision-thesis-lifecycle' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"evidence_vision_thesis_lifecycle"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_exploratory_bets_portfolio_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ebp-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ebp',
                '--exploratory-bets-portfolio-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"exploratory_bets_portfolio_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_composed_obra_arc_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-coa-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-coa',
                '--composed-obra-arc-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"composed_obra_arc_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_memory_feedback_decay_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-mfdc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-mfdc',
                '--memory-feedback-decay-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"memory_feedback_decay_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_spec_completeness_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-scc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-scc',
                '--spec-completeness-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"spec_completeness_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_context_retention_schemas(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-crs-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-crs',
                '--context-retention-schemas' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"context_retention_schemas"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_context_budget_schemas(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-cbs-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-cbs',
                '--context-budget-schemas' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"context_budget_schemas"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_outcome_envelope_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-oec-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-oec',
                '--outcome-envelope-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"outcome_envelope_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_pre_review_advisory_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-prac-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-prac',
                '--pre-review-advisory-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"pre_review_advisory_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_ambition_rung_policy_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-arpc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-arpc',
                '--ambition-rung-policy-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"ambition_rung_policy_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_reactive_saturation_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rsc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rsc',
                '--reactive-saturation-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"reactive_saturation_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_portfolio_budget_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pbc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pbc',
                '--portfolio-budget-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"portfolio_budget_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_predicted_impact_band_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pibc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pibc',
                '--predicted-impact-band-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"predicted_impact_band_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_gated_corpus_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-gcc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-gcc',
                '--gated-corpus-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"gated_corpus_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_claim_definition_of_done_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-cdodc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-cdodc',
                '--claim-definition-of-done-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"claim_definition_of_done_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_quality_bar_telemetry_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-qbtc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-qbtc',
                '--quality-bar-telemetry-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"quality_bar_telemetry_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_doc_maturity_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dmc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dmc',
                '--doc-maturity-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"doc_maturity_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_attempt_lifecycle_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-alc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-alc',
                '--attempt-lifecycle-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"attempt_lifecycle_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_esp09_challenger_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-e9c-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-e9c',
                '--esp09-challenger-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"esp09_challenger_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_memory_weight_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-mwfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-mwfc',
                '--memory-weight-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"memory_weight_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_delivery_pack_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dpc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dpc',
                '--delivery-pack-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"delivery_pack_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_domain_lexical_fact_schema_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dlfsc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dlfsc',
                '--domain-lexical-fact-schema-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"domain_lexical_fact_schema_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_phase_advance_blocker_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pabc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pabc',
                '--phase-advance-blocker-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"phase_advance_blocker_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_outcome_causality_comparator_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-occc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-occc',
                '--outcome-causality-comparator-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"outcome_causality_comparator_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_segment_importance_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-sic-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-sic',
                '--segment-importance-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"segment_importance_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_cognitive_immune_promotion_gate_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-cipgc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-cipgc',
                '--cognitive-immune-promotion-gate-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"cognitive_immune_promotion_gate_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_cognitive_immune_input_classifier_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ciicc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ciicc',
                '--cognitive-immune-input-classifier-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"cognitive_immune_input_classifier_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_window_evolution_hybrid_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-wehc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-wehc',
                '--window-evolution-hybrid-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"window_evolution_hybrid_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_implementation_authority_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-iac-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-iac',
                '--implementation-authority-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"implementation_authority_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_evidence_volume_deferred_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-evdc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-evdc',
                '--evidence-volume-deferred-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"evidence_volume_deferred_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_watchdog_health_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-whfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-whfc',
                '--watchdog-health-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"watchdog_health_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_evidence_vision_composer_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-evcc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-evcc',
                '--evidence-vision-composer-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"evidence_vision_composer_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_measure_series_maxa04_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-msmc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-msmc',
                '--measure-series-maxa04-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"measure_series_maxa04_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_ragx_choreography_budget_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rcbc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rcbc',
                '--ragx-choreography-budget-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"ragx_choreography_budget_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_verified_share_scorecard_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-vssc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-vssc',
                '--verified-share-scorecard-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"verified_share_scorecard_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_aaeos_evidence_maturity_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-aemc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-aemc',
                '--aaeos-evidence-maturity-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"aaeos_evidence_maturity_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_lote2_quality_bar_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-lqbc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-lqbc',
                '--lote2-quality-bar-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"lote2_quality_bar_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_embedding_coverage_truth_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ectc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ectc',
                '--embedding-coverage-truth-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"embedding_coverage_truth_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_phase_gates_flywheel_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pgfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pgfc',
                '--phase-gates-flywheel-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"phase_gates_flywheel_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_frontier_watchdog_cockpit_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-fwcc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-fwcc',
                '--frontier-watchdog-cockpit-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"frontier_watchdog_cockpit_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_runbook_department_atlas_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rdac-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rdac',
                '--runbook-department-atlas-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"runbook_department_atlas_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_outcome_causality_weights_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ocwc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ocwc',
                '--outcome-causality-weights-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"outcome_causality_weights_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_watchdog_canary_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-wcfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-wcfc',
                '--watchdog-canary-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"watchdog_canary_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_http_path_facade_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-hpfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-hpfc',
                '--http-path-facade-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"http_path_facade_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_phase_doc_promotion_ids_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pdpic-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pdpic',
                '--phase-doc-promotion-ids-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"phase_doc_promotion_ids_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_outcome_immune_scorecard_ids_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-oisic-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-oisic',
                '--outcome-immune-scorecard-ids-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"outcome_immune_scorecard_ids_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_gate_evolution_skill_freeze_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-gesfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-gesfc',
                '--gate-evolution-skill-freeze-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"gate_evolution_skill_freeze_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_residual_schema_ledger_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rslc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rslc',
                '--residual-schema-ledger-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"residual_schema_ledger_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_unwired_watchdog_checks_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-uwcc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-uwcc',
                '--unwired-watchdog-checks-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"unwired_watchdog_checks_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_watchdog_runner_autonomy_ladder_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-wralc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-wralc',
                '--watchdog-runner-autonomy-ladder-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"watchdog_runner_autonomy_ladder_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_outcome_envelope_adapters_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-oeac-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-oeac',
                '--outcome-envelope-adapters-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"outcome_envelope_adapters_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_implementation_truth_rank_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-itrc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-itrc',
                '--implementation-truth-rank-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"implementation_truth_rank_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_secondary_report_schemas_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-srsc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-srsc',
                '--secondary-report-schemas-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"secondary_report_schemas_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_department_io_schemas_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-diosc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-diosc',
                '--department-io-schemas-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"department_io_schemas_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_http_path_watchdog_observe_schemas_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-hpwosc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-hpwosc',
                '--http-path-watchdog-observe-schemas-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"http_path_watchdog_observe_schemas_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_evaluator_observe_helpers_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-eohc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-eohc',
                '--evaluator-observe-helpers-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"evaluator_observe_helpers_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_gate_report_schema_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-grsc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-grsc',
                '--gate-report-schema-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"gate_report_schema_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_docs_authority_confidence_keys_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dackc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dackc',
                '--docs-authority-confidence-keys-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"docs_authority_confidence_keys_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_maxa04_promotion_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-m04pfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-m04pfc',
                '--maxa04-promotion-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"maxa04_promotion_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_composed_obra_lifecycle_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-colfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-colfc',
                '--composed-obra-lifecycle-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"composed_obra_lifecycle_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_resource_budget_host_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rbhfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rbhfc',
                '--resource-budget-host-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"resource_budget_host_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_verified_share_procedural_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-vspfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-vspfc',
                '--verified-share-procedural-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"verified_share_procedural_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_long_horizon_gate_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-lhgfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-lhgfc',
                '--long-horizon-gate-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"long_horizon_gate_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_ledger_rotation_impact_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-lrifc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-lrifc',
                '--ledger-rotation-impact-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"ledger_rotation_impact_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_observe_helper_limit_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ohlfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ohlfc',
                '--observe-helper-limit-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"observe_helper_limit_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_outcome_envelope_bool_fields_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-oebfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-oebfc',
                '--outcome-envelope-bool-fields-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"outcome_envelope_bool_fields_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_quality_bar_cognitive_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-qbcfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-qbcfc',
                '--quality-bar-cognitive-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"quality_bar_cognitive_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_parallel_substrate_bridge_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-psbfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-psbfc',
                '--parallel-substrate-bridge-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"parallel_substrate_bridge_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }


    public function test_universal_gates_observe_ops_config_toggle_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-octfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-octfc',
                '--ops-config-toggle-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"ops_config_toggle_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }


    public function test_universal_gates_observe_ragx_immune_substrate_config_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-riscfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-riscfc',
                '--ragx-immune-substrate-config-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"ragx_immune_substrate_config_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }


    public function test_universal_gates_observe_residual_ops_config_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rocfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rocfc',
                '--residual-ops-config-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"residual_ops_config_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }


    public function test_universal_gates_observe_ragx_stage_mechanism_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rsmfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rsmfc',
                '--ragx-stage-mechanism-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"ragx_stage_mechanism_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }


    public function test_universal_gates_observe_department_extended_io_procedural_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-deipfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-deipfc',
                '--department-extended-io-procedural-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"department_extended_io_procedural_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }


    public function test_universal_gates_observe_runtime_status_mode_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rsmode-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rsmode',
                '--runtime-status-mode-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"runtime_status_mode_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }


    public function test_universal_gates_observe_outcome_maxa04_lote2_status_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-oml2sfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-oml2sfc',
                '--outcome-maxa04-lote2-status-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"outcome_maxa04_lote2_status_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }


    public function test_universal_gates_observe_lote2_reason_ambition_portfolio_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-lrapfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-lrapfc',
                '--lote2-reason-ambition-portfolio-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"lote2_reason_ambition_portfolio_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_esp09_bets_obra_status_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ebosfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ebosfc',
                '--esp09-bets-obra-status-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"esp09_bets_obra_status_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_ncapture_promotion_lifecycle_status_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-nplsfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-nplsfc',
                '--ncapture-promotion-lifecycle-status-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"ncapture_promotion_lifecycle_status_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_asef_remint_immune_ragx_status_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-arirsfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-arirsfc',
                '--asef-remint-immune-ragx-status-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"asef_remint_immune_ragx_status_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_decay_veto_numeric_choreography_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dvncfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dvncfc',
                '--decay-veto-numeric-choreography-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"decay_veto_numeric_choreography_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_evidence_temporal_hmac_calibration_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ethcfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ethcfc',
                '--evidence-temporal-hmac-calibration-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"evidence_temporal_hmac_calibration_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_verified_share_capability_truth_ambition_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-vsctafc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-vsctafc',
                '--verified-share-capability-truth-ambition-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"verified_share_capability_truth_ambition_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_canary_integrity_window_rotation_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ciwrfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ciwrfc',
                '--canary-integrity-window-rotation-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"canary_integrity_window_rotation_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_golden_pareto_scorer_maxa04_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-gpsmfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-gpsmfc',
                '--golden-pareto-scorer-maxa04-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"golden_pareto_scorer_maxa04_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_parallel_procedural_watchdog_residual_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ppwrfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ppwrfc',
                '--parallel-procedural-watchdog-residual-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"parallel_procedural_watchdog_residual_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_lote2_decomposer_redaction_unobserved_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ldrufc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ldrufc',
                '--lote2-decomposer-redaction-unobserved-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"lote2_decomposer_redaction_unobserved_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_unobserved_status_basis_handoff_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-usbhfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-usbhfc',
                '--unobserved-status-basis-handoff-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"unobserved_status_basis_handoff_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_choreography_repair_review_measure_freeze_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-crrmffc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-crrmffc',
                '--choreography-repair-review-measure-freeze-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"choreography_repair_review_measure_freeze_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_residual_error_basis_status_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rebsfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rebsfc',
                '--residual-error-basis-status-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"residual_error_basis_status_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_signature_mode_suspended_unknown_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-smsufc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-smsufc',
                '--signature-mode-suspended-unknown-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"signature_mode_suspended_unknown_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_architect_verdict_freeze_ready_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-avfrfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-avfrfc',
                '--architect-verdict-freeze-ready-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"architect_verdict_freeze_ready_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_mission_control_pending_partial_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-mcppfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-mcppfc',
                '--mission-control-pending-partial-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"mission_control_pending_partial_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_volume_autonomy_coverage_unknown_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-vacufc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-vacufc',
                '--volume-autonomy-coverage-unknown-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"volume_autonomy_coverage_unknown_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_watchdog_health_active_disabled_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-whadfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-whadfc',
                '--watchdog-health-active-disabled-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"watchdog_health_active_disabled_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_embedding_pending_mission_outcome_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-epmofc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-epmofc',
                '--embedding-pending-mission-outcome-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"embedding_pending_mission_outcome_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_local_model_embedding_immune_unavailable_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-lmeiufc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-lmeiufc',
                '--local-model-embedding-immune-unavailable-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"local_model_embedding_immune_unavailable_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_prereview_parallel_flywheel_frontier_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ppfff-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ppfff',
                '--prereview-parallel-flywheel-frontier-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"prereview_parallel_flywheel_frontier_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_obra_portfolio_pareto_blocked_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-oppb-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-oppb',
                '--obra-portfolio-pareto-blocked-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"obra_portfolio_pareto_blocked_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_corpus_parallel_truth_blocked_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-cptb-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-cptb',
                '--corpus-parallel-truth-blocked-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"corpus_parallel_truth_blocked_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_teto10_cockpit_ladder_promotion_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-tclp-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-tclp',
                '--teto10-cockpit-ladder-promotion-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"teto10_cockpit_ladder_promotion_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_dead_series_miner_signature_adapter_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dsmsa-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dsmsa',
                '--dead-series-miner-signature-adapter-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"dead_series_miner_signature_adapter_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_ragx_prereview_lote2_schema_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rpls-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rpls',
                '--ragx-prereview-lote2-schema-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"ragx_prereview_lote2_schema_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_window_gates_integrity_flag_disabled_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-wgifd-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-wgifd',
                '--window-gates-integrity-flag-disabled-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"window_gates_integrity_flag_disabled_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_obra_verified_long_horizon_enabled_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ovlhe-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ovlhe',
                '--obra-verified-long-horizon-enabled-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"obra_verified_long_horizon_enabled_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_embedding_table_fixture_measured_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-etfm-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-etfm',
                '--embedding-table-fixture-measured-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"embedding_table_fixture_measured_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_conflict_frontier_fixture_pending_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-cffp-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-cffp',
                '--conflict-frontier-fixture-pending-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"conflict_frontier_fixture_pending_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_queued_passed_advisory_absent_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-qpaa-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-qpaa',
                '--queued-passed-advisory-absent-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"queued_passed_advisory_absent_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_ran_accepted_keep_fixture_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rakf-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rakf',
                '--ran-accepted-keep-fixture-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"ran_accepted_keep_fixture_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_immune_class_chunks_hmac_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-icch-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-icch',
                '--immune-class-chunks-hmac-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"immune_class_chunks_hmac_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_rotation_measure_series_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rms-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rms',
                '--rotation-measure-series-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"rotation_measure_series_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_promotion_lote2_measure_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pl2-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pl2',
                '--promotion-lote2-measure-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"promotion_lote2_measure_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_department_contract_maturity_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dcm-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dcm',
                '--department-contract-maturity-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"department_contract_maturity_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_quality_veto_evolution_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-qve-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-qve',
                '--quality-veto-evolution-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"quality_veto_evolution_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_watchdog_immune_ragx_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-wir-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-wir',
                '--watchdog-immune-ragx-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"watchdog_immune_ragx_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_obra_evidence_http_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-oeh-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-oeh',
                '--obra-evidence-http-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"obra_evidence_http_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_teto_cognitive_hmac_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-tch-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-tch',
                '--teto-cognitive-hmac-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"teto_cognitive_hmac_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_promotion_asef_autonomy_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-paa-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-paa',
                '--promotion-asef-autonomy-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"promotion_asef_autonomy_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_longhorizon_window_aemor_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-lwa-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-lwa',
                '--longhorizon-window-aemor-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"longhorizon_window_aemor_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_test_immune_truth_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-tit-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-tit',
                '--test-immune-truth-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"test_immune_truth_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_model_causality_skill_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-mcs-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-mcs',
                '--model-causality-skill-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"model_causality_skill_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_choreography_hybrid_dev_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-chd-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-chd',
                '--choreography-hybrid-dev-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"choreography_hybrid_dev_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_compounding_scorecard_canary_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-csc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-csc',
                '--compounding-scorecard-canary-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"compounding_scorecard_canary_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_obra_lote2_health_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-olh-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-olh',
                '--obra-lote2-health-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"obra_lote2_health_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_volume_cockpit_rollback_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-vcr-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-vcr',
                '--volume-cockpit-rollback-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"volume_cockpit_rollback_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_arc_segment_window_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-asw-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-asw',
                '--arc-segment-window-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"arc_segment_window_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_department_integrity_capture_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dic-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dic',
                '--department-integrity-capture-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"department_integrity_capture_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_asef_calibration_jina_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-acj-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-acj',
                '--asef-calibration-jina-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"asef_calibration_jina_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_ledger_counterfactual_advisory_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-lca-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-lca',
                '--ledger-counterfactual-advisory-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"ledger_counterfactual_advisory_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_verified_frontier_cooccurrence_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-vfc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-vfc',
                '--verified-frontier-cooccurrence-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"verified_frontier_cooccurrence_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_docs_handoff_adversarial_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dha-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dha',
                '--docs-handoff-adversarial-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"docs_handoff_adversarial_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_immune_ragx_scorecard_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-irs-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-irs',
                '--immune-ragx-scorecard-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"immune_ragx_scorecard_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_http_thesis_lote2_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-htl-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-htl',
                '--http-thesis-lote2-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"http_thesis_lote2_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_longhorizon_watchdog_promotion_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-lwp-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-lwp',
                '--longhorizon-watchdog-promotion-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"longhorizon_watchdog_promotion_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_esp09_lote2_hmac_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-elh-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-elh',
                '--esp09-lote2-hmac-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"esp09_lote2_hmac_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_phase_obra_bets_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pob-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pob',
                '--phase-obra-bets-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"phase_obra_bets_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_parallel_truth_autonomy_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pta-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pta',
                '--parallel-truth-autonomy-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"parallel_truth_autonomy_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_obra_thesis_skill_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ots-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ots',
                '--obra-thesis-skill-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"obra_thesis_skill_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_mission_promotion_outcome_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-mpo-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-mpo',
                '--mission-promotion-outcome-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"mission_promotion_outcome_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_immune_rollback_remint_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-irr-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-irr',
                '--immune-rollback-remint-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"immune_rollback_remint_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_scorecard_gate_test_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-sgt-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-sgt',
                '--scorecard-gate-test-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"scorecard_gate_test_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_ncapture_immune_coverage_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-nic-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-nic',
                '--ncapture-immune-coverage-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"ncapture_immune_coverage_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_cockpit_canary_adversarial_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-cca-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-cca',
                '--cockpit-canary-adversarial-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"cockpit_canary_adversarial_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_maturity_envelope_lifecycle_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-mel-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-mel',
                '--maturity-envelope-lifecycle-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"maturity_envelope_lifecycle_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_embedding_coverage_thesis_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ect-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ect',
                '--embedding-coverage-thesis-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"embedding_coverage_thesis_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_dept_level_evidence_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-dle-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-dle',
                '--dept-level-evidence-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"dept_level_evidence_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_schema_decomposer_surprise_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-sds-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-sds',
                '--schema-decomposer-surprise-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"schema_decomposer_surprise_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_evidence_flywheel_budget_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-efb-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-efb',
                '--evidence-flywheel-budget-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"evidence_flywheel_budget_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_portfolio_impact_corpus_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-pic-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-pic',
                '--portfolio-impact-corpus-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"portfolio_impact_corpus_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_disk_deadseries_latency_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-ddl-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-ddl',
                '--disk-deadseries-latency-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"disk_deadseries_latency_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_memory_spec_dogfood_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-msd-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-msd',
                '--memory-spec-dogfood-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"memory_spec_dogfood_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_restore_redaction_recall_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rrr-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rrr',
                '--restore-redaction-recall-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"restore_redaction_recall_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_runner_phase_saturation_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-rps-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-rps',
                '--runner-phase-saturation-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"runner_phase_saturation_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_immune_scorecard_segment_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-iss-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-iss',
                '--immune-scorecard-segment-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"immune_scorecard_segment_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_advisory_teto_jina_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-atj-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-atj',
                '--advisory-teto-jina-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"advisory_teto_jina_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_window_canary_flywheel_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-wcf-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-wcf',
                '--window-canary-flywheel-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"window_canary_flywheel_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_verified_coverage_choreography_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-vcc-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-vcc',
                '--verified-coverage-choreography-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"verified_coverage_choreography_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_knowledge_decomposer_promoter_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-kdp-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-kdp',
                '--knowledge-decomposer-promoter-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"knowledge_decomposer_promoter_floors_contract"')
                ->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_universal_gates_observe_ncapture_obra_truth_floors_contract(): void
    {
        $path = sys_get_temp_dir().'/atlas-aaeos-not-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(new \stdClass));

        try {
            $this->artisan('atlas:aaeos', [
                'action' => 'universal-gates',
                '--intent' => 'i-not',
                '--ncapture-obra-truth-floors-contract' => $path,
                '--json' => true,
            ])
                ->expectsOutputToContain('"ncapture_obra_truth_floors_contract"')
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
