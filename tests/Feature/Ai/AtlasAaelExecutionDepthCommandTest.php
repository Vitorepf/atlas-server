<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\AtlasAaelExecutionSafeStateRecoverer;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasAaelExecutionDepthCommandTest extends TestCase
{
    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/atlas-aael-depth-'.bin2hex(random_bytes(6));
        @mkdir($this->sandbox.'/safe-state', 0o755, true);
        app()->bind('atlas.aael.sentinel_path', fn () => $this->sandbox.'/sentinel.json');
        app()->bind('atlas.aael.safe_state_dir', fn () => $this->sandbox.'/safe-state');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->sandbox);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $full = $dir.'/'.$f;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($dir);
    }

    public function test_prove_emits_facts_with_schema_and_no_score(): void
    {
        $planPath = $this->sandbox.'/plan.json';
        file_put_contents($planPath, json_encode([
            'steps' => [
                ['id' => 's1', 'objective_anchors' => ['A']],
                ['id' => 's2', 'objective_anchors' => ['B']],
            ],
        ]));

        Artisan::call('atlas:aael:depth', ['action' => 'prove', '--plan' => $planPath, '--objective' => 'A B C', '--json' => true]);
        $raw = trim(Artisan::output());
        $payload = json_decode($raw, true);

        $this->assertSame('atlas.aael.execution.plan_semantic_proof.v1', $payload['schema_version'] ?? ($payload['schema'] ?? null));
        $this->assertArrayHasKey('unmet_objective_anchors', $payload);
        $this->assertStringNotContainsString('"score"', $raw);
    }

    public function test_abort_raise_then_check_reports_aborted_true_with_reason(): void
    {
        Artisan::call('atlas:aael:depth', ['action' => 'abort', '--reason' => 'test_panic', '--json' => true]);
        Artisan::call('atlas:aael:depth', ['action' => 'abort', '--json' => true]);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertTrue($payload['aborted']);
        $this->assertSame('test_panic', $payload['reason']);
        $this->assertSame([], glob($this->sandbox.'/*.tmp') ?: []);
    }

    public function test_recover_with_valid_checkpoint_returns_zero_exit(): void
    {
        $recoverer = new AtlasAaelExecutionSafeStateRecoverer($this->sandbox.'/safe-state');
        $id = $recoverer->snapshot(['step' => 7, 'meta' => 'ok']);

        $exit = Artisan::call('atlas:aael:depth', ['action' => 'recover', '--checkpoint' => $id, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame($id, $payload['checkpoint_id']);
    }

    public function test_recover_with_bad_checkpoint_returns_non_zero_operational_error(): void
    {
        $exit = Artisan::call('atlas:aael:depth', ['action' => 'recover', '--checkpoint' => 'no-such-id', '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertArrayHasKey('error', $payload);
    }

    public function test_invariants_runs_when_plan_path_is_valid(): void
    {
        $planPath = $this->sandbox.'/plan.json';
        file_put_contents($planPath, json_encode(['steps' => [['id' => 's1', 'declares' => ['inv-a']]]]));

        $exit = Artisan::call('atlas:aael:depth', ['action' => 'invariants', '--plan' => $planPath, '--invariants' => ['inv-a', 'inv-b'], '--json' => true]);
        $this->assertSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
    }
}
