<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasDecideMetaLearningCommandTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/atlas_meta_cli_'.uniqid('', true);
        @mkdir($this->tmpRoot, 0775, true);
        $svc = $this->app->make(AtlasDecideMetaLearningService::class);
        $svc->setActivationLogPathForTesting($this->tmpRoot.'/routing_activations.jsonl');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpRoot)) {
            foreach (glob($this->tmpRoot.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpRoot);
        }
        parent::tearDown();
    }

    public function test_list_command_runs_with_zero_exit(): void
    {
        $out = new BufferedOutput;
        $code = Artisan::call('atlas:atlas-decide:meta-learning', [], $out);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('[atlas:atlas-decide:meta-learning]', $out->fetch());
    }

    public function test_json_output_is_well_formed(): void
    {
        $out = new BufferedOutput;
        $code = Artisan::call('atlas:atlas-decide:meta-learning', ['--json' => true], $out);
        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('meta-learning:list', $decoded['action']);
        $this->assertArrayHasKey('recommendations', $decoded);
    }

    public function test_routing_table_command_runs(): void
    {
        $out = new BufferedOutput;
        $code = Artisan::call('atlas:atlas-decide:routing-table', ['--json' => true], $out);
        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertSame('routing-table', $decoded['action']);
        $this->assertArrayHasKey('entries', $decoded['table']);
        $this->assertSame(AtlasDecideMetaLearningService::TABLE_SCHEMA, $decoded['table']['schema_version']);
    }

    public function test_activate_requires_apply_and_confirm(): void
    {
        $out = new BufferedOutput;
        $code = Artisan::call('atlas:atlas-decide:meta-learning:activate', [
            '--task-category' => 'frontend',
            '--role' => 'builder',
            '--mode' => 'plan',
            '--json' => true,
        ], $out);
        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertSame('planned', $decoded['status']);
    }

    public function test_reset_plan_does_not_mutate(): void
    {
        $out = new BufferedOutput;
        $code = Artisan::call('atlas:atlas-decide:meta-learning:reset', [
            '--mode' => 'plan',
            '--json' => true,
        ], $out);
        $this->assertSame(0, $code);
        $decoded = json_decode($out->fetch(), true);
        $this->assertSame('planned', $decoded['status']);
    }
}
