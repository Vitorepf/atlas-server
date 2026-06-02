<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Intelligence\ReviewFindingSeverityComposer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReviewFindingSeverityComposerTest extends TestCase
{
    private ReviewFindingSeverityComposer $composer;

    protected function setUp(): void
    {
        $this->composer = new ReviewFindingSeverityComposer;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function signals(array $overrides = []): array
    {
        return array_merge([
            'risk_type' => 'bug',
            'exploitability' => 0.5,
            'blast_radius_files' => 0,
            'reversibility' => 0.5,
            'in_security_sensitive_path' => false,
            'confidence' => 0.8,
            'has_remediation' => true,
        ], $overrides);
    }

    public function test_schema_version_is_pinned(): void
    {
        $result = $this->composer->compose($this->signals());

        $this->assertSame('atlas.dev.intelligence.review_finding_severity.v1', $result['schema_version']);
    }

    public function test_identical_signals_produce_byte_identical_result(): void
    {
        $signals = $this->signals([
            'risk_type' => 'security',
            'exploitability' => 0.7,
            'blast_radius_files' => 4,
            'reversibility' => 0.3,
            'in_security_sensitive_path' => true,
            'confidence' => 0.6,
            'has_remediation' => true,
        ]);

        $first = $this->composer->compose($signals);
        $second = $this->composer->compose($signals);

        $this->assertSame($first, $second);
    }

    public function test_high_evidence_yields_strictly_worse_rank_than_low_evidence(): void
    {
        $worse = $this->composer->compose($this->signals([
            'risk_type' => 'bug',
            'exploitability' => 0.95,
            'blast_radius_files' => 12,
            'reversibility' => 0.1,
            'in_security_sensitive_path' => false,
            'confidence' => 0.9,
            'has_remediation' => true,
        ]));

        $better = $this->composer->compose($this->signals([
            'risk_type' => 'bug',
            'exploitability' => 0.3,
            'blast_radius_files' => 1,
            'reversibility' => 0.9,
            'in_security_sensitive_path' => false,
            'confidence' => 0.4,
            'has_remediation' => true,
        ]));

        $this->assertEqualsWithDelta(0.94, $worse['score'], 0.0001);
        $this->assertEqualsWithDelta(0.24, $better['score'], 0.0001);
        $this->assertSame(0, $worse['severity_rank']);
        $this->assertSame('blocker', $worse['severity']);
        $this->assertSame(4, $better['severity_rank']);
        $this->assertSame('low', $better['severity']);
        $this->assertLessThan($better['severity_rank'], $worse['severity_rank']);
    }

    public function test_security_sensitive_path_floors_verdict_to_critical_or_blocker(): void
    {
        $result = $this->composer->compose($this->signals([
            'risk_type' => 'security',
            'exploitability' => 0.9,
            'blast_radius_files' => 5,
            'reversibility' => 0.5,
            'in_security_sensitive_path' => true,
            'confidence' => 0.95,
            'has_remediation' => true,
        ]));

        $this->assertContains($result['severity'], ['blocker', 'critical']);
        $this->assertLessThanOrEqual(1, $result['severity_rank']);
        $this->assertContains('floor:sensitive_path_injection', $result['reasons']);
    }

    public function test_sensitive_path_floor_lifts_a_raw_high_score_to_critical(): void
    {
        // Engineered so the raw composite lands in the 'high' band (>= 0.60, < 0.75),
        // proving the floor genuinely lifts severity rather than the score already
        // being critical.
        $result = $this->composer->compose($this->signals([
            'risk_type' => 'security',
            'exploitability' => 0.5,
            'blast_radius_files' => 0,
            'reversibility' => 1.0,
            'in_security_sensitive_path' => true,
            'confidence' => 0.75,
            'has_remediation' => true,
        ]));

        $this->assertEqualsWithDelta(0.60, $result['score'], 0.0001);
        $this->assertSame('threshold:high', $result['reasons'][0]);
        $this->assertSame('critical', $result['severity']);
        $this->assertSame(1, $result['severity_rank']);
        $this->assertContains('floor:sensitive_path_injection', $result['reasons']);
    }

    public function test_low_confidence_downgrades_rank_relative_to_high_confidence(): void
    {
        $base = [
            'risk_type' => 'bug',
            'exploitability' => 0.5,
            'blast_radius_files' => 0,
            'reversibility' => 0.0,
            'in_security_sensitive_path' => false,
            'has_remediation' => true,
        ];

        $lowConfidence = $this->composer->compose($this->signals($base + ['confidence' => 0.2]));
        $highConfidence = $this->composer->compose($this->signals($base + ['confidence' => 0.9]));

        // Low-confidence case is still a medium-band score before the downgrade.
        $this->assertEqualsWithDelta(0.44, $lowConfidence['score'], 0.0001);
        $this->assertSame('threshold:medium', $lowConfidence['reasons'][0]);

        $this->assertTrue($lowConfidence['downgraded_due_to_low_confidence']);
        $this->assertFalse($highConfidence['downgraded_due_to_low_confidence']);
        $this->assertContains('downgrade:low_confidence', $lowConfidence['reasons']);

        $this->assertSame(3, $highConfidence['severity_rank']);
        $this->assertSame(4, $lowConfidence['severity_rank']);
        $this->assertGreaterThan($highConfidence['severity_rank'], $lowConfidence['severity_rank']);
    }

    public function test_sensitive_path_floor_overrides_low_confidence_downgrade(): void
    {
        $result = $this->composer->compose($this->signals([
            'risk_type' => 'security',
            'exploitability' => 0.3,
            'blast_radius_files' => 0,
            'reversibility' => 0.9,
            'in_security_sensitive_path' => true,
            'confidence' => 0.2,
            'has_remediation' => true,
        ]));

        // Raw score is medium-band; both downgrade and floor fire, floor wins.
        $this->assertEqualsWithDelta(0.43, $result['score'], 0.0001);
        $this->assertTrue($result['downgraded_due_to_low_confidence']);
        $this->assertContains('downgrade:low_confidence', $result['reasons']);
        $this->assertContains('floor:sensitive_path_injection', $result['reasons']);
        $this->assertSame(1, $result['severity_rank']);
        $this->assertSame('critical', $result['severity']);
    }

    public function test_blocker_without_remediation_is_capped_to_high(): void
    {
        $result = $this->composer->compose($this->signals([
            'risk_type' => 'bug',
            'exploitability' => 0.95,
            'blast_radius_files' => 12,
            'reversibility' => 0.1,
            'in_security_sensitive_path' => false,
            'confidence' => 0.9,
            'has_remediation' => false,
        ]));

        $this->assertEqualsWithDelta(0.94, $result['score'], 0.0001);
        $this->assertSame('threshold:blocker', $result['reasons'][0]);
        $this->assertSame('high', $result['severity']);
        $this->assertSame(2, $result['severity_rank']);
        $this->assertContains('invariant:remediation_required_capped', $result['reasons']);
    }

    public function test_score_is_clamped_to_one(): void
    {
        $result = $this->composer->compose($this->signals([
            'risk_type' => 'data_loss',
            'exploitability' => 1.0,
            'blast_radius_files' => 20,
            'reversibility' => 0.0,
            'in_security_sensitive_path' => true,
            'confidence' => 1.0,
            'has_remediation' => true,
        ]));

        $this->assertSame(1.0, $result['score']);
        $this->assertSame('blocker', $result['severity']);
        $this->assertSame(0, $result['severity_rank']);
    }

    public function test_secret_leak_risk_weight_pushes_severity_higher_than_plain_risk(): void
    {
        $base = [
            'exploitability' => 0.5,
            'blast_radius_files' => 2,
            'reversibility' => 0.5,
            'in_security_sensitive_path' => false,
            'confidence' => 0.5,
            'has_remediation' => true,
        ];

        $weighted = $this->composer->compose($this->signals($base + ['risk_type' => 'secret_leak']));
        $plain = $this->composer->compose($this->signals($base + ['risk_type' => 'bug']));

        $this->assertEqualsWithDelta(0.15, $weighted['score'] - $plain['score'], 0.0001);
        $this->assertLessThanOrEqual($plain['severity_rank'], $weighted['severity_rank']);
    }

    public function test_out_of_range_exploitability_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->composer->compose($this->signals(['exploitability' => 1.4]));
    }

    public function test_out_of_range_confidence_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->composer->compose($this->signals(['confidence' => 1.2]));
    }

    public function test_negative_blast_radius_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->composer->compose($this->signals(['blast_radius_files' => -1]));
    }

    public function test_empty_risk_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->composer->compose($this->signals(['risk_type' => '   ']));
    }

    public function test_severity_rank_always_matches_severity_label(): void
    {
        $result = $this->composer->compose($this->signals([
            'risk_type' => 'performance',
            'exploitability' => 0.45,
            'blast_radius_files' => 3,
            'reversibility' => 0.6,
            'in_security_sensitive_path' => false,
            'confidence' => 0.7,
            'has_remediation' => true,
        ]));

        $expectedRank = ReviewFindingSeverityComposer::SEVERITY_RANK[$result['severity']];

        $this->assertSame($expectedRank, $result['severity_rank']);
        $this->assertGreaterThanOrEqual(0, $result['severity_rank']);
        $this->assertLessThanOrEqual(5, $result['severity_rank']);
    }
}
