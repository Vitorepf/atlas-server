<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskRespecPlanBuilder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves atlas:task:quality: inspect lists services + non-mutation guarantees; respec-plan returns
 * a plan envelope; bulk-draft handles records arrays; lint returns instruction-lint findings; missing
 * --packet ⇒ usage_error; malformed --input ⇒ usage_error.
 */
final class AtlasTaskQualityCommandTest extends TestCase
{
    private string $packetPath;

    private string $inputPath;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(6));
        $this->packetPath = sys_get_temp_dir().'/atlas_tq_packet_'.$tag.'.json';
        $this->inputPath = sys_get_temp_dir().'/atlas_tq_input_'.$tag.'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->packetPath);
        @unlink($this->inputPath);
        parent::tearDown();
    }

    private function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public function test_inspect_lists_services_and_non_mutation_guarantees(): void
    {
        $exit = Artisan::call('atlas:task:quality', ['action' => 'inspect', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertFalse($p['non_execution_guarantees']['mutates_queue_records']);
        $this->assertFalse($p['non_execution_guarantees']['marks_packet_complete']);
    }

    public function test_respec_plan_returns_envelope_with_keep_action_for_clean_packet(): void
    {
        $this->writeJson($this->packetPath, ['packet_id' => 'p-1']);
        Artisan::call('atlas:task:quality', ['action' => 'respec-plan', '--packet' => $this->packetPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(AtlasTaskRespecPlanBuilder::ACTION_KEEP, $p['respec_plan']['action']);
    }

    public function test_bulk_draft_returns_envelope_from_records_array(): void
    {
        $this->writeJson($this->inputPath, ['records' => [
            ['packet_id' => 'p-a', 'missing_files' => ['app/X.php']],
            ['packet_id' => 'p-b', 'missing_files' => ['app/Y.php']],
        ]]);
        Artisan::call('atlas:task:quality', ['action' => 'bulk-draft', '--input' => $this->inputPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertCount(1, $p['bulk_draft']['drafts']);
        $this->assertSame(['p-a', 'p-b'], $p['bulk_draft']['drafts'][0]['packet_ids']);
    }

    public function test_lint_returns_findings_envelope(): void
    {
        $this->writeJson($this->packetPath, ['packet_id' => 'p', 'worker_instructions' => 'git push to main after the change']);
        Artisan::call('atlas:task:quality', ['action' => 'lint', '--packet' => $this->packetPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertFalse($p['lint']['accepted']);
        $this->assertContains('run_git_manually', $p['lint']['findings']);
    }

    public function test_missing_packet_yields_usage_error(): void
    {
        $exit = Artisan::call('atlas:task:quality', ['action' => 'respec-plan', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $p['status']);
    }

    public function test_malformed_input_yields_usage_error(): void
    {
        $this->writeJson($this->inputPath, ['not_records' => []]);
        $exit = Artisan::call('atlas:task:quality', ['action' => 'bulk-draft', '--input' => $this->inputPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $p['status']);
    }

    public function test_unknown_action_yields_unknown_action(): void
    {
        $exit = Artisan::call('atlas:task:quality', ['action' => 'bogus', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_action', $p['status']);
    }
}
