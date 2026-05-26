<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cartography;

use App\Services\Ai\Cartography\CartographyTruthGuardService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CartographyTruthGuardServiceTest extends TestCase
{
    private string $tmpDocsRoot;

    private string $sweepLog;

    private string $kernelLog;

    private CartographyTruthGuardService $svc;

    private AtlasConstitutionalKernelService $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->tmpDocsRoot = sys_get_temp_dir()."/atlas_carto_docs_{$u}";
        @mkdir($this->tmpDocsRoot, 0775, true);
        $this->sweepLog = sys_get_temp_dir()."/atlas_carto_sweep_{$u}.jsonl";
        $this->kernelLog = sys_get_temp_dir()."/atlas_carto_kernel_{$u}.jsonl";

        $this->kernel = new AtlasConstitutionalKernelService;
        $this->kernel->setViolationsLogPathForTesting($this->kernelLog);

        $this->svc = new CartographyTruthGuardService($this->kernel, new CanonicalDocsFrontmatterParser);
        $this->svc->setDocsRootForTesting($this->tmpDocsRoot);
        $this->svc->setLogPathForTesting($this->sweepLog);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDocsRoot)) {
            foreach (File::allFiles($this->tmpDocsRoot) as $f) {
                @unlink($f->getPathname());
            }
            @rmdir($this->tmpDocsRoot);
        }
        @unlink($this->sweepLog);
        @unlink($this->kernelLog);
        parent::tearDown();
    }

    private function writeDoc(string $slug, string $extraBody = ''): void
    {
        $md = "---\nid: {$slug}\ntype: engineering_knowledge\ndoc_schema: atlas_canonical_module_doc.v1\ntitle: Test {$slug}\nstatus: building\n---\n\n# Body for {$slug}\n{$extraBody}";
        file_put_contents($this->tmpDocsRoot.DIRECTORY_SEPARATOR.$slug.'.md', $md);
    }

    public function test_sweep_returns_kb_table_missing_in_test_env_with_docs(): void
    {
        $this->writeDoc('atlas-test-doc');
        $env = $this->svc->sweep('test_actor');
        // SQLite test env has no atlas_engineering_knowledge_items table by default.
        // The sweep MUST degrade honestly to kb_table_missing.
        $this->assertSame(
            CartographyTruthGuardService::STATUS_KB_TABLE_MISSING,
            $env['status']
        );
        $this->assertSame(1, $env['canonical_doc_count']);
        $this->assertSame(0, $env['kb_item_count']);
    }

    public function test_sweep_returns_docs_root_missing_when_directory_absent(): void
    {
        $this->svc->setDocsRootForTesting('/path/that/does/not/exist/xyz');
        $env = $this->svc->sweep('test_actor');
        $this->assertSame(
            CartographyTruthGuardService::STATUS_DOCS_ROOT_MISSING,
            $env['status']
        );
        $this->assertSame(0, $env['canonical_doc_count']);
    }

    public function test_envelope_carries_canonical_schema_and_hash(): void
    {
        $this->writeDoc('atlas-sample');
        $env = $this->svc->sweep('test_actor');
        $this->assertSame(CartographyTruthGuardService::SWEEP_SCHEMA, $env['schema_version']);
        $this->assertStringStartsWith('sha256:', $env['sweep_hash']);
        $this->assertArrayHasKey('started_at', $env);
        $this->assertArrayHasKey('drifts', $env);
    }

    public function test_canonical_index_returns_slug_and_hash_per_doc(): void
    {
        $this->writeDoc('atlas-alpha');
        $this->writeDoc('atlas-beta');
        $idx = $this->svc->canonicalIndex();
        $this->assertArrayHasKey('atlas-alpha', $idx);
        $this->assertArrayHasKey('atlas-beta', $idx);
        // sha256 hex digest length === 64
        $this->assertSame(64, strlen($idx['atlas-alpha']['content_hash']));
        $this->assertSame(64, strlen($idx['atlas-beta']['content_hash']));
    }

    public function test_sweep_persists_append_only(): void
    {
        $this->writeDoc('atlas-a');
        $this->svc->sweep('test_actor');
        $this->svc->sweep('test_actor');
        $this->assertCount(2, $this->svc->listSweeps());
    }

    public function test_last_sweep_returns_most_recent(): void
    {
        $this->writeDoc('atlas-a');
        $this->svc->sweep('actor_one');
        $second = $this->svc->sweep('actor_two');
        $last = $this->svc->lastSweep();
        $this->assertSame('actor_two', $last['actor']);
        $this->assertSame($second['sweep_hash'], $last['sweep_hash']);
    }

    public function test_last_sweep_null_when_log_empty(): void
    {
        $this->assertNull($this->svc->lastSweep());
    }

    public function test_drift_kinds_constants_match_canon(): void
    {
        $this->assertSame('orphan_in_kb', CartographyTruthGuardService::DRIFT_ORPHAN_IN_KB);
        $this->assertSame('missing_in_kb', CartographyTruthGuardService::DRIFT_MISSING_IN_KB);
        $this->assertSame('content_drift', CartographyTruthGuardService::DRIFT_CONTENT_DRIFT);
    }

    public function test_sweep_status_constants_canon(): void
    {
        $this->assertSame('truthful', CartographyTruthGuardService::STATUS_TRUTHFUL);
        $this->assertSame('drift_detected', CartographyTruthGuardService::STATUS_DRIFT);
        $this->assertSame('kb_table_missing', CartographyTruthGuardService::STATUS_KB_TABLE_MISSING);
        $this->assertSame('docs_root_missing', CartographyTruthGuardService::STATUS_DOCS_ROOT_MISSING);
    }
}
