<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasPolicyProfileService;
use App\Services\Ai\Policy\PolicyCanon;
use Tests\TestCase;

final class AtlasPolicyProfileTest extends TestCase
{
    private AtlasPolicyProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasPolicyProfileService();
    }

    public function testCodeChangeGetsWorkspaceWriteAndProceeds(): void
    {
        // Doc Example: "Alteracao de codigo pode exigir workspace-write".
        $profile = $this->service->resolveProfile([
            'intent' => 'programming.edit',
            'risk' => 'low',
            'environment' => 'workspace',
        ]);

        $this->assertSame(AtlasPolicyProfileService::SANDBOX_WORKSPACE_WRITE, $profile['sandbox']);
        $this->assertSame(PolicyCanon::DECISION_ALLOW, $profile['decision']);
        $this->assertSame(AtlasPolicyProfileService::NEXT_PROCEED, $profile['next_step']);
        $this->assertFalse($profile['dangerous']);
        // workspace-write must never expose destructive tooling.
        $this->assertContains('destructive', $profile['tools']['deny']);
    }

    public function testDeletingFilesWithoutSignatureIsBlockedAndDemandsSignature(): void
    {
        // Doc Invariant: "ferramenta perigosa exige policy explicita"; Example:
        // "apagar arquivos exige autorizacao maior". Permission is never implicit.
        $profile = $this->service->resolveProfile([
            'intent' => 'programming.delete_files',
            'risk' => 'high',
            'environment' => 'workspace',
            'signature_present' => false,
        ]);

        $this->assertTrue($profile['dangerous']);
        $this->assertSame(PolicyCanon::DECISION_BLOCKED, $profile['decision']);
        $this->assertSame(AtlasPolicyProfileService::NEXT_REQUEST_SIGNATURE, $profile['next_step']);
        // Blocked dangerous path collapses to least-privilege read-only and a
        // tightly capped cost ceiling (dangerous actions never get a wide budget).
        $this->assertSame(AtlasPolicyProfileService::SANDBOX_READ_ONLY, $profile['sandbox']);
        $this->assertLessThanOrEqual(1.0, $profile['cost_ceiling']);
        $this->assertContains('destructive_action', $profile['required_approvals']);
        $this->assertSame(PolicyCanon::AUTONOMY_SUGGEST, $profile['autonomy']);
    }

    public function testDeletingFilesWithExplicitSignatureUnlocksFullAccess(): void
    {
        // With the explicit signature the same dangerous action is authorized.
        $profile = $this->service->resolveProfile([
            'intent' => 'programming.delete_files',
            'risk' => 'high',
            'environment' => 'workspace',
            'signature_present' => true,
        ]);

        $this->assertSame(AtlasPolicyProfileService::SANDBOX_DANGER_FULL_ACCESS, $profile['sandbox']);
        $this->assertSame(PolicyCanon::DECISION_REQUIRE_APPROVAL, $profile['decision']);
        $this->assertSame(AtlasPolicyProfileService::NEXT_PROCEED, $profile['next_step']);
        $this->assertSame([], $profile['tools']['deny']);
    }

    public function testProviderCannotRequestSandboxBeyondPolicyGrant(): void
    {
        // Escopo: no bypass-by-provider-preference. A read-only request may not
        // be widened to danger-full-access by asking for it.
        $auth = $this->service->authorizeSandbox(
            AtlasPolicyProfileService::SANDBOX_DANGER_FULL_ACCESS,
            ['intent' => 'analysis.read', 'risk' => 'low']
        );

        $this->assertSame(AtlasPolicyProfileService::SANDBOX_READ_ONLY, $auth['granted']);
        $this->assertFalse($auth['authorized']);
        $this->assertSame('requested_sandbox_exceeds_policy_grant', $auth['reason']);
    }

    public function testHighRiskNonDangerousActionDropsFromAutonomousToApproval(): void
    {
        // Risk gate: high/critical risk can never run autonomously even when the
        // action itself is not on the dangerous list.
        $profile = $this->service->resolveProfile([
            'intent' => 'analysis.read',
            'risk' => 'critical',
            'environment' => 'workspace',
        ]);

        $this->assertFalse($profile['dangerous']);
        $this->assertSame(PolicyCanon::DECISION_REQUIRE_APPROVAL, $profile['decision']);
        $this->assertContains('risk_review', $profile['required_approvals']);
        $this->assertSame(1.0, $profile['cost_ceiling']);
    }

    public function testProductionEnvironmentWithoutAuthorizationReducesScope(): void
    {
        // Production is elevated: without a signature the step blocks and forces
        // scope reduction rather than passing through.
        $profile = $this->service->resolveProfile([
            'intent' => 'programming.edit',
            'risk' => 'low',
            'environment' => 'production',
            'signature_present' => false,
        ]);

        $this->assertSame(PolicyCanon::DECISION_BLOCKED, $profile['decision']);
        $this->assertSame(AtlasPolicyProfileService::NEXT_REDUCE_SCOPE, $profile['next_step']);
        $this->assertContains('production_environment', $profile['required_approvals']);
    }
}
