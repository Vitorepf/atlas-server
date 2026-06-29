<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the obra-candidate ranker is live at the operator surface: a high-leverage, larger-scope cluster
 * outranks a small low-leverage one (panel value · scope under the ambition strategy), and unmappable payloads
 * fail open to an empty ranking.
 */
final class AtlasLoopObraCandidateRankCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-obra-rank-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function payload(string $hash, float $leverage, int $cyclomatic, array $files): array
    {
        return [
            'obra_cluster_candidate' => [
                'cluster_hash' => $hash,
                'objective_kind' => 'refactor',
                'allowed_files' => $files,
                'leverage_signals' => [
                    'refactor_leverage' => $leverage,
                    'cyclomatic_total' => $cyclomatic,
                    'caller_count' => 0,
                ],
            ],
        ];
    }

    public function test_high_leverage_large_cluster_outranks_small_one(): void
    {
        file_put_contents($this->input, (string) json_encode([
            $this->payload('small', 0.2, 12, ['app/A.php']),
            $this->payload('big', 0.9, 120, ['app/B1.php', 'app/B2.php', 'app/B3.php', 'app/B4.php']),
        ]));

        $exit = Artisan::call('atlas:loop:obra-candidate-rank', ['--input' => $this->input, '--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.obra_candidate_rank.v1', $d['schema']);
        $this->assertSame('big', $d['pick'], (string) json_encode($d));
        $this->assertSame(['big', 'small'], array_column($d['ranked'], 'candidateId'));
        $this->assertSame('refactor', $d['ranked'][0]['class']);
        $this->assertNotSame('', $d['ranked'][0]['gate']);
    }

    public function test_unmappable_payloads_fail_open_empty(): void
    {
        // no obra_cluster_candidate block ⇒ nothing competes
        file_put_contents($this->input, (string) json_encode([['not_a_candidate' => true], ['x' => 1]]));

        $exit = Artisan::call('atlas:loop:obra-candidate-rank', ['--input' => $this->input, '--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertNull($d['pick']);
        $this->assertSame(0, $d['count']);
        $this->assertSame([], $d['ranked']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:obra-candidate-rank', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
