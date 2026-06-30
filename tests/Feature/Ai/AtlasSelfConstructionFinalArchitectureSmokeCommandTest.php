<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasSelfConstructionFinalArchitectureSmokeCommand;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasSelfConstructionFinalArchitectureSmokeCommandTest extends TestCase
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
        $path = sys_get_temp_dir().'/atlas-smoke-facts-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, (string) json_encode($payload));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:self-construction:final-smoke', $args);

        return [$exit, $kernel->output()];
    }

    private function happyFacts(): array
    {
        return [
            'cycle' => ['atlas_native' => true, 'evidence_refs' => ['r1', 'r2']],
            'coverage' => ['fully_covered' => true, 'missing_organ' => []],
            'stewardship' => ['refused' => false, 'project_id' => 'atlas-server', 'runtime_plan' => ['x' => 1]],
            'queue_repair' => ['atlas_native' => true, 'repaired_packets' => 3],
        ];
    }

    public function test_cycle_action_reports_readiness(): void
    {
        $path = $this->fixture($this->happyFacts());
        [$exit, $out] = $this->runCmd(['action' => 'cycle', '--facts' => $path, '--json' => true]);

        $this->assertSame(AtlasSelfConstructionFinalArchitectureSmokeCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertTrue($decoded['cycle_ready']);
        $this->assertSame(2, $decoded['evidence_refs_count']);
    }

    public function test_coverage_action_reports_full_coverage(): void
    {
        $path = $this->fixture($this->happyFacts());
        [, $out] = $this->runCmd(['action' => 'coverage', '--facts' => $path, '--json' => true]);
        $decoded = json_decode(trim($out), true);
        $this->assertTrue($decoded['fully_covered']);
    }

    public function test_stewardship_action_reports_runtime_plan_present(): void
    {
        $path = $this->fixture($this->happyFacts());
        [, $out] = $this->runCmd(['action' => 'stewardship', '--facts' => $path, '--json' => true]);
        $decoded = json_decode(trim($out), true);
        $this->assertFalse($decoded['refused']);
        $this->assertTrue($decoded['has_runtime_plan']);
        $this->assertSame('atlas-server', $decoded['project_id']);
    }

    public function test_queue_repair_action_reports_ready(): void
    {
        $path = $this->fixture($this->happyFacts());
        [, $out] = $this->runCmd(['action' => 'queue-repair', '--facts' => $path, '--json' => true]);
        $decoded = json_decode(trim($out), true);
        $this->assertTrue($decoded['queue_repair_ready']);
        $this->assertSame(3, $decoded['repaired_packets']);
    }

    public function test_smoke_action_complete_only_when_all_four_facts_are_atlas_native(): void
    {
        $path = $this->fixture($this->happyFacts());
        [, $out] = $this->runCmd(['action' => 'smoke', '--facts' => $path, '--json' => true]);
        $decoded = json_decode(trim($out), true);
        $this->assertTrue($decoded['complete']);
        $this->assertSame([], $decoded['blockers']);
    }

    public function test_smoke_action_marks_blockers_when_cycle_not_atlas_native(): void
    {
        $facts = $this->happyFacts();
        $facts['cycle']['atlas_native'] = false;
        $path = $this->fixture($facts);
        [, $out] = $this->runCmd(['action' => 'smoke', '--facts' => $path, '--json' => true]);
        $decoded = json_decode(trim($out), true);
        $this->assertFalse($decoded['complete']);
        $this->assertContains('cycle_not_atlas_native_or_no_evidence', $decoded['blockers']);
    }

    public function test_smoke_action_includes_missing_organ_blockers(): void
    {
        $facts = $this->happyFacts();
        $facts['coverage'] = ['fully_covered' => false, 'missing_organ' => ['cortex']];
        $path = $this->fixture($facts);
        [, $out] = $this->runCmd(['action' => 'smoke', '--facts' => $path, '--json' => true]);
        $decoded = json_decode(trim($out), true);
        $this->assertFalse($decoded['complete']);
        $this->assertContains('coverage:missing_organ:cortex', $decoded['blockers']);
    }

    public function test_malformed_facts_path_fails_closed(): void
    {
        [$exit] = $this->runCmd(['action' => 'smoke']);
        $this->assertSame(AtlasSelfConstructionFinalArchitectureSmokeCommand::EXIT_USAGE, $exit);
    }

    public function test_invalid_json_facts_fails_closed(): void
    {
        $path = sys_get_temp_dir().'/atlas-smoke-bad-'.bin2hex(random_bytes(3)).'.json';
        file_put_contents($path, '{not-json,');
        $this->tempFiles[] = $path;
        [$exit] = $this->runCmd(['action' => 'smoke', '--facts' => $path]);
        $this->assertSame(AtlasSelfConstructionFinalArchitectureSmokeCommand::EXIT_USAGE, $exit);
    }

    public function test_unknown_action_fails_closed(): void
    {
        $path = $this->fixture([]);
        [$exit] = $this->runCmd(['action' => 'BOGUS', '--facts' => $path]);
        $this->assertSame(AtlasSelfConstructionFinalArchitectureSmokeCommand::EXIT_USAGE, $exit);
    }

    public function test_cli_source_makes_no_provider_or_git_calls(): void
    {
        $src = (string) file_get_contents(base_path('app/Console/Commands/AtlasSelfConstructionFinalArchitectureSmokeCommand.php'));
        foreach (['shell_exec', 'system(', 'exec(', 'proc_open', 'Process::run', 'curl_', 'git ', 'Http::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "CLI source must NOT contain {$forbidden}");
        }
    }

    public function test_missing_facts_with_json_flag_emits_usage_error_envelope(): void
    {
        [$exit, $out] = $this->runCmd(['action' => 'smoke', '--json' => true]);

        $this->assertSame(AtlasSelfConstructionFinalArchitectureSmokeCommand::EXIT_USAGE, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertIsArray($decoded, 'output must be valid JSON when --json is set');
        $this->assertSame('usage_error', $decoded['status']);
        $this->assertNotEmpty($decoded['reason']);
    }
}
