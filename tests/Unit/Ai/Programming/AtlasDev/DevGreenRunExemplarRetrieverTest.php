<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Discovery\DevGreenRunExemplarRetriever;
use PHPUnit\Framework\TestCase;

final class DevGreenRunExemplarRetrieverTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $this->rrmdir($dir);
        }
        parent::tearDown();
    }

    private function tempStore(): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/').'/atlas-dev-receipts-'.bin2hex(random_bytes(5));
        mkdir($dir, 0777, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** Writes a fixture verification_receipt.json mirroring the real orchestrator's persisted shape. */
    private function writeReceipt(string $store, string $runId, array $overrides = []): void
    {
        $runDir = $store.'/'.$runId;
        mkdir($runDir, 0777, true);

        $receipt = array_merge([
            'run_id' => $runId,
            'task_kind' => 'patch',
            'design_path' => 'safe_refactor',
            'task_contract_hash' => hash('sha256', $runId.'-contract'),
            'changed_files' => ['app/Services/Foo/Bar.php'],
            'tests' => [
                ['command' => 'php artisan test --filter=BarTest', 'ok' => true],
            ],
            'completion' => ['status' => 'passed'],
        ], $overrides);

        file_put_contents($runDir.'/verification_receipt.json', json_encode($receipt, JSON_UNESCAPED_SLASHES));
    }

    public function test_green_runs_matching_task_kind_and_files_rank_above_unrelated(): void
    {
        $store = $this->tempStore();
        $this->writeReceipt($store, 'run-matching', [
            'task_kind' => 'patch',
            'changed_files' => ['app/Services/Foo/Bar.php'],
        ]);
        $this->writeReceipt($store, 'run-unrelated', [
            'task_kind' => 'question',
            'changed_files' => ['docs/README.md'],
        ]);

        $retriever = new DevGreenRunExemplarRetriever($store);
        $result = $retriever->retrieve('patch', 'safe_refactor', ['app/Services/Foo/Bar.php'], 5);

        $this->assertCount(2, $result);
        $this->assertSame('run-matching', $result[0]['run_id']);
        $this->assertSame('run-unrelated', $result[1]['run_id']);
    }

    public function test_failed_runs_are_excluded(): void
    {
        $store = $this->tempStore();
        $this->writeReceipt($store, 'run-failed', ['completion' => ['status' => 'failed']]);
        $this->writeReceipt($store, 'run-passed', ['completion' => ['status' => 'passed']]);

        $retriever = new DevGreenRunExemplarRetriever($store);
        $result = $retriever->retrieve('patch', 'safe_refactor', [], 5);

        $runIds = array_column($result, 'run_id');
        $this->assertNotContains('run-failed', $runIds);
        $this->assertContains('run-passed', $runIds);
    }

    public function test_returned_exemplars_carry_zero_raw_prompt_or_provider_payload_fields(): void
    {
        $store = $this->tempStore();
        $this->writeReceipt($store, 'run-safe', [
            'prompt' => 'this is a raw prompt that must never leak',
            'provider_call_payload' => ['secret' => 'nope'],
        ]);

        $retriever = new DevGreenRunExemplarRetriever($store);
        $result = $retriever->retrieve('patch', 'safe_refactor', [], 5);

        $this->assertCount(1, $result);
        $exemplar = $result[0];
        $this->assertSame(
            ['run_id', 'objective_digest', 'design_path', 'files_touched', 'verification_command', 'outcome'],
            array_keys($exemplar),
        );
        $serialized = json_encode($exemplar);
        $this->assertStringNotContainsString('raw prompt', (string) $serialized);
        $this->assertStringNotContainsString('secret', (string) $serialized);
    }

    public function test_empty_store_returns_empty_list(): void
    {
        $store = $this->tempStore();

        $retriever = new DevGreenRunExemplarRetriever($store);
        $result = $retriever->retrieve('patch', 'safe_refactor', ['app/Foo.php'], 3);

        $this->assertSame([], $result);
    }

    public function test_unreadable_store_fails_open_to_empty_list(): void
    {
        $retriever = new DevGreenRunExemplarRetriever('/definitely/does/not/exist/atlas-dev-receipts');

        $result = $retriever->retrieve('patch', 'safe_refactor', [], 3);

        $this->assertSame([], $result);
    }

    public function test_limit_caps_the_returned_exemplar_count(): void
    {
        $store = $this->tempStore();
        for ($i = 1; $i <= 5; $i++) {
            $this->writeReceipt($store, "run-{$i}");
        }

        $retriever = new DevGreenRunExemplarRetriever($store);
        $result = $retriever->retrieve('patch', 'safe_refactor', ['app/Services/Foo/Bar.php'], 2);

        $this->assertCount(2, $result);
    }

    public function test_verification_command_is_carried_from_the_receipt(): void
    {
        $store = $this->tempStore();
        $this->writeReceipt($store, 'run-cmd', [
            'tests' => [['command' => 'php artisan test --filter=SpecificTest', 'ok' => true]],
        ]);

        $retriever = new DevGreenRunExemplarRetriever($store);
        $result = $retriever->retrieve('patch', 'safe_refactor', [], 5);

        $this->assertSame('php artisan test --filter=SpecificTest', $result[0]['verification_command']);
    }
}
