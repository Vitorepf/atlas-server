<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\ContextIntelligence;

use App\Services\Ai\ContextIntelligence\AtlasContextIntelligenceService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class AtlasContextIntelligenceServiceTest extends TestCase
{
    public function test_assess_emits_context_certification_without_provider_or_benchmark(): void
    {
        $payload = app(AtlasContextIntelligenceService::class)->assess([
            'now' => CarbonImmutable::parse('2026-05-20 10:00:00'),
            'prompt' => 'implemente um ajuste pequeno com evidencia',
            'task_type' => 'programming',
            'domain' => 'programming',
            'risk_level' => 'medium',
            'context_refs' => ['file:app/Services/Ai/AiCompactionService.php'],
            'must_keep_items' => [['kind' => 'constraint', 'digest' => 'nao rodar benchmark']],
        ]);

        $this->assertSame(AtlasContextIntelligenceService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertContains($payload['status'], [
            AtlasContextIntelligenceService::STATUS_READY,
            AtlasContextIntelligenceService::STATUS_DEGRADED,
        ]);
        $this->assertArrayHasKey('context_certification_hash', $payload);
        $this->assertFalse($payload['claim_policy']['provider_calls_made']);
        $this->assertFalse($payload['claim_policy']['rivals_compared']);
        $this->assertTrue($payload['claim_policy']['benchmark_not_run']);
        $this->assertFalse($payload['writes']);
    }

    public function test_assess_blocks_must_keep_without_evidence(): void
    {
        $payload = app(AtlasContextIntelligenceService::class)->assess([
            'prompt' => 'preserve esta decisao critica',
            'must_keep_items' => [['kind' => 'decision', 'digest' => 'critical']],
            'context_refs' => [],
        ]);

        $this->assertSame(AtlasContextIntelligenceService::STATUS_BLOCKED, $payload['status']);
        $this->assertSame('must_keep_without_evidence', $payload['blockers'][0]['id'] ?? null);
    }

    public function test_hash_is_stable_for_same_payload_ignoring_generated_at(): void
    {
        $input = [
            'prompt' => 'analise contexto',
            'context_refs' => ['doc:a'],
            'must_keep_items' => [['kind' => 'decision', 'digest' => 'x']],
        ];

        $a = app(AtlasContextIntelligenceService::class)->assess($input + ['now' => CarbonImmutable::parse('2026-05-20 10:00:00')]);
        $b = app(AtlasContextIntelligenceService::class)->assess($input + ['now' => CarbonImmutable::parse('2026-05-20 11:00:00')]);

        $this->assertSame($a['context_certification_hash'], $b['context_certification_hash']);
    }
}
