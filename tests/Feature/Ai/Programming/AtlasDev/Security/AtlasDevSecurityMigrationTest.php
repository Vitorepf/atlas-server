<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Security;

use App\Services\Ai\Programming\AtlasDev\RunIndex\AtlasDevRunIndexRepository;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenResult;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasDevSecurityMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_dev_run_index');
        Schema::dropIfExists('atlas_dev_confirmation_tokens');

        require_once base_path('database/migrations/2026_05_16_010000_create_atlas_dev_security_tables.php');
        $migration = (require base_path('database/migrations/2026_05_16_010000_create_atlas_dev_security_tables.php'));
        $migration->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_dev_run_index');
        Schema::dropIfExists('atlas_dev_confirmation_tokens');

        parent::tearDown();
    }

    public function test_migration_creates_both_tables(): void
    {
        $this->assertTrue(Schema::hasTable('atlas_dev_confirmation_tokens'));
        $this->assertTrue(Schema::hasTable('atlas_dev_run_index'));

        $this->assertTrue(Schema::hasColumns('atlas_dev_confirmation_tokens', [
            'id', 'run_id', 'token_hash', 'surface_id', 'task_contract_hash',
            'compact_sdd_hash',
            'issued_at', 'expires_at', 'used_at', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('atlas_dev_run_index', [
            'run_id', 'surface_id', 'workspace_hash', 'thread_id',
            'routing_decision', 'task_kind', 'risk_level',
            'completion_state', 'last_receipt_hash', 'created_at', 'updated_at',
        ]));
    }

    public function test_end_to_end_token_and_run_index_flow(): void
    {
        $tokens = new ConfirmationTokenService;
        $repo = new AtlasDevRunIndexRepository;

        $repo->upsertFromPlan('run-feat-001', 'cli', 'ws-feat', 'fast_path', 'patch', 'R2');
        $issue = $tokens->issue('run-feat-001', 'tc-feat', 'cli');

        $consumed = $tokens->validateAndConsume('run-feat-001', 'tc-feat', $issue->plaintext);
        $this->assertTrue($consumed->ok);

        $repo->updateCompletion('run-feat-001', 'completed', 'receipt-feat-hash');

        $entry = $repo->find('run-feat-001');
        $this->assertNotNull($entry);
        $this->assertSame('completed', $entry->completionState);
        $this->assertSame('receipt-feat-hash', $entry->lastReceiptHash);

        $replay = $tokens->validateAndConsume('run-feat-001', 'tc-feat', $issue->plaintext);
        $this->assertFalse($replay->ok);
        $this->assertSame(ConfirmationTokenResult::REASON_ALREADY_USED, $replay->reason);
    }
}
