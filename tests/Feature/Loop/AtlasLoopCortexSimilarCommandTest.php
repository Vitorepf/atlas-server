<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopCortexSimilarCommand;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Similarity\AtlasCortexSymbolSimilarityPairFact;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopCortexSimilarCommandTest extends TestCase
{
    private string $historyDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->historyDir = sys_get_temp_dir().'/atlas-cortex-sim-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->historyDir);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir.'/*') as $entry) {
            @unlink((string) $entry);
        }
        @rmdir($dir);
    }

    private function bindFacts(array $facts): void
    {
        $this->app->instance(
            AtlasLoopCortexSimilarCommand::PAIR_FACTS_SOURCE_BINDING,
            static fn (): array => $facts,
        );
    }

    private function pair(string $a, string $b, float $token, float $ast, float $method, int $methodCount = 1): AtlasCortexSymbolSimilarityPairFact
    {
        return new AtlasCortexSymbolSimilarityPairFact(
            pairA: $a,
            pairB: $b,
            tokenOverlapRatio: $token,
            astShapeOverlapRatio: $ast,
            methodNameOverlapCount: $methodCount,
            methodNameOverlapRatio: $method,
            fingerprints: ['token_a' => 'abc', 'token_b' => 'def'],
        );
    }

    public function test_pair_emits_all_three_overlap_channels_and_is_byte_stable(): void
    {
        $this->bindFacts([
            $this->pair('App\\Foo', 'App\\Bar', 0.8, 0.7, 0.6),
        ]);

        $first = Artisan::call('atlas:loop:cortex:similar', [
            'action' => 'pair',
            'fqcnA' => 'App\\Foo',
            'fqcnB' => 'App\\Bar',
            '--json' => true,
        ]);
        $firstOut = trim(Artisan::output());

        $second = Artisan::call('atlas:loop:cortex:similar', [
            'action' => 'pair',
            'fqcnA' => 'App\\Foo',
            'fqcnB' => 'App\\Bar',
            '--json' => true,
        ]);
        $secondOut = trim(Artisan::output());

        $this->assertSame(0, $first);
        $this->assertSame(0, $second);
        $this->assertSame($firstOut, $secondOut, 'pair output must be byte-stable across runs');

        $payload = json_decode($firstOut, true);
        $this->assertArrayHasKey('token_overlap_ratio', $payload);
        $this->assertArrayHasKey('ast_shape_overlap_ratio', $payload);
        $this->assertArrayHasKey('method_name_overlap_ratio', $payload);
        foreach (['score', 'grade', 'verdict'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload);
        }
    }

    public function test_cluster_groups_three_members_under_thresholds(): void
    {
        // Three pairs between A,B,C — all qualify under the thresholds.
        $this->bindFacts([
            $this->pair('App\\A', 'App\\B', 0.8, 0.8, 0.6),
            $this->pair('App\\B', 'App\\C', 0.8, 0.8, 0.6),
            $this->pair('App\\A', 'App\\C', 0.8, 0.8, 0.6),
        ]);

        $exit = Artisan::call('atlas:loop:cortex:similar', [
            'action' => 'cluster',
            '--token' => 0.7,
            '--ast' => 0.7,
            '--method' => 0.5,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $clusters = (array) ($payload['clusters'] ?? []);
        $this->assertCount(1, $clusters);
        $this->assertSame(['App\\A', 'App\\B', 'App\\C'], $clusters[0]['members']);
    }

    public function test_cluster_rejects_out_of_range_threshold(): void
    {
        $this->bindFacts([]);
        $exit = Artisan::call('atlas:loop:cortex:similar', [
            'action' => 'cluster',
            '--token' => -0.1,
            '--ast' => 0.5,
            '--method' => 0.5,
            '--json' => true,
        ]);
        $this->assertNotSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame('invalid_threshold', $payload['error']);
    }

    public function test_history_abstains_when_snapshot_directory_missing(): void
    {
        $this->app->instance(AtlasLoopCortexSimilarCommand::HISTORY_DIR_BINDING, $this->historyDir.'/missing');

        $exit = Artisan::call('atlas:loop:cortex:similar', [
            'action' => 'history',
            'fqcnA' => 'App\\MissingFqcn',
            '--limit' => 5,
            '--json' => true,
        ]);
        $this->assertNotSame(0, $exit, 'history must NOT exit zero when no snapshots exist');
        $combined = Artisan::output();
        $this->assertStringContainsString('no_FACT', $combined);
    }

    public function test_pair_falls_back_to_fact_extractor_when_no_binding(): void
    {
        // No PAIR_FACTS_SOURCE_BINDING registered → fallback must produce real pair FACTS
        // via AtlasCortexSymbolSimilarityFactExtractor against the Discovery scope.
        $exit = Artisan::call('atlas:loop:cortex:similar', [
            'action' => 'pair',
            'fqcnA' => 'App\\Services\\Ai\\AutonomousEvolution\\Discovery\\AtlasLoopScopeComprehensionModel',
            'fqcnB' => 'App\\Services\\Ai\\AutonomousEvolution\\Discovery\\Cortex\\Similarity\\AtlasCortexSymbolSimilarityFactExtractor',
            '--json' => true,
        ]);

        $combined = Artisan::output();
        $this->assertNotSame(
            'cortex_read_model_unavailable',
            str_contains($combined, 'cortex_read_model_unavailable') ? 'cortex_read_model_unavailable' : 'ok',
            'fallback must NOT abstain with no_FACT/cortex_read_model_unavailable'
        );
        $this->assertSame(0, $exit, 'fallback pair must exit zero');
        $payload = json_decode(trim($combined), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('token_overlap_ratio', $payload);
        $this->assertArrayHasKey('ast_shape_overlap_ratio', $payload);
        $this->assertArrayHasKey('method_name_overlap_ratio', $payload);
    }

    public function test_cluster_falls_back_to_fact_extractor_when_no_binding(): void
    {
        // No PAIR_FACTS_SOURCE_BINDING → cluster must still return clusters (or empty)
        // built from the FactExtractor fallback (never no_FACT/cortex_read_model_unavailable).
        $exit = Artisan::call('atlas:loop:cortex:similar', [
            'action' => 'cluster',
            '--token' => '0.1',
            '--ast' => '0.1',
            '--method' => '0.1',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $combined = Artisan::output();
        $this->assertStringNotContainsString('cortex_read_model_unavailable', $combined);
        $this->assertStringNotContainsString('"error":"no_FACT"', $combined);
        $payload = json_decode(trim($combined), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('clusters', $payload);
        $this->assertSame('cluster', $payload['action']);
    }

    public function test_explicit_pair_facts_source_binding_takes_precedence_over_fallback(): void
    {
        // When the binding is present, it MUST win — even if the fallback would
        // also produce a valid pair (we bind a tiny synthetic pair list that the
        // fallback cannot reproduce).
        $bound = $this->pair('App\\BoundOnlyA', 'App\\BoundOnlyB', 0.5, 0.5, 0.5);
        $this->bindFacts([$bound]);

        $exit = Artisan::call('atlas:loop:cortex:similar', [
            'action' => 'pair',
            'fqcnA' => 'App\\BoundOnlyA',
            'fqcnB' => 'App\\BoundOnlyB',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame(0.5, $payload['token_overlap_ratio']);
        $this->assertSame(0.5, $payload['ast_shape_overlap_ratio']);
        $this->assertSame(0.5, $payload['method_name_overlap_ratio']);
    }
}
