<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasSelfConstructionReceiptsCommand;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasSelfConstructionReceiptsCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function fixture(array $payload): string
    {
        $path = sys_get_temp_dir().'/atlas-receipts-facts-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, (string) json_encode($payload));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:self-construction:receipts', $args);

        return [$exit, $kernel->output()];
    }

    public function test_index_groups_receipts_by_class(): void
    {
        $path = $this->fixture(['receipts' => [
            ['class' => 'scope_gap'],
            ['class' => 'scope_gap'],
            ['class' => 'missing_dependency'],
        ]]);
        [$exit, $out] = $this->runCmd(['action' => 'index', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionReceiptsCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame(3, $decoded['total']);
    }

    public function test_bind_unverified_worker_claim_is_refused(): void
    {
        $path = $this->fixture(['task_id' => 't1', 'lease_id' => 'l1', 'receipt_id' => 'r1', 'verified' => false]);
        [$exit, $out] = $this->runCmd(['action' => 'bind', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionReceiptsCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertFalse($decoded['bound']);
        $this->assertSame('unverified_worker_claim', $decoded['reason']);
    }

    public function test_bind_verified_keys_succeed(): void
    {
        $path = $this->fixture(['task_id' => 't1', 'lease_id' => 'l1', 'receipt_id' => 'r1', 'verified' => true]);
        [$exit, $out] = $this->runCmd(['action' => 'bind', '--facts' => $path, '--json' => true]);
        $decoded = json_decode(trim($out), true);
        $this->assertTrue($decoded['bound']);
    }

    public function test_reality_reports_completion_real_only_with_evidence_and_outcome_match(): void
    {
        $path = $this->fixture([
            'claimed_outcome' => 'success',
            'verified_outcome' => 'success',
            'verified_evidence' => ['phpunit:t1'],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'reality', '--facts' => $path, '--json' => true]);
        $decoded = json_decode(trim($out), true);
        $this->assertTrue($decoded['completion_real']);
    }

    public function test_reality_marks_unreal_when_no_evidence(): void
    {
        $path = $this->fixture([
            'claimed_outcome' => 'success',
            'verified_outcome' => 'success',
            'verified_evidence' => [],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'reality', '--facts' => $path, '--json' => true]);
        $decoded = json_decode(trim($out), true);
        $this->assertFalse($decoded['completion_real']);
    }

    public function test_export_plan_truncates_and_lists_kinds(): void
    {
        $path = $this->fixture([
            'receipts' => [
                ['kind' => 'failure'],
                ['kind' => 'failure'],
                ['kind' => 'success'],
            ],
            'max_items' => 2,
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'export-plan', '--facts' => $path, '--json' => true]);
        $decoded = json_decode(trim($out), true);
        $this->assertSame(2, $decoded['planned_export_count']);
        $this->assertTrue($decoded['truncated']);
        $this->assertSame(['failure'], $decoded['kinds']);
        $this->assertSame('export_plan_only_never_writes_memory', $decoded['note']);
    }

    public function test_invalid_facts_path_fails_closed(): void
    {
        [$exit] = $this->runCmd(['action' => 'index']);
        $this->assertSame(AtlasSelfConstructionReceiptsCommand::EXIT_USAGE, $exit);
    }

    public function test_invalid_json_facts_fails_closed(): void
    {
        $path = sys_get_temp_dir().'/atlas-receipts-bad-'.bin2hex(random_bytes(3)).'.json';
        file_put_contents($path, '{not-json,');
        $this->tempFiles[] = $path;

        [$exit] = $this->runCmd(['action' => 'index', '--facts' => $path]);
        $this->assertSame(AtlasSelfConstructionReceiptsCommand::EXIT_USAGE, $exit);
    }

    public function test_unknown_action_fails_closed(): void
    {
        $path = $this->fixture([]);
        [$exit] = $this->runCmd(['action' => 'BOGUS', '--facts' => $path]);
        $this->assertSame(AtlasSelfConstructionReceiptsCommand::EXIT_USAGE, $exit);
    }

    public function test_missing_facts_with_json_flag_emits_usage_error_envelope(): void
    {
        [$exit, $out] = $this->runCmd(['action' => 'reality', '--json' => true]);

        $this->assertSame(AtlasSelfConstructionReceiptsCommand::EXIT_USAGE, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertIsArray($decoded, 'output must be valid JSON when --json is set');
        $this->assertSame('usage_error', $decoded['status']);
        $this->assertNotEmpty($decoded['reason']);
    }
}
