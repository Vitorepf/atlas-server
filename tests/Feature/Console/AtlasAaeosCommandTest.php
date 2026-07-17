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

    public function test_unknown_action_fails(): void
    {
        $this->artisan('atlas:aaeos', ['action' => 'wibble'])
            ->assertExitCode(1);
    }
}
