<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasRecipesExistingToolsService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Cyber Existing Tools reuse rule: do not create a new
 * cyber recipe when a registered tool already produces the evidence; propose a new
 * one only when the tool, mode or evidence shape is missing.
 *
 * @see docs/engineering-knowledge-base/cyber-security/recipes-existing-tools.md
 */
class AtlasRecipesExistingToolsTest extends TestCase
{
    private function service(): AtlasRecipesExistingToolsService
    {
        return new AtlasRecipesExistingToolsService();
    }

    /**
     * Core Rule: a capability covered by a registered tool must REUSE it (naming the
     * tool), not spawn a new recipe. gitleaks owns secret scanning; the matcher is
     * separator/case-insensitive ("Secret Scan" === "secret_scan").
     */
    public function test_covered_capability_forces_reuse_of_named_registered_tool(): void
    {
        $result = $this->service()->evaluate(['capability' => 'Secret Scan']);

        $this->assertSame('reuse', $result['verdict']);
        $this->assertTrue($result['must_reuse']);
        $this->assertSame('gitleaks', $result['reuse_tool']);
        $this->assertSame('Secret scanning.', $result['tool_use']);
        $this->assertNull($result['proposal_reason']);
    }

    /**
     * The whole doc table is enforced, including the osv_scanner CVE mapping and the
     * remediation-flow tools. toolForCapability resolves "what gives me X" directly.
     */
    public function test_registry_table_maps_each_capability_to_its_tool(): void
    {
        $service = $this->service();

        $this->assertSame('semgrep', $service->toolForCapability('sast'));
        $this->assertSame('osv_scanner', $service->toolForCapability('dependency_cve'));
        $this->assertSame('syft', $service->toolForCapability('sbom'));
        $this->assertSame('checkov', $service->toolForCapability('iac_scan'));
        $this->assertSame('hadolint', $service->toolForCapability('dockerfile_lint'));

        // "osv-scanner" and "osv_scanner" are both recognized as the same registered tool.
        $this->assertTrue($service->isRegisteredTool('osv-scanner'));
        $this->assertTrue($service->isRegisteredTool('osv_scanner'));
        $this->assertFalse($service->isRegisteredTool('atlas-new-cyber-runner'));

        // The registry projection mirrors the doc table (11 tools).
        $this->assertCount(11, $service->registry());
    }

    /**
     * Rule escape #1 — tool missing: nothing in the registry produces this evidence,
     * so a new recipe is justified.
     */
    public function test_uncovered_capability_is_propose_new_with_tool_missing_reason(): void
    {
        $result = $this->service()->evaluate(['capability' => 'binary diffing']);

        $this->assertSame('propose_new', $result['verdict']);
        $this->assertFalse($result['must_reuse']);
        $this->assertNull($result['reuse_tool']);
        $this->assertNull($result['matched_tool']);
        $this->assertSame('tool_missing', $result['proposal_reason']);
    }

    /**
     * Rule escape #2 — mode missing: trivy is registered (image/fs/repo/k8s), but a
     * requested mode it does not support flips reuse to propose_new for that mode,
     * while still naming the matched tool.
     */
    public function test_unsupported_mode_on_multimode_tool_is_propose_new(): void
    {
        $service = $this->service();

        // A supported trivy mode reuses trivy.
        $supported = $service->evaluate(['capability' => 'image_scan', 'requested_mode' => 'kubernetes']);
        $this->assertSame('reuse', $supported['verdict']);
        $this->assertSame('trivy', $supported['reuse_tool']);

        // An unsupported mode is a justified new proposal (mode missing).
        $missing = $service->evaluate(['capability' => 'image_scan', 'requested_mode' => 'firmware']);
        $this->assertSame('propose_new', $missing['verdict']);
        $this->assertSame('mode_missing', $missing['proposal_reason']);
        $this->assertSame('trivy', $missing['matched_tool']);
        $this->assertNull($missing['reuse_tool']);
    }

    /**
     * Rule escape #3 — evidence shape missing: even when a tool family matches, an
     * explicitly-missing evidence shape justifies a new recipe.
     */
    public function test_missing_evidence_shape_forces_propose_new(): void
    {
        $result = $this->service()->evaluate([
            'capability' => 'sast',
            'evidence_shape_missing' => true,
        ]);

        $this->assertSame('propose_new', $result['verdict']);
        $this->assertSame('evidence_shape_missing', $result['proposal_reason']);
        $this->assertSame('semgrep', $result['matched_tool']);
        $this->assertNull($result['reuse_tool']);
    }

    /**
     * A request with no declared capability cannot silently claim reuse: it falls to
     * propose_new (tool missing), forcing the caller to declare a concrete capability.
     */
    public function test_empty_capability_cannot_claim_reuse(): void
    {
        $result = $this->service()->evaluate(['capability' => '   ']);

        $this->assertSame('propose_new', $result['verdict']);
        $this->assertFalse($result['must_reuse']);
        $this->assertSame('tool_missing', $result['proposal_reason']);
    }
}
