<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainKnowledgeFreshnessCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-knowledge-freshness-cli-'.bin2hex(random_bytes(6));
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
        $exit = $kernel->call('atlas:external-brain:knowledge-freshness', $args);

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

    public function test_stale_docs_and_code_index_block_post_merge_plan(): void
    {
        $this->writeInput([
            'artifact_map' => [
                'changed_files' => ['docs/foo.md', 'app/Services/Foo.php'],
                'event_type' => 'post_merge',
            ],
            'docs_drift' => [
                'now_unix' => 1000,
            ],
            'code_index' => [
                'manifest' => ['workspace_id' => 'ws1', 'required_artifacts' => ['atlas_code_symbols']],
                'observations' => ['now_unix' => 1000],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('ok', $decoded['status']);
        $this->assertFalse($decoded['docs_drift_conformant']);
        $this->assertFalse($decoded['code_index_ready']);
        $this->assertTrue($decoded['post_merge_blocked']);
        $this->assertNotEmpty($decoded['post_merge_blockers']);
    }

    public function test_fresh_docs_and_code_index_produce_unblocked_post_merge_plan(): void
    {
        $this->writeInput([
            'artifact_map' => [
                'changed_files' => [],
            ],
            'docs_drift' => [
                'now_unix' => 1000,
            ],
            'code_index' => [
                'manifest' => ['workspace_id' => 'ws1', 'required_artifacts' => ['atlas_code_symbols']],
                'observations' => [
                    'now_unix' => 1000,
                    'indexed_at_unix' => 1000,
                    'index_code_status' => 'pass',
                    'observed_artifacts' => ['atlas_code_symbols'],
                    'local_schema_available' => true,
                ],
            ],
            'post_merge' => [
                'artifact_map' => ['project_ids' => ['proj1']],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertTrue($decoded['docs_drift_conformant']);
        $this->assertTrue($decoded['code_index_ready']);
        $this->assertFalse($decoded['post_merge_blocked']);
        $this->assertNotEmpty($decoded['post_merge_actions']);
    }

    public function test_missing_queue_reality_refresh_blocks_next_origination(): void
    {
        $this->writeInput([]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertFalse($decoded['next_origination_allowed']);
        $this->assertNotEmpty($decoded['missing_refreshes']);
    }

    public function test_fresh_queue_reality_refresh_allows_next_origination(): void
    {
        $this->writeInput([
            'queue_reality_refresh' => [
                'queue_health' => ['present' => true, 'age_seconds' => 10],
                'queued_targets' => ['present' => true, 'age_seconds' => 10],
                'code_index' => ['present' => true, 'age_seconds' => 10],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertTrue($decoded['next_origination_allowed']);
        $this->assertSame([], $decoded['missing_refreshes']);
    }
}
