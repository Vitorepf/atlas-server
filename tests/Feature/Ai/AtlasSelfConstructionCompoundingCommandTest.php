<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasSelfConstructionCompoundingCommand;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasSelfConstructionCompoundingCommandTest extends TestCase
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
        $path = sys_get_temp_dir().'/atlas-compounding-facts-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, (string) json_encode($payload));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:self-construction:compounding', $args);

        return [$exit, $kernel->output()];
    }

    public function test_outcomes_action_projects_records_and_carries_runtime_owner(): void
    {
        $path = $this->fixture([
            'records' => [['organ' => 'cortex', 'task_class' => 'recall', 'cycle_id' => 'c-1', 'evidence_hash' => 'h1', 'outcome' => 'success']],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'outcomes', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionCompoundingCommand::EXIT_OK, $exit);

        $decoded = json_decode(trim($out), true);
        $this->assertSame('atlas_native', $decoded['final_runtime_owner']);
        $this->assertCount(1, $decoded['groups']);
    }

    public function test_velocity_action_tracks_cycle_facts(): void
    {
        $path = $this->fixture([
            'cycle_facts' => [
                ['cycle_id' => 'c-1', 'passed_count' => 1],
                ['cycle_id' => 'c-2', 'passed_count' => 3],
            ],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'velocity', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionCompoundingCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame('improving', $decoded['rows'][1]['throughput_trend']);
    }

    public function test_leverage_action_reports_real_leverage(): void
    {
        $path = $this->fixture([
            'before' => ['capability_coverage' => 5, 'verification_strength' => 10, 'task_waste' => 3],
            'after' => ['capability_coverage' => 8, 'verification_strength' => 10, 'task_waste' => 3],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'leverage', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionCompoundingCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertTrue($decoded['real_leverage']);
        $this->assertSame('atlas_native', $decoded['final_runtime_owner']);
    }

    public function test_frontier_action_proposes_blocker_removal_first(): void
    {
        $path = $this->fixture([
            'leverage_delta' => ['deltas' => ['capability_coverage' => 2]],
            'unresolved_blockers' => [['organ' => 'verification_court', 'blocker_id' => 'b1']],
            'missing_organ_coverage' => ['cortex'],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'frontier', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionCompoundingCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame('blocker_removal_high_leverage', $decoded['frontier'][0]['kind']);
    }

    public function test_empty_facts_payload_still_returns_ok_with_empty_payload(): void
    {
        $path = $this->fixture([]);
        [$exit, $out] = $this->runCmd(['action' => 'outcomes', '--facts' => $path, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionCompoundingCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame([], $decoded['groups']);
    }

    public function test_missing_facts_path_fails_closed(): void
    {
        [$exit] = $this->runCmd(['action' => 'velocity']);
        $this->assertSame(AtlasSelfConstructionCompoundingCommand::EXIT_USAGE, $exit);
    }

    public function test_malformed_json_facts_fails_closed(): void
    {
        $path = sys_get_temp_dir().'/atlas-cp-bad-'.bin2hex(random_bytes(3)).'.json';
        file_put_contents($path, '{not-json,');
        $this->tempFiles[] = $path;
        [$exit] = $this->runCmd(['action' => 'velocity', '--facts' => $path]);
        $this->assertSame(AtlasSelfConstructionCompoundingCommand::EXIT_USAGE, $exit);
    }

    public function test_unknown_action_fails_closed(): void
    {
        $path = $this->fixture([]);
        [$exit] = $this->runCmd(['action' => 'BOGUS', '--facts' => $path]);
        $this->assertSame(AtlasSelfConstructionCompoundingCommand::EXIT_USAGE, $exit);
    }

    public function test_cli_source_makes_no_provider_or_runtime_calls(): void
    {
        $src = (string) file_get_contents(base_path('app/Console/Commands/AtlasSelfConstructionCompoundingCommand.php'));
        foreach (['shell_exec', 'system(', 'exec(', 'proc_open', 'Process::run', 'curl_', 'git ', 'Http::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "CLI source must NOT contain {$forbidden}");
        }
    }

    public function test_missing_facts_with_json_flag_emits_usage_error_envelope(): void
    {
        [$exit, $out] = $this->runCmd(['action' => 'outcomes', '--json' => true]);

        $this->assertSame(AtlasSelfConstructionCompoundingCommand::EXIT_USAGE, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertIsArray($decoded, 'output must be valid JSON when --json is set');
        $this->assertSame('usage_error', $decoded['status']);
        $this->assertNotEmpty($decoded['reason']);
    }
}
