<?php

namespace Tests\Feature\ProgrammingGovernance;

use App\Models\AtlasEngineeringEvidence;
use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\Governance\ProgrammingEvidenceLedger;
use RuntimeException;
use Tests\Concerns\CreatesAtlasProgrammingGovernanceTables;
use Tests\TestCase;

/**
 * Locks down the hardening added in Etapa 2:
 *   - file_hashes computed from disk
 *   - output_hash computed from receipt['output']
 *   - diff_path read, hashed, excerpted, with byte count
 *   - parent_receipt_id must reference an existing receipt
 *   - non-existent files refused at the ledger boundary
 */
class EvidenceHardeningTest extends TestCase
{
    use CreatesAtlasProgrammingGovernanceTables;

    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasProgrammingGovernanceTables();
        $this->workspace = $this->makeProgrammingWorkspace([
            'app/Foo.php',
            'app/Bar.php',
            'tmp/diff.patch',
        ]);
        // Seed a non-default content so hashes are deterministic but distinct.
        file_put_contents($this->workspace.'/app/Foo.php', "<?php // Foo body\n");
        file_put_contents($this->workspace.'/app/Bar.php', "<?php // Bar body different\n");
        file_put_contents($this->workspace.'/tmp/diff.patch', "--- a/app/Foo.php\n+++ b/app/Foo.php\n@@\n+new line\n");
    }

    protected function tearDown(): void
    {
        $this->cleanupProgrammingWorkspaces();
        $this->dropAtlasProgrammingGovernanceTables();
        parent::tearDown();
    }

    public function test_file_hashes_are_computed_per_file(): void
    {
        $ledger = new ProgrammingEvidenceLedger();
        $item = $this->makeItem();

        $receipt = $ledger->record($item, [
            'command' => 'phpunit',
            'output' => 'OK',
            'files' => ['app/Foo.php', 'app/Bar.php'],
        ]);

        $this->assertCount(2, $receipt['file_hashes']);
        $hashes = collect($receipt['file_hashes'])->pluck('sha256')->all();
        $this->assertCount(2, array_unique($hashes), 'distinct file contents must yield distinct hashes');
        $this->assertSame(64, strlen($hashes[0]));
    }

    public function test_output_hash_is_sha256_of_output_text(): void
    {
        $ledger = new ProgrammingEvidenceLedger();
        $item = $this->makeItem();

        $receipt = $ledger->record($item, [
            'command' => 'phpunit',
            'output' => 'OK (42 tests)',
            'files' => ['app/Foo.php'],
        ]);

        $this->assertSame(hash('sha256', 'OK (42 tests)'), $receipt['output_hash']);
    }

    public function test_missing_file_is_rejected(): void
    {
        $ledger = new ProgrammingEvidenceLedger();
        $item = $this->makeItem();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('evidence_files_not_found:app/DoesNotExist.php');

        $ledger->record($item, [
            'command' => 'phpunit',
            'output' => 'OK',
            'files' => ['app/Foo.php', 'app/DoesNotExist.php'],
        ]);

        // Nothing should land in the ledger when the receipt is rejected.
        $this->assertSame(0, AtlasEngineeringEvidence::query()->count());
    }

    public function test_diff_path_is_hashed_and_excerpted(): void
    {
        $ledger = new ProgrammingEvidenceLedger();
        $item = $this->makeItem();

        $receipt = $ledger->record($item, [
            'command' => 'phpunit',
            'output' => 'OK',
            'files' => ['app/Foo.php'],
            'diff_path' => 'tmp/diff.patch',
        ]);

        $diffContents = file_get_contents($this->workspace.'/tmp/diff.patch');
        $this->assertSame(hash('sha256', $diffContents), $receipt['diff_hash']);
        $this->assertSame(strlen($diffContents), $receipt['diff_bytes']);
        $this->assertStringContainsString('+new line', $receipt['diff_excerpt']);
    }

    public function test_missing_diff_path_is_rejected(): void
    {
        $ledger = new ProgrammingEvidenceLedger();
        $item = $this->makeItem();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('evidence_diff_path_not_found:tmp/missing.patch');

        $ledger->record($item, [
            'command' => 'phpunit',
            'output' => 'OK',
            'files' => ['app/Foo.php'],
            'diff_path' => 'tmp/missing.patch',
        ]);
    }

    public function test_parent_receipt_id_must_reference_existing_receipt(): void
    {
        $ledger = new ProgrammingEvidenceLedger();
        $item = $this->makeItem();

        $first = $ledger->record($item, [
            'command' => 'phpunit',
            'output' => 'OK first',
            'files' => ['app/Foo.php'],
        ]);
        $item->forceFill(['evidence_refs_json' => [$first]])->save();
        $item = $item->refresh();

        // Linking to an existing receipt id is allowed.
        $second = $ledger->record($item, [
            'command' => 'phpunit',
            'output' => 'OK second',
            'files' => ['app/Bar.php'],
            'parent_receipt_id' => $first['receipt_id'],
        ]);
        $this->assertSame($first['receipt_id'], $second['parent_receipt_id']);

        // Linking to an unknown id is rejected.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('evidence_parent_receipt_not_found:bogus-receipt-id');

        $ledger->record($item, [
            'command' => 'phpunit',
            'output' => 'OK third',
            'files' => ['app/Foo.php'],
            'parent_receipt_id' => 'bogus-receipt-id',
        ]);
    }

    public function test_metadata_includes_full_hardening_payload(): void
    {
        $ledger = new ProgrammingEvidenceLedger();
        $item = $this->makeItem();

        $receipt = $ledger->record($item, [
            'command' => 'phpunit',
            'output' => 'OK',
            'files' => ['app/Foo.php'],
            'diff_path' => 'tmp/diff.patch',
        ]);

        $row = AtlasEngineeringEvidence::query()->firstOrFail();
        $this->assertSame($receipt['receipt_id'], $row->metadata['receipt_id']);
        $this->assertSame($receipt['file_hashes'], $row->metadata['file_hashes']);
        $this->assertSame($receipt['output_hash'], $row->metadata['output_hash']);
        $this->assertSame($receipt['diff_hash'], $row->metadata['diff_hash']);
        $this->assertSame($receipt['diff_bytes'], $row->metadata['diff_bytes']);
    }

    private function makeItem(): AtlasProgrammingWorkItem
    {
        return AtlasProgrammingWorkItem::query()->create([
            'code' => 'EH-'.bin2hex(random_bytes(4)),
            'intent_text' => 'evidence hardening test',
            'intent_type' => 'feature',
            'scope_mode' => 'structural',
            'risk_level' => 'medium',
            'workspace' => $this->workspace,
            'status' => 'executing',
            'current_stage' => 'execution',
            'placement_json' => [],
            'code_intelligence_json' => [],
            'spec_json' => [],
            'plan_json' => [],
            'tasks_json' => [],
            'evidence_refs_json' => [],
            'gaps_json' => [],
            'metadata_json' => [],
        ]);
    }
}
