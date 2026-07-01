<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainProviderIndependenceCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-provider-independence-cli-'.bin2hex(random_bytes(6));
        @mkdir($this->tempBase, 0o755, true);
        $this->inputFile = $this->tempBase.'/input.json';
    }

    protected function tearDown(): void
    {
        if ($this->tempBase !== '' && is_dir($this->tempBase)) {
            (new Process(['rm', '-rf', $this->tempBase]))->run();
        }
        parent::tearDown();
    }

    private function writeInput(array $data): void
    {
        file_put_contents($this->inputFile, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:provider-independence', $args);

        return [$exit, $kernel->output()];
    }

    public function test_missing_input_option_fails(): void
    {
        [$exit] = $this->runCmd([]);

        $this->assertSame(1, $exit);
    }

    public function test_nonexistent_input_path_fails(): void
    {
        [$exit] = $this->runCmd(['--input' => $this->tempBase.'/does-not-exist.json']);

        $this->assertSame(1, $exit);
    }

    public function test_invalid_json_fails(): void
    {
        file_put_contents($this->inputFile, 'not json');

        [$exit] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(1, $exit);
    }

    public function test_empty_sections_produce_clean_ready_report(): void
    {
        $this->writeInput([]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame([], $decoded['capability_matrix']);
        $this->assertTrue($decoded['autonomy_preserved']);
        $this->assertFalse($decoded['production_promotion_blocked']);
        $this->assertTrue($decoded['ready_for_production']);
    }

    public function test_required_for_steady_state_provider_blocks_promotion(): void
    {
        $this->writeInput([
            'provider_pools' => [
                [
                    'provider_id' => 'cursor',
                    'model_family' => 'composer',
                    'cost_tier' => 'strong',
                    'sdk_available' => true,
                    'headless_available' => false,
                ],
            ],
            'providers' => [
                [
                    'provider' => 'cursor',
                    'has_local_fallback' => false,
                    'required_for_steady_state' => true,
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('cursor', $decoded['capability_matrix'][0]['provider_id']);
        $this->assertFalse($decoded['capability_matrix'][0]['atlas_required']);
        $this->assertSame('unproven', $decoded['capability_matrix'][0]['integration_status']);
        $this->assertFalse($decoded['autonomy_preserved']);
        $this->assertTrue($decoded['production_promotion_blocked']);
        $this->assertContains('cursor', $decoded['required_for_steady_state_providers']);
        $this->assertNotEmpty($decoded['minimal_next_tasks_needed_to_restore_independence']);
        $this->assertFalse($decoded['ready_for_production']);
    }

    public function test_router_picks_cheapest_safe_candidate(): void
    {
        $this->writeInput([
            'router' => [
                'task_criticality' => 'low',
                'task_irreversible' => false,
                'candidates' => [
                    [
                        'pool_id' => 'cheap_pool',
                        'cost_tier' => 'cheap',
                        'model_strength' => 0.6,
                        'historical_success_rate' => 0.9,
                        'give_back_rate' => 0.05,
                        'proven' => true,
                    ],
                    [
                        'pool_id' => 'frontier_pool',
                        'cost_tier' => 'frontier',
                        'model_strength' => 0.95,
                        'historical_success_rate' => 0.95,
                        'give_back_rate' => 0.01,
                        'proven' => true,
                    ],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('cheap_pool', $decoded['route_decision']['pool_id']);
        $this->assertSame('frontier_pool', $decoded['fallback_route']['pool_id']);
    }

    public function test_fully_independent_pool_is_ready_for_production(): void
    {
        $this->writeInput([
            'provider_pools' => [
                [
                    'provider_id' => 'codex',
                    'model_family' => 'gpt',
                    'cost_tier' => 'strong',
                    'sdk_available' => true,
                    'headless_available' => true,
                ],
            ],
            'providers' => [
                [
                    'provider' => 'codex',
                    'has_local_fallback' => true,
                    'required_for_steady_state' => false,
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('production_ready', $decoded['capability_matrix'][0]['integration_status']);
        $this->assertTrue($decoded['autonomy_preserved']);
        $this->assertFalse($decoded['production_promotion_blocked']);
        $this->assertTrue($decoded['ready_for_production']);
    }
}
