<?php

namespace Tests\Unit\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\Mesh\HermesDoctorPreflight;
use Tests\TestCase;

class HermesDoctorPreflightTest extends TestCase
{
    private function preflight(): HermesDoctorPreflight
    {
        return new HermesDoctorPreflight();
    }

    public function test_healthy_approved_path_advises_critical_dispatch(): void
    {
        $receipt = $this->preflight()->assess(
            'hermes v1.4.2',
            "doctor: all checks passed\nnetwork: ok\nconfig: ok",
            'status: idle, queue empty',
        );

        $this->assertSame('atlas.hermes.preflight_evidence.v1', $receipt['schema_version']);
        $this->assertSame('atlas', $receipt['authority']);
        $this->assertFalse($receipt['hermes_preflight_can_decide']);
        $this->assertSame('v1.4.2', $receipt['version']);
        $this->assertTrue($receipt['version_detected']);
        $this->assertTrue($receipt['doctor_clean']);
        $this->assertTrue($receipt['status_present']);
        $this->assertTrue($receipt['healthy']);
        $this->assertTrue($receipt['critical_dispatch_advised']);
        $this->assertSame(['version_detected', 'doctor_clean', 'status_present'], $receipt['signals']);
        $this->assertSame('preflight_healthy', $receipt['status']);
    }

    public function test_schema_version_is_first_key_and_receipt_hash_is_last(): void
    {
        $receipt = $this->preflight()->assess('v2.0.0', 'all ok', 'running');

        $keys = array_keys($receipt);
        $this->assertSame('schema_version', $keys[0]);
        $this->assertSame('receipt_hash', $keys[array_key_last($keys)]);
    }

    public function test_empty_input_is_fail_closed(): void
    {
        $receipt = $this->preflight()->assess('', '', '');

        $this->assertNull($receipt['version']);
        $this->assertFalse($receipt['version_detected']);
        $this->assertFalse($receipt['doctor_clean']);
        $this->assertFalse($receipt['status_present']);
        $this->assertFalse($receipt['healthy']);
        $this->assertFalse($receipt['critical_dispatch_advised']);
        $this->assertSame([], $receipt['signals']);
        $this->assertSame('preflight_unhealthy', $receipt['status']);
    }

    public function test_garbage_input_is_fail_closed(): void
    {
        $receipt = $this->preflight()->assess('not a version', '???', '   ');

        $this->assertNull($receipt['version']);
        $this->assertFalse($receipt['healthy']);
        $this->assertFalse($receipt['critical_dispatch_advised']);
        $this->assertFalse($receipt['status_present']);
    }

    public function test_doctor_error_marker_blocks_health_even_with_version(): void
    {
        $receipt = $this->preflight()->assess(
            'hermes v1.4.2',
            'doctor: ERROR connecting to provider socket',
            'status: ok',
        );

        $this->assertSame('v1.4.2', $receipt['version']);
        $this->assertTrue($receipt['version_detected']);
        $this->assertFalse($receipt['doctor_clean']);
        $this->assertFalse($receipt['healthy']);
        $this->assertFalse($receipt['critical_dispatch_advised']);
        $this->assertNotContains('doctor_clean', $receipt['signals']);
        $this->assertContains('version_detected', $receipt['signals']);
    }

    public function test_missing_and_fail_markers_block_health_case_insensitively(): void
    {
        foreach (['something is Missing', 'check FAILed', 'fatal Error here'] as $doctor) {
            $receipt = $this->preflight()->assess('v1.0.0', $doctor, 'ok');
            $this->assertFalse($receipt['doctor_clean'], $doctor);
            $this->assertFalse($receipt['healthy'], $doctor);
            $this->assertFalse($receipt['critical_dispatch_advised'], $doctor);
        }
    }

    public function test_version_without_clean_doctor_is_not_healthy(): void
    {
        // Version present but doctor output empty => fail-closed (no evidence).
        $receipt = $this->preflight()->assess('v3.1.0', '', 'status here');

        $this->assertSame('v3.1.0', $receipt['version']);
        $this->assertFalse($receipt['doctor_clean']);
        $this->assertFalse($receipt['healthy']);
        $this->assertFalse($receipt['critical_dispatch_advised']);
    }

    public function test_clean_doctor_without_version_is_not_healthy(): void
    {
        $receipt = $this->preflight()->assess('hermes (build unknown)', 'all checks passed', 'running');

        $this->assertNull($receipt['version']);
        $this->assertTrue($receipt['doctor_clean']);
        $this->assertFalse($receipt['healthy']);
        $this->assertFalse($receipt['critical_dispatch_advised']);
    }

    public function test_version_token_is_normalised_and_raw_output_not_echoed(): void
    {
        $receipt = $this->preflight()->assess(
            "Hermes CLI 0.9.13 (commit abc123, /Users/secret/path/.hermes/token=deadbeef)",
            'all ok',
            'ok',
        );

        $this->assertSame('v0.9.13', $receipt['version']);
        // No raw path/secret content leaks into the receipt.
        $encoded = json_encode($receipt);
        $this->assertStringNotContainsString('/Users/secret', $encoded);
        $this->assertStringNotContainsString('deadbeef', $encoded);
        $this->assertStringNotContainsString('commit', $encoded);
    }

    public function test_receipt_hash_is_present_and_deterministic(): void
    {
        $a = $this->preflight()->assess('v1.4.2', 'all ok', 'idle');
        $b = $this->preflight()->assess('v1.4.2', 'all ok', 'idle');

        $this->assertArrayHasKey('receipt_hash', $a);
        $this->assertSame(64, strlen($a['receipt_hash']));
        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);
    }

    public function test_disabled_path_receipt_hash_present_and_action_flag_false(): void
    {
        $receipt = $this->preflight()->assess('', '', '');

        $this->assertArrayHasKey('receipt_hash', $receipt);
        $this->assertSame(64, strlen($receipt['receipt_hash']));
        $this->assertFalse($receipt['critical_dispatch_advised']);
        $this->assertFalse($receipt['hermes_preflight_can_decide']);
    }
}
