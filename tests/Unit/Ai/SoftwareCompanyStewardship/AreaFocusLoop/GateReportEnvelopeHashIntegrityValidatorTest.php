<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\GateReportEnvelopeHashIntegrityValidator;
use Tests\TestCase;

final class GateReportEnvelopeHashIntegrityValidatorTest extends TestCase
{
    /**
     * Build a payload P and seal it exactly as the stamp site does:
     * report_hash = 'sha256:'.MissionCanonicalHash::sha256(P) over P BEFORE the
     * stamp and the volatile generated_at exist, then generated_at AFTER.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stamp(array $payload, string $generatedAt = '2026-06-01T12:00:00+00:00'): array
    {
        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256($payload);
        $payload['generated_at'] = $generatedAt;

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function basePayload(): array
    {
        return [
            'mode' => 'max_governed',
            'gate' => 'area_focus',
            'tier' => 3,
            'claims' => [
                'read_only' => true,
                'executes_work' => false,
            ],
            'findings' => ['a', 'b', 'c'],
        ];
    }

    public function test_rule1_freshly_stamped_envelope_is_verified_and_trusted(): void
    {
        $report = $this->stamp($this->basePayload());

        $result = (new GateReportEnvelopeHashIntegrityValidator())->validate($report);

        $this->assertSame('atlas.aaeos.gate_report_hash_integrity.v1', $result['schema_version']);
        $this->assertTrue($result['trusted']);
        $this->assertSame('verified', $result['verdict']);
        $this->assertSame($result['stamped_hash'], $result['recomputed_hash']);
        $this->assertSame($report['report_hash'], $result['stamped_hash']);
        $this->assertNull($result['reason']);
    }

    public function test_rule2_missing_report_hash_is_unhashed_never_trusted(): void
    {
        $validator = new GateReportEnvelopeHashIntegrityValidator();

        foreach ([null, '', 'absent'] as $variant) {
            $report = $this->basePayload();

            if ($variant === 'absent') {
                // report_hash key never set.
            } else {
                $report['report_hash'] = $variant;
            }

            $result = $validator->validate($report);

            $this->assertFalse($result['trusted'], "variant={$variant}");
            $this->assertSame('unhashed', $result['verdict'], "variant={$variant}");
            $this->assertNull($result['stamped_hash'], "variant={$variant}");
            $this->assertNotNull($result['recomputed_hash'], "variant={$variant}");
            $this->assertStringStartsWith('sha256:', $result['recomputed_hash'], "variant={$variant}");
            $this->assertNotNull($result['reason'], "variant={$variant}");
        }
    }

    public function test_rule3_mutating_non_volatile_field_without_restamp_is_tampered(): void
    {
        $report = $this->stamp($this->basePayload());

        // Mutate a non-volatile field WITHOUT restamping.
        $report['tier'] = 99;

        $result = (new GateReportEnvelopeHashIntegrityValidator())->validate($report);

        $this->assertFalse($result['trusted']);
        $this->assertSame('tampered', $result['verdict']);
        $this->assertNotSame($result['stamped_hash'], $result['recomputed_hash']);
        $this->assertSame($report['report_hash'], $result['stamped_hash']);
        $this->assertNotNull($result['reason']);
    }

    public function test_rule4_changing_only_generated_at_stays_verified(): void
    {
        $report = $this->stamp($this->basePayload(), '2026-06-01T12:00:00+00:00');

        // generated_at is excluded from identity: a different ATOM timestamp
        // on an otherwise-verified report must remain verified.
        $report['generated_at'] = '2030-12-31T23:59:59+00:00';

        $result = (new GateReportEnvelopeHashIntegrityValidator())->validate($report);

        $this->assertTrue($result['trusted']);
        $this->assertSame('verified', $result['verdict']);
        $this->assertSame($result['stamped_hash'], $result['recomputed_hash']);
        $this->assertNull($result['reason']);
    }

    public function test_rule5_valid_shaped_but_wrong_value_hash_is_tampered(): void
    {
        $report = $this->basePayload();

        // Syntactically valid shape ('sha256:' + 64 zeros) but wrong value.
        $report['report_hash'] = 'sha256:'.str_repeat('0', 64);

        $result = (new GateReportEnvelopeHashIntegrityValidator())->validate($report);

        $this->assertFalse($result['trusted']);
        $this->assertSame('tampered', $result['verdict']);
        $this->assertSame('sha256:'.str_repeat('0', 64), $result['stamped_hash']);
        $this->assertNotSame($result['stamped_hash'], $result['recomputed_hash']);
        $this->assertNotNull($result['reason']);
    }
}
