<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Mobile\Governance;

use App\Services\Ai\Mobile\Governance\MobileFeatureContractGate;
use Tests\TestCase;

final class MobileFeatureContractGateTest extends TestCase
{
    private MobileFeatureContractGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new MobileFeatureContractGate;
    }

    private function compliant(): array
    {
        return [
            'feature_id' => 'inbox_proactive',
            'schema_version' => 'atlas.mobile.feature.v1',
            'privacy_class' => 'personal',
            'eclipse_behaviour' => 'pause_during_eclipse',
            'offline_contract' => ['degradation_mode' => 'queue'],
            'push_policy' => 'notification_only',
            'safety_boundary' => ['operator_confirmed_actions' => ['delete', 'archive_all']],
        ];
    }

    public function test_approves_compliant_feature(): void
    {
        $r = $this->gate->evaluate($this->compliant());
        $this->assertSame('mobile_deployment_approved', $r['gate_decision']);
        $this->assertSame([], $r['failed_checks']);
    }

    public function test_blocks_missing_required_field(): void
    {
        $f = $this->compliant();
        unset($f['offline_contract']);
        $r = $this->gate->evaluate($f);
        $this->assertArrayHasKey('offline_contract', $r['failed_checks']);
    }

    public function test_blocks_invalid_privacy_class(): void
    {
        $f = $this->compliant();
        $f['privacy_class'] = 'wibble';
        $r = $this->gate->evaluate($f);
        $this->assertArrayHasKey('privacy_class', $r['failed_checks']);
    }

    public function test_blocks_invalid_push_policy(): void
    {
        $f = $this->compliant();
        $f['push_policy'] = 'spam_user';
        $r = $this->gate->evaluate($f);
        $this->assertArrayHasKey('push_policy', $r['failed_checks']);
    }

    public function test_blocks_personal_privacy_without_eclipse_aware(): void
    {
        $f = $this->compliant();
        $f['eclipse_behaviour'] = 'ignore';
        $r = $this->gate->evaluate($f);
        $this->assertArrayHasKey('eclipse_behaviour', $r['failed_checks']);
    }

    public function test_blocks_proactive_push_without_user_consent(): void
    {
        $f = $this->compliant();
        $f['push_policy'] = 'proactive';
        $r = $this->gate->evaluate($f);
        $this->assertArrayHasKey('user_consent_required', $r['failed_checks']);
    }

    public function test_proactive_push_with_consent_passes(): void
    {
        $f = $this->compliant();
        $f['push_policy'] = 'proactive';
        $f['user_consent_required'] = true;
        $r = $this->gate->evaluate($f);
        $this->assertSame('mobile_deployment_approved', $r['gate_decision']);
    }

    public function test_blocks_offline_contract_without_degradation_mode(): void
    {
        $f = $this->compliant();
        $f['offline_contract'] = ['some_other_field' => 'x'];
        $r = $this->gate->evaluate($f);
        $this->assertArrayHasKey('offline_contract.degradation_mode', $r['failed_checks']);
    }

    public function test_envelope_shape_is_stable(): void
    {
        $r = $this->gate->evaluate($this->compliant());
        $this->assertSame([
            'schema_version', 'feature_id', 'gate_decision', 'passed_checks',
            'failed_checks', 'detail', 'evaluated_at',
        ], array_keys($r));
    }
}
