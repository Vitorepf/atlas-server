<?php

namespace Tests\Feature\Sdd;

use App\Models\AtlasDecisionReceipt;
use App\Models\AtlasOperation;
use App\Models\AtlasPlan;
use App\Models\AtlasSpec;
use App\Services\Ai\Programming\Sdd\DecisionEngine;
use App\Services\Ai\Programming\Sdd\Enums\ConfidenceClass;
use App\Services\Ai\Programming\Sdd\Pipeline\ContextPack;
use App\Services\Ai\Programming\Sdd\Pipeline\Intent;
use App\Services\Ai\Programming\Sdd\Pipeline\SddPipelineOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\Sdd\RepairLoop;
use App\Services\Ai\Programming\Sdd\RuntimeExecutor;
use Tests\Concerns\CreatesAtlasSddTables;
use Tests\TestCase;

class RuntimeExecutorAndRepairLoopTest extends TestCase
{
    use CreatesAtlasSddTables;

    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasSddTables();
        $this->workspace = sys_get_temp_dir().'/sdd_exec_'.bin2hex(random_bytes(4));
        mkdir($this->workspace.'/app', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workspace);
        $this->dropAtlasSddTables();
        parent::tearDown();
    }

    public function test_executor_writes_allowed_file_and_records_evidence(): void
    {
        $receipt = $this->makeReceipt();
        $exec = new RuntimeExecutor(new DecisionEngine);

        $result = $exec->execute(
            receipt: $receipt,
            proposedWrites: [['path' => 'app/Foo.php', 'contents' => "<?php // generated\n"]],
            proposedCommands: [],
            evidenceRefs: ['ledger:abc'],
            workspace: $this->workspace,
        );

        $this->assertSame('ok', $result->status);
        $this->assertSame(['app/Foo.php'], $result->writtenFiles);
        $this->assertFileExists($this->workspace.'/app/Foo.php');
        $this->assertSame(64, strlen($result->outputHash));
    }

    public function test_executor_rejects_out_of_scope_write(): void
    {
        $receipt = $this->makeReceipt();
        $exec = new RuntimeExecutor(new DecisionEngine);

        $result = $exec->execute(
            receipt: $receipt,
            proposedWrites: [
                ['path' => 'app/Foo.php', 'contents' => 'allowed'],
                ['path' => 'app/Models/AtlasUser.php', 'contents' => 'forbidden'],
            ],
            workspace: $this->workspace,
        );

        $this->assertSame('partial', $result->status);
        $this->assertSame(['app/Foo.php'], $result->writtenFiles);
        $this->assertSame(['app/Models/AtlasUser.php'], $result->rejectedFiles);
        $this->assertFileDoesNotExist($this->workspace.'/app/Models/AtlasUser.php');
    }

    public function test_executor_rejects_inactive_receipt(): void
    {
        $receipt = $this->makeReceipt();
        (new DecisionEngine)->revoke($receipt, 'leaked');
        $exec = new RuntimeExecutor(new DecisionEngine);

        $result = $exec->execute(
            receipt: $receipt->refresh(),
            proposedWrites: [['path' => 'app/Foo.php', 'contents' => 'x']],
            workspace: $this->workspace,
        );

        $this->assertSame('rejected', $result->status);
        $this->assertSame([], $result->writtenFiles);
    }

    public function test_repair_loop_succeeds_when_command_eventually_passes(): void
    {
        $receipt = $this->makeReceipt();
        $engine = new DecisionEngine;
        $exec = new RuntimeExecutor($engine);
        $loop = new RepairLoop($exec);

        // Initial run fails the command.
        $first = $exec->execute(
            receipt: $receipt,
            proposedCommands: ['phpunit tests/Foo'],
            commandRunner: static fn (string $cmd): bool => false,
        );
        $this->assertSame('partial', $first->status);

        // Repair succeeds on attempt 1.
        $repaired = $loop->repair(
            receipt: $receipt,
            previous: $first,
            commandRunner: static fn (string $cmd): bool => true,
            maxAttempts: 1,
        );

        $this->assertTrue($repaired->ok());
        $this->assertContains('phpunit tests/Foo', $repaired->commandsExecuted);
    }

    public function test_repair_loop_does_nothing_when_already_ok(): void
    {
        $receipt = $this->makeReceipt();
        $exec = new RuntimeExecutor(new DecisionEngine);
        $loop = new RepairLoop($exec);

        $previous = $exec->execute(
            receipt: $receipt,
            proposedWrites: [['path' => 'app/Foo.php', 'contents' => 'x']],
            workspace: $this->workspace,
        );

        $repaired = $loop->repair($receipt, $previous);
        $this->assertSame($previous, $repaired, 'no-op when already ok');
    }

    private function makeReceipt(): AtlasDecisionReceipt
    {
        $envelope = new OperationEnvelope('Add', userId: 'vitor');
        $intent = new Intent('feature', 'programming', 'medium', ConfidenceClass::ConfirmedFact, true);
        $op = AtlasOperation::query()->create([
            'raw_input' => 'x', 'status' => 'routed', 'risk_level' => 'medium',
            'domain' => 'programming', 'routing_metadata_json' => [],
        ]);
        $spec = AtlasSpec::query()->create([
            'operation_id' => $op->id, 'title' => 't', 'type' => 'feature',
            'status' => 'approved', 'version' => 1, 'risk_level' => 'medium',
            'content_hash' => str_repeat('a', 64), 'content_json' => [],
        ]);
        $plan = AtlasPlan::query()->create([
            'spec_id' => $spec->id, 'content_hash' => str_repeat('b', 64),
            'content_json' => [], 'target_files_json' => ['app/Foo.php'],
            'forbidden_files_json' => ['app/Models/AtlasUser.php'],
            'hot_file_ownership_json' => ['app/Foo.php' => 'service'],
            'test_plan_json' => [], 'rollback_plan_json' => [], 'status' => 'draft',
        ]);
        $ctx = new ContextPack('kernel-programming', ['atlas.base.v1'], [], str_repeat('c', 64));

        return (new DecisionEngine)->createReceipt($envelope, $op, $spec, $plan, [
            ['code' => 'T-01-SERV', 'allowed_files' => ['app/Foo.php']],
        ], $ctx, $intent);
    }

    private function rmrf(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach (@scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->rmrf($path.'/'.$item);
        }
        @rmdir($path);
    }
}
