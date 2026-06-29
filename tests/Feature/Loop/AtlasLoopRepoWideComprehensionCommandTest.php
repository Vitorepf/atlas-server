<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\RepoWide\AtlasLoopRepoWideComprehensionModel;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the repo-wide comprehension model is live at the operator surface: it federates per-scope symbols and
 * clones, and a class that is orphan in its own scope but called from another scope is NOT a federated orphan;
 * the model_hash is stable across runs.
 */
final class AtlasLoopRepoWideComprehensionCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-repowide-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($this->input, (string) json_encode([
            'scopes' => [
                [
                    'inventory' => [
                        ['fqcn' => 'App\\A', 'is_orphan' => true],   // orphan in scope 1, but called in scope 2 ⇒ resolved
                        ['fqcn' => 'App\\B', 'is_orphan' => false],
                        ['fqcn' => 'App\\D', 'is_orphan' => true],   // orphan everywhere ⇒ stays
                    ],
                    'orphans' => ['App\\A', 'App\\D'],
                    'clone_clusters' => [['cluster_id' => 'cl1', 'members' => [['path' => 'a/A.php']]]],
                ],
                [
                    'inventory' => [['fqcn' => 'App\\C', 'is_orphan' => false]],
                    'caller_targets' => ['App\\A'], // A is called here
                ],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function build(): array
    {
        $exit = Artisan::call('atlas:loop:repo-wide-comprehension', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_federates_scopes_and_resolves_cross_domain_orphans(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->build();

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopRepoWideComprehensionModel::SCHEMA, $d['schema']);
        $this->assertSame(2, $d['scope_count']);
        $this->assertSame(4, $d['symbol_count'], (string) json_encode($d)); // A,B,C,D deduped
        // A is orphan in scope 1 but called in scope 2 ⇒ resolved; only D remains a federated orphan
        $this->assertSame(['App\\D'], $d['orphans']);
        $this->assertCount(1, $d['clones']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $d['model_hash']);
    }

    public function test_model_hash_is_stable(): void
    {
        $a = $this->build()['d'];
        $b = $this->build()['d'];

        $this->assertSame($a['model_hash'], $b['model_hash']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:repo-wide-comprehension', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
