<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasSelfConstructionGoalValueCommand;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasSelfConstructionGoalValueCommandTest extends TestCase
{
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
        $path = sys_get_temp_dir().'/atlas-gv-facts-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, (string) json_encode($payload));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:self-construction:goal-value', $args);

        return [$exit, $kernel->output()];
    }

    public function test_contract_with_real_evidence_emits_real_leverage_true(): void
    {
        $path = $this->fixture([
            'dimension_evidence' => [
                'capability_lift' => ['evidence_kind' => 'new_capability', 'evidence_refs' => ['receipt:r1']],
                'failure_removal' => ['evidence_kind' => 'red_test_removed', 'evidence_refs' => ['test:t1']],
            ],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'contract', '--facts' => $path, '--json' => true]);

        $this->assertSame(AtlasSelfConstructionGoalValueCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertTrue($decoded['real_leverage']);
    }

    public function test_anti_proxy_blocks_proxy_only_signals(): void
    {
        $path = $this->fixture([
            'signals' => ['task_count' => true, 'line_churn' => true],
            'real_levers' => [],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'anti-proxy', '--facts' => $path, '--json' => true]);

        $this->assertSame(AtlasSelfConstructionGoalValueCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertTrue($decoded['blocked']);
        $this->assertContains('task_count', $decoded['blocked_proxy_categories']);
    }

    public function test_decide_promotes_when_real_leverage_and_green_verification(): void
    {
        $path = $this->fixture([
            'leverage_verdict' => ['real_leverage' => true, 'proxy_only' => false, 'blockers' => []],
            'anti_proxy_verdict' => ['blocked' => false, 'blocked_proxy_categories' => []],
            'verification' => ['passed' => true, 'color' => 'green', 'evidence' => ['phpunit:exit_0']],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'decide', '--facts' => $path, '--json' => true]);

        $this->assertSame(AtlasSelfConstructionGoalValueCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame('promote', $decoded['decision']);
    }

    public function test_evidence_action_echoes_facts(): void
    {
        $facts = ['arbitrary' => 'fact_block', 'nested' => ['a' => 1]];
        $path = $this->fixture($facts);
        [$exit, $out] = $this->runCmd(['action' => 'evidence', '--facts' => $path, '--json' => true]);

        $this->assertSame(AtlasSelfConstructionGoalValueCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame($facts, $decoded['echo']);
    }

    public function test_missing_facts_path_fails_closed(): void
    {
        [$exit] = $this->runCmd(['action' => 'contract']);
        $this->assertSame(AtlasSelfConstructionGoalValueCommand::EXIT_USAGE, $exit);
    }

    public function test_invalid_json_facts_fails_closed(): void
    {
        $path = sys_get_temp_dir().'/atlas-gv-bad-'.bin2hex(random_bytes(3)).'.json';
        file_put_contents($path, '{not-json,');
        $this->tempFiles[] = $path;

        [$exit] = $this->runCmd(['action' => 'contract', '--facts' => $path]);
        $this->assertSame(AtlasSelfConstructionGoalValueCommand::EXIT_USAGE, $exit);
    }

    public function test_unknown_action_fails_closed(): void
    {
        $path = $this->fixture([]);
        [$exit] = $this->runCmd(['action' => 'BOGUS', '--facts' => $path]);
        $this->assertSame(AtlasSelfConstructionGoalValueCommand::EXIT_USAGE, $exit);
    }
}
