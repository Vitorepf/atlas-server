<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Governance;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Policy\PolicyCanon;
use Tests\TestCase;

class AtlasAutonomyAdmissionServiceTest extends TestCase
{
    private string $kernelLog;

    private string $admissionLog;

    private AtlasConstitutionalKernelService $kernel;

    private AtlasAutonomyAdmissionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kernelLog = sys_get_temp_dir().'/atlas_admission_kernel_'.uniqid('', true).'.jsonl';
        $this->admissionLog = sys_get_temp_dir().'/atlas_admission_'.uniqid('', true).'.jsonl';
        $this->kernel = new AtlasConstitutionalKernelService;
        $this->kernel->setViolationsLogPathForTesting($this->kernelLog);
        $this->svc = new AtlasAutonomyAdmissionService($this->kernel);
        $this->svc->setTicketsLogPathForTesting($this->admissionLog);
    }

    protected function tearDown(): void
    {
        @unlink($this->kernelLog);
        @unlink($this->admissionLog);
        parent::tearDown();
    }

    public function test_kernel_block_results_in_deny_with_petreo_gap(): void
    {
        $r = $this->svc->admit([
            'change_kind' => 'subsystem_propose',
            'proposed_effect' => 'innocent',
            'claims' => ['benchmark'],
            'requested_autonomy' => PolicyCanon::AUTONOMY_AUTONOMOUS,
            'actor' => 'ASCB',
        ]);
        $this->assertSame(AtlasAutonomyAdmissionService::DECISION_DENY, $r['decision']);
        $this->assertContains('petreo', $r['gaps']);
        $this->assertSame(PolicyCanon::AUTONOMY_SUGGEST, $r['effective_autonomy']);
    }

    public function test_clean_low_risk_autonomous_passes_full(): void
    {
        $r = $this->svc->admit([
            'change_kind' => 'noop',
            'proposed_effect' => 'add an internal memory subsystem',
            'scope' => ['privacy_class' => 'public'],
            'requested_autonomy' => PolicyCanon::AUTONOMY_AUTONOMOUS,
            'actor' => 'ASCB',
        ]);
        $this->assertSame(AtlasAutonomyAdmissionService::DECISION_ALLOW_AUTONOMOUS, $r['decision']);
        $this->assertSame(PolicyCanon::RISK_LOW, $r['risk_level']);
        $this->assertSame(PolicyCanon::AUTONOMY_AUTONOMOUS, $r['effective_autonomy']);
        $this->assertFalse($r['requires_human_approval']);
        $this->assertSame([], $r['gaps']);
    }

    public function test_kernel_approval_requirement_caps_autonomy(): void
    {
        $r = $this->svc->admit([
            'change_kind' => 'domain_bridge',
            'proposed_effect' => 'bridge engineering to legal for read access',
            'scope' => ['privacy_class' => 'cyber'],
            'requested_autonomy' => PolicyCanon::AUTONOMY_AUTONOMOUS,
            'actor' => 'ACDM',
        ]);
        // Kernel marks allow_with_approval; risk_level=critical caps further.
        $this->assertSame(AtlasAutonomyAdmissionService::DECISION_ALLOW_WITH_APPROVAL, $r['decision']);
        $this->assertTrue($r['requires_human_approval']);
        $this->assertContains('kernel_requires_approval', $r['gaps']);
        $this->assertSame(PolicyCanon::RISK_CRITICAL, $r['risk_level']);
    }

    public function test_medium_risk_caps_to_execute_with_approval(): void
    {
        $r = $this->svc->admit([
            'change_kind' => 'subsystem_propose',
            'proposed_effect' => 'add cross-domain subsystem',
            'scope' => ['privacy_class' => 'normal'],
            'requested_autonomy' => PolicyCanon::AUTONOMY_AUTONOMOUS,
            'actor' => 'ASCB',
        ]);
        $this->assertSame(AtlasAutonomyAdmissionService::DECISION_ALLOW_WITH_APPROVAL, $r['decision']);
        $this->assertContains('risk_exceeds_requested_autonomy', $r['gaps']);
        $this->assertSame(PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL, $r['effective_autonomy']);
    }

    public function test_high_risk_caps_to_draft(): void
    {
        $r = $this->svc->admit([
            'change_kind' => 'provider_swap',
            'proposed_effect' => 'switch provider for sensitive data domain',
            'scope' => ['privacy_class' => 'sensitive'],
            'requested_autonomy' => PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL,
            'actor' => 'ADML',
        ]);
        $this->assertSame(PolicyCanon::RISK_HIGH, $r['risk_level']);
        $this->assertSame(PolicyCanon::AUTONOMY_DRAFT, $r['max_autonomy_for_risk']);
        $this->assertSame(AtlasAutonomyAdmissionService::DECISION_ALLOW_WITH_APPROVAL, $r['decision']);
    }

    public function test_explicit_risk_level_overrides_derivation(): void
    {
        $r = $this->svc->admit([
            'change_kind' => 'noop',
            'proposed_effect' => 'innocent',
            'scope' => ['privacy_class' => 'public'],
            'risk_level' => PolicyCanon::RISK_CRITICAL,
            'requested_autonomy' => PolicyCanon::AUTONOMY_AUTONOMOUS,
            'actor' => 'ASCB',
        ]);
        $this->assertSame(PolicyCanon::RISK_CRITICAL, $r['risk_level']);
        $this->assertSame(PolicyCanon::AUTONOMY_SUGGEST, $r['max_autonomy_for_risk']);
    }

    public function test_requested_within_max_autonomous_is_allowed(): void
    {
        $r = $this->svc->admit([
            'change_kind' => 'noop',
            'proposed_effect' => 'innocent',
            'scope' => ['privacy_class' => 'public'],
            'requested_autonomy' => PolicyCanon::AUTONOMY_DRAFT,
            'actor' => 'ASCB',
        ]);
        $this->assertSame(AtlasAutonomyAdmissionService::DECISION_ALLOW_WITH_APPROVAL, $r['decision']);
        // requested=draft, not autonomous, so allow_with_approval not allow_autonomous
        $this->assertSame(PolicyCanon::AUTONOMY_DRAFT, $r['effective_autonomy']);
    }

    public function test_unknown_requested_autonomy_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->admit([
            'change_kind' => 'noop',
            'proposed_effect' => 'noop',
            'requested_autonomy' => 'galactic',
        ]);
    }

    public function test_envelope_carries_kernel_hash_and_envelope_hash(): void
    {
        $r = $this->svc->admit([
            'change_kind' => 'noop',
            'proposed_effect' => 'noop',
            'scope' => ['privacy_class' => 'public'],
            'requested_autonomy' => PolicyCanon::AUTONOMY_AUTONOMOUS,
        ]);
        $this->assertSame($this->kernel->kernelHash(), $r['kernel_hash']);
        $this->assertStringStartsWith('sha256:', $r['envelope_hash']);
    }

    public function test_determinism_same_input_same_hash(): void
    {
        $input = [
            'change_kind' => 'subsystem_propose',
            'proposed_effect' => 'add a memory_v2 subsystem',
            'scope' => ['privacy_class' => 'normal'],
            'requested_autonomy' => PolicyCanon::AUTONOMY_DRAFT,
            'actor' => 'ASCB',
        ];
        $a = $this->svc->admit($input);
        $b = $this->svc->admit($input);
        $this->assertSame($a['envelope_hash'], $b['envelope_hash']);
    }

    public function test_tickets_persisted_append_only(): void
    {
        $this->svc->admit([
            'change_kind' => 'noop', 'proposed_effect' => 'a', 'scope' => ['privacy_class' => 'public'], 'requested_autonomy' => PolicyCanon::AUTONOMY_SUGGEST,
        ]);
        $this->svc->admit([
            'change_kind' => 'noop', 'proposed_effect' => 'b', 'scope' => ['privacy_class' => 'public'], 'requested_autonomy' => PolicyCanon::AUTONOMY_SUGGEST,
        ]);
        $tickets = $this->svc->listTickets();
        $this->assertCount(2, $tickets);
        foreach ($tickets as $t) {
            $this->assertSame(AtlasAutonomyAdmissionService::TICKET_SCHEMA, $t['schema_version']);
            $this->assertArrayHasKey('envelope', $t);
        }
    }

    public function test_kernel_block_persists_ticket_with_deny(): void
    {
        $this->svc->admit([
            'change_kind' => 'subsystem_propose',
            'proposed_effect' => 'innocent',
            'claims' => ['rivals'],
            'requested_autonomy' => PolicyCanon::AUTONOMY_AUTONOMOUS,
        ]);
        $tickets = $this->svc->listTickets();
        $this->assertCount(1, $tickets);
        $this->assertSame(AtlasAutonomyAdmissionService::DECISION_DENY, $tickets[0]['envelope']['decision']);
    }
}
