<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\AtlasContextFreshnessQualityGateService;
use Tests\TestCase;

final class AtlasContextFreshnessQualityGateServiceTest extends TestCase
{
    private function service(): AtlasContextFreshnessQualityGateService
    {
        return app(AtlasContextFreshnessQualityGateService::class);
    }

    public function test_provider_unsafe_selected_context_returns_operator_review_or_stricter_and_blocked(): void
    {
        // max_refs=2 forces missing_required_source_coverage which is the only reliable way to
        // reach a blocked ranking outcome deterministically; combined with an explicit
        // contradiction signal this also exercises the provider_unsafe/blocking_reasons path.
        $payload = $this->service()->evaluate([
            'objective' => 'corrigir bug no repo com teste falhando e evidence replay',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'max_refs' => 2,
        ]);

        self::assertSame('blocked', $payload['status']);
        self::assertContains(
            data_get($payload, 'decision_ladder.action'),
            ['operator_review', 'refresh_retrieval'],
        );
        self::assertSame('blocked', data_get($payload, 'decision_ladder.status'));
    }

    public function test_stale_high_risk_context_never_returns_allow_context(): void
    {
        $payload = $this->service()->evaluate([
            'objective' => 'decisao critica sobre arquitetura e impacto entre sistemas',
            'task_type' => 'decision',
            'domain' => 'strategy',
            'risk_level' => 'high',
            'max_refs' => 8,
        ]);

        self::assertNotSame('allow_context', data_get($payload, 'decision_ladder.action'));
        self::assertContains(
            data_get($payload, 'decision_ladder.action'),
            ['refresh_retrieval', 'operator_review'],
        );
    }

    public function test_fresh_provider_safe_coverage_with_no_contradictions_allows_context(): void
    {
        $payload = $this->service()->evaluate([
            'objective' => 'corrigir bug no repo com teste falhando e evidence replay',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'max_refs' => 8,
        ]);

        self::assertSame('passed', $payload['status']);
        self::assertSame('allow_context', data_get($payload, 'decision_ladder.action'));
        self::assertSame('allowed', data_get($payload, 'decision_ladder.status'));
    }
}
