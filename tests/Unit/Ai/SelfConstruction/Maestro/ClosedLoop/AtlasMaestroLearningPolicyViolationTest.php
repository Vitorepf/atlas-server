<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ClosedLoop;

use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroLearningPolicyViolation;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroLearningPolicyViolationTest extends TestCase
{
    // ── AC: violation instances expose provider-safe structured fields ─────────

    public function test_violation_exposes_reason_severity_policy_code_remediation_and_fail_closed(): void
    {
        $v = AtlasMaestroLearningPolicyViolation::compositeScore('rank');

        $this->assertSame('composite_score', $v->reason);
        $this->assertSame(AtlasMaestroLearningPolicyViolation::SEVERITY_BLOCK, $v->severity);
        $this->assertSame('composite_score', $v->policyCode);
        $this->assertNotEmpty($v->remediationHint);
        $this->assertTrue($v->failClosed);
    }

    // ── AC: default block behavior ───────────────────────────────────────────────

    public function test_named_factories_default_to_block_severity_and_fail_closed(): void
    {
        foreach ([
            AtlasMaestroLearningPolicyViolation::compositeScore('score'),
            AtlasMaestroLearningPolicyViolation::imperativeAdvice('must'),
            AtlasMaestroLearningPolicyViolation::forbiddenScope('MarketingDomain'),
            AtlasMaestroLearningPolicyViolation::taskFieldOverride('acceptance_criteria'),
        ] as $v) {
            $this->assertSame(AtlasMaestroLearningPolicyViolation::SEVERITY_BLOCK, $v->severity);
            $this->assertTrue($v->failClosed);
        }
    }

    public function test_ad_hoc_two_argument_construction_still_defaults_to_block(): void
    {
        // Mirrors AtlasMaestroLearningPolicyGuard's inline `new self($reason, $message)` call sites —
        // must remain valid with exactly two positional arguments.
        $v = new AtlasMaestroLearningPolicyViolation('proxy_work', 'learning artifact suggests proxy/cosmetic work: template farm');

        $this->assertSame('proxy_work', $v->reason);
        $this->assertSame(AtlasMaestroLearningPolicyViolation::SEVERITY_BLOCK, $v->severity);
        $this->assertSame('proxy_work', $v->policyCode, 'policy_code defaults to reason when not supplied');
        $this->assertNull($v->remediationHint);
        $this->assertTrue($v->failClosed);
    }

    // ── AC: warn/review variants ─────────────────────────────────────────────────

    public function test_sub_support_bucket_is_a_review_severity_not_a_hard_block(): void
    {
        $v = AtlasMaestroLearningPolicyViolation::subSupportBucket(2);

        $this->assertSame(AtlasMaestroLearningPolicyViolation::SEVERITY_REVIEW, $v->severity);
        $this->assertNotEmpty($v->remediationHint);
    }

    public function test_stale_evidence_advisory_is_a_warn_severity_and_not_fail_closed(): void
    {
        $v = AtlasMaestroLearningPolicyViolation::staleEvidenceAdvisory('give_back_rate');

        $this->assertSame(AtlasMaestroLearningPolicyViolation::SEVERITY_WARN, $v->severity);
        $this->assertFalse($v->failClosed);
        $this->assertNotEmpty($v->remediationHint);
    }

    // ── AC: stable array export for receipt ledgers ─────────────────────────────

    public function test_to_array_exports_stable_provider_safe_shape(): void
    {
        $v = AtlasMaestroLearningPolicyViolation::forbiddenScope('Forge');

        $this->assertSame([
            'reason' => 'forbidden_scope',
            'message' => $v->getMessage(),
            'severity' => AtlasMaestroLearningPolicyViolation::SEVERITY_BLOCK,
            'policy_code' => 'forbidden_scope',
            'remediation_hint' => $v->remediationHint,
            'fail_closed' => true,
        ], $v->toArray());
    }

    public function test_to_array_reflects_warn_severity_variant(): void
    {
        $v = AtlasMaestroLearningPolicyViolation::staleEvidenceAdvisory('evidence_hash');
        $arr = $v->toArray();

        $this->assertSame('warn', $arr['severity']);
        $this->assertFalse($arr['fail_closed']);
    }

    public function test_is_still_a_throwable_runtime_exception(): void
    {
        $this->expectException(AtlasMaestroLearningPolicyViolation::class);

        throw AtlasMaestroLearningPolicyViolation::imperativeAdvice('must');
    }
}
