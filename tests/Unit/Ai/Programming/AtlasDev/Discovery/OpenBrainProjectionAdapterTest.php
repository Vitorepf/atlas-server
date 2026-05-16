<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Discovery;

use App\Services\Ai\AtlasOpenBrainService;
use App\Services\Ai\Programming\AtlasDev\Discovery\DocContextTierSelector;
use App\Services\Ai\Programming\AtlasDev\Discovery\OpenBrainProjectionAdapter;
use App\Services\Ai\Programming\AtlasDev\Schemas\ContextRetrievalPlan;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OpenBrainProjectionAdapterTest extends TestCase
{
    public function test_within_budget_yields_untruncated_projection(): void
    {
        $envelope = DiscoveryFixtureFactory::envelope();
        $compactSdd = DiscoveryFixtureFactory::compactSdd();
        $plan = $this->basePlan($envelope->runId);

        $fakeOpenBrain = $this->fakeOpenBrain([
            'ok' => true,
            'context_refs' => [
                ['type' => 'atlas_memory_entry', 'id' => 'mem-1', 'memory_type' => 'decision'],
                ['type' => 'semantic_note', 'id' => 'note-1', 'title' => 'atlas-dev-efficient-programming-flow'],
                ['type' => 'atlas_engineering_code_module', 'id' => 'mod-1', 'slug' => 'atlas-cli-dev-workflow-service'],
            ],
        ]);

        $adapter = new OpenBrainProjectionAdapter($fakeOpenBrain);

        $projection = $adapter->projectFor($envelope, $compactSdd, $plan);

        $this->assertFalse($projection->isTruncated());
        $this->assertNotEmpty($projection->memoryRefs);
        $this->assertNotEmpty($projection->knowledgeRefs);
        $this->assertNotEmpty($projection->codeRefs);
    }

    public function test_over_budget_truncates_and_reports_reason(): void
    {
        $envelope = DiscoveryFixtureFactory::envelope();
        $compactSdd = DiscoveryFixtureFactory::compactSdd();
        $plan = $this->basePlan($envelope->runId, budgetChars: 600);

        $refs = [];
        for ($i = 0; $i < 30; $i++) {
            $refs[] = ['type' => 'semantic_note', 'id' => 'note-'.$i, 'title' => 'note '.$i];
        }

        $fakeOpenBrain = $this->fakeOpenBrain(['ok' => true, 'context_refs' => $refs]);
        $adapter = new OpenBrainProjectionAdapter($fakeOpenBrain);

        $projection = $adapter->projectFor($envelope, $compactSdd, $plan);

        $this->assertTrue($projection->isTruncated());
        $reasons = (array) ($projection->truncation['reasons'] ?? []);
        $this->assertContains('estimated_chars_exceeds_budget', $reasons);
        $this->assertLessThan(30, count($projection->knowledgeRefs));
    }

    public function test_required_source_not_covered_lands_in_missing_sources(): void
    {
        $envelope = DiscoveryFixtureFactory::envelope();
        $compactSdd = DiscoveryFixtureFactory::compactSdd();
        $plan = new ContextRetrievalPlan(
            runId: $envelope->runId,
            selectedTiers: ['core', 'code_intelligence', 'sdd'],
            budgetChars: 12000,
            requiredSources: [
                'doc://engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md',
                'doc://engineering-knowledge-base/atlas-programming-governance-system.md',
            ],
            optionalSources: [],
            missingSources: [],
            truncationPolicy: ['open_brain_mode' => DocContextTierSelector::OPEN_BRAIN_MODE_AUTO, 'reasons' => []],
            providerSafe: true,
            planHash: 'planhash-test',
        );

        $fakeOpenBrain = $this->fakeOpenBrain([
            'ok' => true,
            'context_refs' => [
                ['type' => 'semantic_note', 'id' => 'covers-1', 'title' => 'atlas-dev-efficient-programming-flow'],
            ],
        ]);

        $adapter = new OpenBrainProjectionAdapter($fakeOpenBrain);
        $projection = $adapter->projectFor($envelope, $compactSdd, $plan);

        $this->assertContains(
            'doc://engineering-knowledge-base/atlas-programming-governance-system.md',
            $projection->missingSources,
        );
        $this->assertNotContains(
            'doc://engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md',
            $projection->missingSources,
            'covered source must not appear as missing',
        );
    }

    public function test_open_brain_mode_off_returns_empty_projection_without_calling_service(): void
    {
        $envelope = DiscoveryFixtureFactory::envelope();
        $compactSdd = DiscoveryFixtureFactory::compactSdd();
        $plan = $this->basePlan($envelope->runId, mode: DocContextTierSelector::OPEN_BRAIN_MODE_OFF);

        $fakeOpenBrain = $this->fakeOpenBrain(
            payload: null,
            throwOnCall: true,
            countCalls: $calls,
        );

        $adapter = new OpenBrainProjectionAdapter($fakeOpenBrain);
        $projection = $adapter->projectFor($envelope, $compactSdd, $plan);

        $this->assertSame(0, $calls, 'open_brain_mode=off must short-circuit the service call');
        $this->assertSame([], $projection->memoryRefs);
        $this->assertSame([], $projection->knowledgeRefs);
        $this->assertSame([], $projection->codeRefs);
        $this->assertTrue($projection->isTruncated());
        $this->assertContains('open_brain_mode=off', (array) ($projection->truncation['reasons'] ?? []));
    }

    public function test_service_failure_degrades_to_empty_projection_not_exception(): void
    {
        $envelope = DiscoveryFixtureFactory::envelope();
        $compactSdd = DiscoveryFixtureFactory::compactSdd();
        $plan = $this->basePlan($envelope->runId);

        $fakeOpenBrain = $this->fakeOpenBrain(payload: null, throwOnCall: true);
        $adapter = new OpenBrainProjectionAdapter($fakeOpenBrain);

        $projection = $adapter->projectFor($envelope, $compactSdd, $plan);

        $this->assertTrue($projection->isTruncated());
        $this->assertContains(
            'open_brain_unavailable',
            (array) ($projection->truncation['reasons'] ?? []),
        );
    }

    public function test_projection_is_deterministic_byte_identical_payload(): void
    {
        $envelope = DiscoveryFixtureFactory::envelope();
        $compactSdd = DiscoveryFixtureFactory::compactSdd();
        $plan = $this->basePlan($envelope->runId);
        $fakeOpenBrain = $this->fakeOpenBrain([
            'ok' => true,
            'context_refs' => [
                ['type' => 'atlas_memory_entry', 'id' => 'mem-1', 'memory_type' => 'decision'],
                ['type' => 'semantic_note', 'id' => 'note-1', 'title' => 'atlas-dev'],
            ],
        ]);

        $adapter = new OpenBrainProjectionAdapter($fakeOpenBrain);

        $a = $adapter->projectFor($envelope, $compactSdd, $plan);
        $b = $adapter->projectFor($envelope, $compactSdd, $plan);

        $this->assertSame($a->toJson(), $b->toJson());
        $this->assertSame($a->projectionHash, $b->projectionHash);
    }

    public function test_no_rivals_or_benchmark_leakage_in_canonical_payload(): void
    {
        $envelope = DiscoveryFixtureFactory::envelope();
        $compactSdd = DiscoveryFixtureFactory::compactSdd();
        $plan = $this->basePlan($envelope->runId);
        $fakeOpenBrain = $this->fakeOpenBrain([
            'ok' => true,
            'context_refs' => [
                ['type' => 'atlas_memory_entry', 'id' => 'mem-1', 'memory_type' => 'decision'],
            ],
        ]);

        $adapter = new OpenBrainProjectionAdapter($fakeOpenBrain);
        $serialized = $adapter->projectFor($envelope, $compactSdd, $plan)->toJson();

        foreach (['rivals', 'benchmark', 'opus', 'messy_human_local', 'cost_normalized_score'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $serialized);
        }
    }

    private function basePlan(
        string $runId,
        int $budgetChars = 12000,
        string $mode = DocContextTierSelector::OPEN_BRAIN_MODE_AUTO,
    ): ContextRetrievalPlan {
        return new ContextRetrievalPlan(
            runId: $runId,
            selectedTiers: ['core', 'code_intelligence', 'sdd'],
            budgetChars: $budgetChars,
            requiredSources: [],
            optionalSources: [],
            missingSources: [],
            truncationPolicy: ['open_brain_mode' => $mode, 'reasons' => []],
            providerSafe: true,
            planHash: 'planhash-test',
        );
    }

    /**
     * @param  array<string,mixed>|null  $payload
     */
    private function fakeOpenBrain(?array $payload = null, bool $throwOnCall = false, ?int &$countCalls = null): AtlasOpenBrainService
    {
        $countCalls = 0;

        return new class($payload, $throwOnCall, $countCalls) extends AtlasOpenBrainService
        {
            public function __construct(
                private readonly ?array $payload,
                private readonly bool $throwOnCall,
                private int &$countCalls,
            ) {
                // Bypass parent constructor — we never touch the real builder.
            }

            public function contextPack(array $data, string $surface = 'api'): array
            {
                $this->countCalls++;
                if ($this->throwOnCall) {
                    throw new RuntimeException('forced failure for adapter test');
                }

                return $this->payload ?? ['ok' => false];
            }
        };
    }
}
