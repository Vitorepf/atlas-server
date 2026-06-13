<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AobgSemanticRetrievalLiftService;
use App\Services\Ai\Context\SemanticContextRetrievalService;
use App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

final class AobgSemanticRetrievalLiftTest extends TestCase
{
    public function test_positive_lift_recommends_enabling_aobg_semantic_retrieval(): void
    {
        config(['atlas.aobg.semantic_retrieval' => false]);
        $runtime = new L411RuntimeFixture(available: true, preferRelevant: true);
        $service = new AobgSemanticRetrievalLiftService(new SemanticContextRetrievalService($runtime), $runtime);

        $report = $service->report();

        $this->assertFalse(config('atlas.aobg.semantic_retrieval'), 'measurement must restore the original flag');
        $this->assertSame('positive_lift', $report['status']);
        $this->assertSame(10, $report['measurement']['measured_case_count']);
        $this->assertGreaterThan(0, $report['measurement']['positive_lift_case_count']);
        $this->assertGreaterThan(0.0, $report['measurement']['average_lift']);
        $this->assertTrue($report['decision']['should_enable']);
        $this->assertSame('enable_aobg_semantic_retrieval', $report['decision']['decision']);
        $this->assertFalse($report['claim_policy']['provider_calls_made']);
    }

    public function test_unavailable_runtime_keeps_activation_unmeasured_and_disabled(): void
    {
        config(['atlas.aobg.semantic_retrieval' => false]);
        $runtime = new L411RuntimeFixture(available: false, preferRelevant: true);
        $service = new AobgSemanticRetrievalLiftService(new SemanticContextRetrievalService($runtime), $runtime);

        $report = $service->report();

        $this->assertSame('unmeasured', $report['status']);
        $this->assertSame(0, $report['measurement']['measured_case_count']);
        $this->assertFalse($report['decision']['should_enable']);
        $this->assertSame('keep_current_or_disabled', $report['decision']['decision']);
    }

    public function test_non_positive_lift_does_not_recommend_activation(): void
    {
        config(['atlas.aobg.semantic_retrieval' => false]);
        $runtime = new L411RuntimeFixture(available: true, preferRelevant: false);
        $service = new AobgSemanticRetrievalLiftService(new SemanticContextRetrievalService($runtime), $runtime);

        $report = $service->report();

        $this->assertSame('no_positive_lift', $report['status']);
        $this->assertSame(10, $report['measurement']['measured_case_count']);
        $this->assertFalse($report['decision']['should_enable']);
    }

    public function test_command_json_uses_bound_runtime_and_succeeds_without_provider_calls(): void
    {
        $runtime = new L411RuntimeFixture(available: true, preferRelevant: true);
        $this->app->instance(SemanticRetrievalRuntime::class, $runtime);
        $this->app->instance(SemanticContextRetrievalService::class, new SemanticContextRetrievalService($runtime));

        $exit = Artisan::call('atlas:aobg:semantic-lift', ['--json' => true, '--strict' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('positive_lift', $payload['status']);
        $this->assertTrue($payload['decision']['should_enable']);
        $this->assertFalse($payload['claim_policy']['provider_calls_made']);
    }
}

final class L411RuntimeFixture implements SemanticRetrievalRuntime
{
    public function __construct(
        private readonly bool $available,
        private readonly bool $preferRelevant,
    ) {}

    public function available(): bool
    {
        return $this->available;
    }

    public function retrieve(
        array $documents,
        string $query,
        int $k = 5,
        bool $graphExpand = false,
        float $graphThreshold = 0.6,
    ): array {
        if (! $this->available) {
            throw new RuntimeException('unavailable');
        }

        $matches = [];
        foreach ($documents as $document) {
            $id = (string) ($document['id'] ?? '');
            $matches[] = [
                'id' => $id,
                'score' => $this->scoreFor($id),
            ];
        }

        return [
            'matches' => $matches,
            'boundary' => [
                'real_embeddings' => true,
                'embeddings_engine_in_python' => true,
                'fabricated_vectors' => false,
            ],
        ];
    }

    private function scoreFor(string $id): float
    {
        if (str_ends_with($id, '_relevant')) {
            return $this->preferRelevant ? 0.95 : 0.1;
        }
        if (str_ends_with($id, '_lexical_decoy')) {
            return $this->preferRelevant ? 0.2 : 0.96;
        }

        return 0.05;
    }
}
