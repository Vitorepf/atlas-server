<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Governance;

use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Tests\TestCase;

class AtlasConstitutionalKernelServiceTest extends TestCase
{
    private string $tmpPath;

    private AtlasConstitutionalKernelService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpPath = sys_get_temp_dir().'/atlas_constitutional_'.uniqid('', true).'.jsonl';
        $this->svc = new AtlasConstitutionalKernelService;
        $this->svc->setViolationsLogPathForTesting($this->tmpPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpPath);
        parent::tearDown();
    }

    public function test_list_invariants_returns_petreos_by_default(): void
    {
        $all = $this->svc->listInvariants();
        $this->assertNotEmpty($all);
        foreach ($all as $i) {
            $this->assertSame(AtlasConstitutionalKernelService::INVARIANT_SCHEMA, $i['schema_version']);
            $this->assertContains($i['class'], AtlasConstitutionalKernelService::VALID_CLASSES);
        }
        $petreos = $this->svc->listInvariants(AtlasConstitutionalKernelService::CLASS_PETREO);
        $this->assertNotEmpty($petreos);
        foreach ($petreos as $i) {
            $this->assertSame('petreo', $i['class']);
        }
    }

    public function test_unknown_class_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->listInvariants('martian');
    }

    public function test_kernel_hash_is_deterministic(): void
    {
        $a = $this->svc->kernelHash();
        $b = $this->svc->kernelHash();
        $this->assertSame($a, $b);
        $this->assertStringStartsWith('sha256:', $a);
    }

    public function test_validate_blocks_malformed_input(): void
    {
        $r = $this->svc->validateChange([]);
        $this->assertSame(AtlasConstitutionalKernelService::DECISION_BLOCK, $r['decision']);
        $this->assertNotEmpty($r['violations']);
    }

    public function test_validate_allows_clean_change(): void
    {
        $r = $this->svc->validateChange([
            'change_kind' => 'subsystem_propose',
            'proposed_effect' => 'add a new memory subsystem under cognition group',
            'scope' => ['privacy_class' => 'normal'],
            'actor' => 'ASCB',
        ]);
        $this->assertSame(AtlasConstitutionalKernelService::DECISION_ALLOW, $r['decision']);
        $this->assertSame([], $r['violations']);
        $this->assertSame([], $r['required_approvals']);
    }

    public function test_validate_blocks_prohibited_claim(): void
    {
        $r = $this->svc->validateChange([
            'change_kind' => 'subsystem_propose',
            'proposed_effect' => 'innocent description',
            'claims' => ['benchmark'],
            'actor' => 'ASCB',
        ]);
        $this->assertSame(AtlasConstitutionalKernelService::DECISION_BLOCK, $r['decision']);
        $found = array_filter($r['violations'], static fn ($v) => $v['invariant_id'] === 'claim_policy_provider_safe');
        $this->assertNotEmpty($found);
    }

    public function test_validate_blocks_sensitive_outbound_data(): void
    {
        $r = $this->svc->validateChange([
            'change_kind' => 'provider_swap',
            'proposed_effect' => 'route a request to remote provider',
            'outbound_data_classes' => ['cyber'],
            'actor' => 'ADML',
        ]);
        $this->assertSame(AtlasConstitutionalKernelService::DECISION_BLOCK, $r['decision']);
    }

    public function test_validate_blocks_prohibited_vocabulary(): void
    {
        $r = $this->svc->validateChange([
            'change_kind' => 'subsystem_propose',
            'proposed_effect' => 'make atlas behave like jarvis at scale',
            'actor' => 'operator',
        ]);
        $this->assertSame(AtlasConstitutionalKernelService::DECISION_BLOCK, $r['decision']);
    }

    public function test_validate_blocks_unblock_attempt(): void
    {
        $r = $this->svc->validateChange([
            'change_kind' => 'policy_swap',
            'proposed_effect' => 'unblock external_rivals_certification gate',
            'actor' => 'operator',
        ]);
        $this->assertSame(AtlasConstitutionalKernelService::DECISION_BLOCK, $r['decision']);
    }

    public function test_validate_requires_approval_for_sensitive_scope(): void
    {
        $r = $this->svc->validateChange([
            'change_kind' => 'domain_bridge',
            'proposed_effect' => 'open a read window from cyber findings into governance',
            'scope' => ['privacy_class' => 'cyber'],
            'actor' => 'ACDM',
        ]);
        $this->assertSame(AtlasConstitutionalKernelService::DECISION_ALLOW_WITH_APPROVAL, $r['decision']);
        $this->assertContains('operator', $r['required_approvals']);
    }

    public function test_violations_persisted_append_only(): void
    {
        $this->svc->validateChange([
            'change_kind' => 'subsystem_propose',
            'proposed_effect' => 'innocent',
            'claims' => ['rivals'],
            'actor' => 'ASCB',
        ]);
        $this->svc->validateChange([
            'change_kind' => 'subsystem_propose',
            'proposed_effect' => 'innocent',
            'claims' => ['superiority'],
            'actor' => 'ASCB',
        ]);
        $vs = $this->svc->listViolations();
        $this->assertCount(2, $vs);
        foreach ($vs as $v) {
            $this->assertSame(AtlasConstitutionalKernelService::VIOLATION_SCHEMA, $v['schema_version']);
            $this->assertStringStartsWith('sha256:', $v['ticket_hash']);
        }
    }

    public function test_validation_envelope_carries_kernel_hash(): void
    {
        $r = $this->svc->validateChange([
            'change_kind' => 'noop',
            'proposed_effect' => 'no-op',
            'actor' => 'operator',
        ]);
        $this->assertSame($this->svc->kernelHash(), $r['kernel_hash']);
    }

    // ---------- Elastic invariant state ----------

    public function test_elastic_invariant_defaults_to_canonical_enabled(): void
    {
        $elasticPath = sys_get_temp_dir().'/atlas_elastic_'.uniqid('', true).'.jsonl';
        $this->svc->setElasticStateLogPathForTesting($elasticPath);
        try {
            $this->assertTrue($this->svc->isElasticEnabled('autonomous_self_construction_enabled'));
            $this->assertTrue($this->svc->isElasticEnabled('teos_meta_projection_enabled'));
        } finally {
            @unlink($elasticPath);
        }
    }

    public function test_flip_elastic_records_append_only_and_changes_effective_state(): void
    {
        $elasticPath = sys_get_temp_dir().'/atlas_elastic_'.uniqid('', true).'.jsonl';
        $this->svc->setElasticStateLogPathForTesting($elasticPath);
        try {
            $entry = $this->svc->flipElastic(
                'autonomous_self_construction_enabled',
                false,
                'operator',
                'pause autonomous propose during maintenance window'
            );
            $this->assertSame(AtlasConstitutionalKernelService::ELASTIC_FLIP_SCHEMA, $entry['schema_version']);
            $this->assertStringStartsWith('sha256:', $entry['entry_hash']);
            $this->assertFalse($this->svc->isElasticEnabled('autonomous_self_construction_enabled'));

            // Flip back ON — last flip wins.
            $this->svc->flipElastic(
                'autonomous_self_construction_enabled',
                true,
                'operator',
                'resume autonomous propose'
            );
            $this->assertTrue($this->svc->isElasticEnabled('autonomous_self_construction_enabled'));
        } finally {
            @unlink($elasticPath);
        }
    }

    public function test_flip_elastic_refuses_petreo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->flipElastic('claim_policy_provider_safe', false, 'operator', 'test');
    }

    public function test_flip_elastic_refuses_runtime_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->flipElastic('reconciliation_cadence_window', false, 'operator', 'test');
    }

    public function test_flip_elastic_refuses_unknown_invariant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->flipElastic('nonexistent_invariant_id', false, 'operator', 'test');
    }

    public function test_flip_elastic_requires_actor_and_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->flipElastic('autonomous_self_construction_enabled', false, '', '');
    }

    // ---------- Runtime tuning ----------

    public function test_runtime_value_defaults_null_until_tuned(): void
    {
        $log = sys_get_temp_dir().'/atlas_runtime_'.uniqid('', true).'.jsonl';
        $this->svc->setRuntimeStateLogPathForTesting($log);
        try {
            $this->assertNull($this->svc->currentRuntimeValue('reconciliation_cadence_window'));
            $this->assertNull($this->svc->currentRuntimeValue('tdc_ttl_window'));
            $this->assertNull($this->svc->currentRuntimeValue('admission_trust_modifier_window'));
        } finally {
            @unlink($log);
        }
    }

    public function test_tune_runtime_records_within_enum_window(): void
    {
        $log = sys_get_temp_dir().'/atlas_runtime_'.uniqid('', true).'.jsonl';
        $this->svc->setRuntimeStateLogPathForTesting($log);
        try {
            $entry = $this->svc->tuneRuntime(
                'reconciliation_cadence_window',
                'thirty',
                'autonomous_tuner',
                'demand low — slow cadence to 30 min'
            );
            $this->assertSame('thirty', $this->svc->currentRuntimeValue('reconciliation_cadence_window'));
            $this->assertStringStartsWith('sha256:', $entry['entry_hash']);
        } finally {
            @unlink($log);
        }
    }

    public function test_tune_runtime_records_within_numeric_range(): void
    {
        $log = sys_get_temp_dir().'/atlas_runtime_'.uniqid('', true).'.jsonl';
        $this->svc->setRuntimeStateLogPathForTesting($log);
        try {
            $this->svc->tuneRuntime(
                'tdc_ttl_window',
                3600,
                'operator',
                'set TDC TTL to 1h'
            );
            $this->assertSame(3600, $this->svc->currentRuntimeValue('tdc_ttl_window'));
        } finally {
            @unlink($log);
        }
    }

    public function test_tune_runtime_rejects_outside_enum_window(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->tuneRuntime(
            'reconciliation_cadence_window',
            'daily',
            'operator',
            'attempt to set daily cadence outside window'
        );
    }

    public function test_tune_runtime_rejects_outside_numeric_range(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->tuneRuntime(
            'tdc_ttl_window',
            10,
            'operator',
            'attempt 10s TTL below floor'
        );
    }

    public function test_tune_runtime_rejects_petreo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->tuneRuntime('claim_policy_provider_safe', 1, 'operator', 'test');
    }

    public function test_tune_runtime_rejects_elastic_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->tuneRuntime('autonomous_self_construction_enabled', 1, 'operator', 'test');
    }
}
