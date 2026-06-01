<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiArchitectureAuditService;
use Tests\TestCase;

/**
 * Pins the documented rules of the Atlas AI Architecture Audit index doc.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
 */
class AtlasAiArchitectureAuditTest extends TestCase
{
    private function service(): AtlasAiArchitectureAuditService
    {
        return new AtlasAiArchitectureAuditService();
    }

    /** Core Diagnosis: the weakness is unified orchestration, NOT missing capability. */
    public function test_core_diagnosis_is_orchestration_gap_not_missing_capability(): void
    {
        $d = $this->service()->coreDiagnosis();

        $this->assertFalse($d['is_missing_capability']);
        $this->assertTrue($d['is_unified_orchestration_gap']);
        $this->assertSame('insufficient_unified_orchestration', $d['problem']);
    }

    /** Read Order table: each need resolves to exactly one canonical doc path. */
    public function test_read_order_routes_need_to_canonical_doc(): void
    {
        $svc = $this->service();

        $owner = $svc->routeReadOrder('Capability owner map');
        $this->assertTrue($owner['found']);
        $this->assertSame(
            'docs/engineering-knowledge-base/architecture-audit/capability-ownership-map.md',
            $owner['read'],
        );

        $findings = $svc->routeReadOrder('Canonical truths and observed disorder');
        $this->assertSame(
            'docs/engineering-knowledge-base/architecture-audit/canonical-findings.md',
            $findings['read'],
        );

        // Unknown need is a gap, never a guessed path.
        $unknown = $svc->routeReadOrder('please invent a doc for me');
        $this->assertFalse($unknown['found']);
        $this->assertNull($unknown['read']);
    }

    /** Architecture Direction: exactly the six documented layers, drift detected. */
    public function test_layer_audit_requires_all_six_layers_and_flags_drift(): void
    {
        $svc = $this->service();

        $ok = $svc->auditLayers(['core', 'domains', 'surfaces', 'runtime', 'evidence', 'learning']);
        $this->assertSame('ok', $ok['status']);
        $this->assertSame(6, $ok['expected_count']);
        $this->assertSame([], $ok['missing']);

        // Missing a layer + an undocumented extra layer => drift.
        $drift = $svc->auditLayers(['core', 'domains', 'surfaces', 'runtime', 'evidence', 'rogue_layer']);
        $this->assertSame('drift', $drift['status']);
        $this->assertSame(['learning'], $drift['missing']);
        $this->assertSame(['rogue_layer'], $drift['unexpected']);
    }

    /** Non-Negotiable Conclusion: a surface owning business flow is a violation. */
    public function test_surface_owning_business_flow_is_a_violation(): void
    {
        $svc = $this->service();

        $bad = $svc->auditSurfaceOwnership([
            'owns_business_flow' => true,
            'collects_input' => true,
            'calls_pipeline' => true,
        ]);
        $this->assertSame(AtlasAiArchitectureAuditService::SURFACE_VIOLATION, $bad['verdict']);
        $this->assertContains('surface_owns_business_flow', $bad['violations']);

        // A thin adapter that only collects input and calls the pipeline passes.
        $good = $svc->auditSurfaceOwnership([
            'owns_business_flow' => false,
            'collects_input' => true,
            'calls_pipeline' => true,
        ]);
        $this->assertSame(AtlasAiArchitectureAuditService::SURFACE_PASS, $good['verdict']);
        $this->assertTrue($good['delegates_to_pipeline']);
    }

    /** Implementation Rule: cross-surface gap => promote (never copy) + enforcement. */
    public function test_feature_gap_promotes_and_never_copies(): void
    {
        $svc = $this->service();

        $gap = $svc->decideFeatureGap([
            'present_surfaces' => ['cli'],
            'missing_surfaces' => ['api', 'mcp'],
            'owning_layer' => 'surface',
            'target_layer' => 'core',
        ]);

        $this->assertSame(AtlasAiArchitectureAuditService::VERDICT_PROMOTE, $gap['verdict']);
        $this->assertTrue($gap['copy_forbidden']);
        $this->assertSame('core', $gap['promotion_target']);
        $this->assertTrue($gap['requires_enforcement_check']);
        $this->assertContains('architecture_validation_check', $gap['enforcement_options']);

        // A promote verdict whose proposed target is a surface is rejected as
        // invalid — the rule forbids "owning" a copy in a surface.
        $badTarget = $svc->decideFeatureGap([
            'present_surfaces' => ['cli'],
            'missing_surfaces' => ['api'],
            'owning_layer' => 'surface',
            'target_layer' => 'surfaces',
        ]);
        $this->assertSame(AtlasAiArchitectureAuditService::VERDICT_PROMOTE, $badTarget['verdict']);
        $this->assertFalse($badTarget['promotion_target_valid']);
        $this->assertNull($badTarget['promotion_target']);
    }

    /** Implementation Rule: a feature already on a shared layer is already governed. */
    public function test_feature_already_on_shared_layer_is_already_governed(): void
    {
        $gap = $this->service()->decideFeatureGap([
            'present_surfaces' => ['cli'],
            'missing_surfaces' => ['api'],
            'owning_layer' => 'runtime',
        ]);

        $this->assertSame(AtlasAiArchitectureAuditService::VERDICT_ALREADY_GOVERNED, $gap['verdict']);
        $this->assertFalse($gap['copy_forbidden']);
        $this->assertFalse($gap['requires_enforcement_check']);
    }

    /** Composite audit with default sample flags the canonical violation. */
    public function test_default_audit_reports_action_required(): void
    {
        $audit = $this->service()->audit();

        $this->assertSame('action_required', $audit['status']);
        $this->assertSame(
            AtlasAiArchitectureAuditService::SURFACE_VIOLATION,
            $audit['surface_ownership']['verdict'],
        );
        $this->assertSame(
            AtlasAiArchitectureAuditService::VERDICT_PROMOTE,
            $audit['feature_gap']['verdict'],
        );
    }
}
