<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Discovery\DevGreenRunExemplarRetriever;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\TestCase;

final class DevGreenRunExemplarRetrieverTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
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
            ['run_id', 'objective_digest', 'objective_excerpt', 'design_path', 'files_touched', 'verification_command', 'outcome'],
            array_keys($exemplar),
        );
        $serialized = json_encode($exemplar);
        $this->assertStringNotContainsString('raw prompt', (string) $serialized);
        $this->assertStringNotContainsString('secret', (string) $serialized);
    }

    public function test_objective_excerpt_is_read_from_sibling_mini_spec(): void
    {
        $store = $this->tempStore();
        $this->writeReceipt($store, 'run-goal');
        file_put_contents(
            $store.'/run-goal/mini_programming_spec.json',
            json_encode(['goal' => 'Corrigir o parser de diff para hunks multi-arquivo']),
        );

        $out = (new DevGreenRunExemplarRetriever($store))->retrieve('patch', 'safe_refactor', [], 1);

        $this->assertSame(
            'Corrigir o parser de diff para hunks multi-arquivo',
            $out[0]['objective_excerpt'],
            'exemplar must carry the human-readable goal — an opaque hash teaches a model nothing',
        );
    }

    public function test_objective_excerpt_is_truncated_and_fails_open_when_spec_missing(): void
    {
        $store = $this->tempStore();
        $this->writeReceipt($store, 'run-long');
        file_put_contents(
            $store.'/run-long/mini_programming_spec.json',
            json_encode(['goal' => str_repeat('a', 400)]),
        );
        $this->writeReceipt($store, 'run-nospec');

        $out = (new DevGreenRunExemplarRetriever($store))->retrieve('patch', 'safe_refactor', [], 5);
        $byRun = [];
        foreach ($out as $exemplar) {
            $byRun[$exemplar['run_id']] = $exemplar;
        }

        $this->assertSame(160, mb_strlen($byRun['run-long']['objective_excerpt']));
        $this->assertStringEndsWith('...', $byRun['run-long']['objective_excerpt']);
        $this->assertSame('', $byRun['run-nospec']['objective_excerpt'], 'missing spec fails open to empty excerpt');
    }

    public function test_workspace_hash_filter_excludes_foreign_and_unattributable_receipts(): void
    {
        $store = $this->tempStore();
        $this->writeReceipt($store, 'run-mine', ['workspace_hash' => hash('sha256', '/ws/mine')]);
        $this->writeReceipt($store, 'run-foreign', ['workspace_hash' => hash('sha256', '/ws/other')]);
        $this->writeReceipt($store, 'run-unattributed'); // no workspace_hash at all

        $out = (new DevGreenRunExemplarRetriever($store))->retrieve(
            'patch', 'safe_refactor', [], 5,
            workspaceHash: hash('sha256', '/ws/mine'),
        );

        $this->assertSame(
            ['run-mine'],
            array_column($out, 'run_id'),
            'a foreign or unattributable receipt must never ride into this workspace prompt (anti cross-repo bleed)',
        );

        // Null caller hash keeps legacy unfiltered behavior.
        $all = (new DevGreenRunExemplarRetriever($store))->retrieve('patch', 'safe_refactor', [], 5);
        $this->assertCount(3, $all);
    }

    public function test_origin_hash_matches_receipts_from_other_checkouts_of_the_same_repo(): void
    {
        // Sandboxed flows run in per-run temp dirs: workspace_hash NEVER
        // repeats (audited: 73 green receipts, 73 distinct hashes). The
        // sibling workspace_origin.json carries the stable repo identity.
        $store = $this->tempStore();
        $originHash = hash('sha256', 'https://example.test/atlas-server.git');

        $this->writeReceipt($store, 'run-same-repo', ['workspace_hash' => hash('sha256', '/tmp/sandbox-a')]);
        file_put_contents(
            $store.'/run-same-repo/workspace_origin.json',
            json_encode(['schema' => 'atlas.dev.workspace_origin.v1', 'origin_hash' => $originHash]),
        );
        $this->writeReceipt($store, 'run-other-repo', ['workspace_hash' => hash('sha256', '/tmp/sandbox-b')]);
        file_put_contents(
            $store.'/run-other-repo/workspace_origin.json',
            json_encode(['schema' => 'atlas.dev.workspace_origin.v1', 'origin_hash' => hash('sha256', 'other-repo')]),
        );
        $this->writeReceipt($store, 'run-legacy-no-origin', ['workspace_hash' => hash('sha256', '/tmp/sandbox-c')]);

        $out = (new DevGreenRunExemplarRetriever($store))->retrieve(
            'patch', 'safe_refactor', [], 5,
            workspaceHash: hash('sha256', '/tmp/current-sandbox'),
            originHash: $originHash,
        );

        $this->assertSame(
            ['run-same-repo'],
            array_column($out, 'run_id'),
            'same-repo receipts must match via origin even when checkout paths differ; foreign/legacy excluded',
        );
    }

    public function test_scan_cap_examines_only_the_newest_run_dirs(): void
    {
        // dev-<ms>-<rand> ids sort chronologically; the cap must keep the
        // NEWEST dirs (an old green run beyond the cap is not scanned).
        $store = $this->tempStore();
        $this->writeReceipt($store, 'dev-1000-old');
        $this->writeReceipt($store, 'dev-2000-mid');
        $this->writeReceipt($store, 'dev-3000-new');

        $out = (new DevGreenRunExemplarRetriever($store, scanCap: 2))->retrieve('patch', 'safe_refactor', [], 5);

        $runIds = array_column($out, 'run_id');
        $this->assertContains('dev-3000-new', $runIds);
        $this->assertContains('dev-2000-mid', $runIds);
        $this->assertNotContains('dev-1000-old', $runIds, 'dirs beyond the newest-N cap must not be scanned');
    }

    public function test_index_backfill_makes_runs_beyond_the_scan_cap_retrievable(): void
    {
        // Same store as the scan-cap test, but after indexAll() the OLD green
        // run is served from the index without being re-scanned — history
        // stays retrievable while per-call disk reads stay capped.
        $store = $this->tempStore();
        $this->writeReceipt($store, 'dev-1000-old');
        $this->writeReceipt($store, 'dev-2000-mid');
        $this->writeReceipt($store, 'dev-3000-new');

        $summary = (new DevGreenRunExemplarRetriever($store))->indexAll();
        $this->assertSame(3, $summary['indexed_green']);

        $out = (new DevGreenRunExemplarRetriever($store, scanCap: 2))->retrieve('patch', 'safe_refactor', [], 5);
        $this->assertContains('dev-1000-old', array_column($out, 'run_id'), 'indexed history is retrievable beyond the scan cap');
    }

    public function test_retrieval_lazily_indexes_scanned_runs_and_tombstones_failures(): void
    {
        $store = $this->tempStore();
        $this->writeReceipt($store, 'dev-1000-green');
        $this->writeReceipt($store, 'dev-2000-red', ['completion' => ['status' => 'failed']]);

        (new DevGreenRunExemplarRetriever($store))->retrieve('patch', 'safe_refactor', [], 5);

        $index = file_get_contents($store.'/exemplar_index.jsonl');
        $this->assertStringContainsString('dev-1000-green', $index);
        $this->assertStringContainsString('dev-2000-red', $index, 'a failed run is tombstoned so it is never re-read');

        // A second retrieval must serve the green run from the index (still
        // returned) and keep excluding the tombstoned failure.
        $out = (new DevGreenRunExemplarRetriever($store))->retrieve('patch', 'safe_refactor', [], 5);
        $runIds = array_column($out, 'run_id');
        $this->assertContains('dev-1000-green', $runIds);
        $this->assertNotContains('dev-2000-red', $runIds);
    }

    public function test_workspace_identity_filter_applies_to_indexed_rows(): void
    {
        $store = $this->tempStore();
        $this->writeReceipt($store, 'dev-1000-foreign', ['workspace_hash' => hash('sha256', 'foreign-ws')]);
        (new DevGreenRunExemplarRetriever($store))->indexAll();

        $out = (new DevGreenRunExemplarRetriever($store))->retrieve(
            'patch', 'safe_refactor', [], 5,
            workspaceHash: hash('sha256', 'my-ws'),
        );

        $this->assertSame([], $out, 'anti-bleed must hold for index-served rows too');
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
