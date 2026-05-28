<?php

namespace Tests\Unit\Ai\Programming\Sdd;

use App\Services\Ai\Programming\Governance\ProgrammingScopeMode;
use App\Services\Ai\Programming\Governance\ProgrammingWorkItemClassifier;
use App\Services\Ai\Programming\Sdd\Enums\ConfidenceClass;
use App\Services\Ai\Programming\Sdd\IntentRouter;
use App\Services\Ai\Programming\Sdd\Pipeline\SddPipelineOperationEnvelope as OperationEnvelope;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class IntentRouterTest extends TestCase
{
    /** @var ProgrammingWorkItemClassifier&MockObject */
    private ProgrammingWorkItemClassifier $classifier;

    private IntentRouter $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = $this->createMock(ProgrammingWorkItemClassifier::class);
        $this->router = new IntentRouter($this->classifier);
    }

    public function test_route_returns_blocking_ambiguity_for_blank_input(): void
    {
        $this->classifier
            ->expects($this->once())
            ->method('classify')
            ->with('   ', [])
            ->willReturn($this->classification(
                intentType: 'other',
                scopeMode: ProgrammingScopeMode::Compact->value,
                riskLevel: 'low',
                signals: [
                    'structural_matches' => [],
                    'high_risk_matches' => [],
                ],
            ));

        $intent = $this->router->route(new OperationEnvelope('   '));

        $this->assertSame(ConfidenceClass::BlockingAmbiguity, $intent->confidenceClass);
        $this->assertTrue($intent->confidenceClass->isBlocking());
    }

    public function test_route_falls_back_to_intent_domain_when_context_domain_is_blank(): void
    {
        $this->classifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn($this->classification(intentType: 'docs'));

        $intent = $this->router->route(new OperationEnvelope(
            'Update readme',
            context: ['domain' => ''],
        ));

        $this->assertSame('documentation', $intent->domain);
    }

    public function test_route_honors_non_blank_context_domain_override(): void
    {
        $this->classifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn($this->classification(intentType: 'feature'));

        $intent = $this->router->route(new OperationEnvelope(
            'Add export',
            context: ['domain' => 'payments'],
        ));

        $this->assertSame('payments', $intent->domain);
    }

    public function test_route_maps_intent_type_to_domain_when_context_has_no_override(): void
    {
        $this->classifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn($this->classification(intentType: 'migration'));

        $intent = $this->router->route(new OperationEnvelope('Create migration'));

        $this->assertSame('database', $intent->domain);
    }

    public function test_route_requires_harness_for_critical_risk(): void
    {
        $this->classifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn($this->classification(
                intentType: 'bugfix',
                scopeMode: ProgrammingScopeMode::Compact->value,
                riskLevel: 'critical',
            ));

        $intent = $this->router->route(new OperationEnvelope('Fix production crash'));

        $this->assertTrue($intent->harnessRequired);
    }

    public function test_route_flattens_and_deduplicates_classification_signals(): void
    {
        $this->classifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn($this->classification(
                signals: [
                    'structural_matches' => ['kernel', 'kernel', ''],
                    'high_risk_matches' => ['auth', 'kernel'],
                ],
            ));

        $intent = $this->router->route(new OperationEnvelope('Refactor kernel auth'));

        $this->assertSame(['kernel', 'auth'], $intent->signals);
    }

    public function test_route_marks_other_intent_without_structural_signals_as_hypothesis(): void
    {
        $this->classifier
            ->expects($this->once())
            ->method('classify')
            ->willReturn($this->classification(
                intentType: 'other',
                signals: [
                    'structural_matches' => [],
                    'high_risk_matches' => [],
                ],
            ));

        $intent = $this->router->route(new OperationEnvelope('Do something vague'));

        $this->assertSame(ConfidenceClass::Hypothesis, $intent->confidenceClass);
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    private function classification(
        string $intentType = 'feature',
        string $scopeMode = ProgrammingScopeMode::Structural->value,
        string $riskLevel = 'medium',
        array $signals = [],
    ): array {
        return [
            'intent_type' => $intentType,
            'scope_mode' => $scopeMode,
            'risk_level' => $riskLevel,
            'signals' => $signals !== [] ? $signals : [
                'structural_matches' => ['feature'],
                'high_risk_matches' => [],
            ],
        ];
    }
}
