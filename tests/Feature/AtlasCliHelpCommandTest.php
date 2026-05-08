<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasCliHelpCommandTest extends TestCase
{
    public function test_cli_help_outputs_command_map_as_json(): void
    {
        $exitCode = Artisan::call('atlas:cli:help', [
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $architectureCommands = array_column($payload['commands']['arquitetura_mae'], 'command');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"Atlas CLI"', $output);
        $this->assertStringContainsString('atlas bootstrap', $output);
        $this->assertStringContainsString('atlas bootstrap --provider-projection=status', $output);
        $this->assertStringContainsString('atlas dev', $output);
        $this->assertStringContainsString('atlas doctor', $output);
        $this->assertStringContainsString('atlas install', $output);
        $this->assertStringContainsString('atlas quality', $output);
        $this->assertStringContainsString('atlas benchmark', $output);
        $this->assertStringContainsString('atlas benchmark seed', $output);
        $this->assertStringContainsString('atlas benchmark calibrate', $output);
        $this->assertStringContainsString('atlas benchmark cleanup', $output);
        $this->assertStringContainsString('atlas engineering run', $output);
        $this->assertStringContainsString('--quality-scan=auto', $output);
        $this->assertStringContainsString('atlas engineering replay', $output);
        $this->assertStringContainsString('atlas memory projection', $output);
        $this->assertStringContainsString('atlas engineering harnessability calibrate', $output);
        $this->assertStringContainsString('atlas engineering quality-scan', $output);
        $this->assertStringContainsString('atlas tools authority --json', $output);
        $this->assertStringContainsString('--sandbox-mode=worktree', $output);
        $this->assertStringContainsString('--tool-env=KEY=VALUE', $output);
        $this->assertStringContainsString('--requires-provider-safe', $output);
        $this->assertStringContainsString('atlas engineering visual-smoke', $output);
        $this->assertStringContainsString('atlas engineering visual-driver install', $output);
        $this->assertStringContainsString('atlas engineering visual-baseline', $output);
        $this->assertStringContainsString('atlas dogfood', $output);
        $this->assertStringContainsString('atlas release', $output);
        $this->assertStringContainsString('arquitetura_mae', $output);
        $this->assertStringContainsString('atlas ai architecture-operations --json', $output);
        $this->assertStringContainsString('atlas ai architecture-validate', $output);
        $this->assertStringContainsString('atlas ai slo --hours=24 --json', $output);
        $this->assertStringContainsString('atlas ai kernel-pipeline-report --hours=24 --json', $output);
        $this->assertStringContainsString('atlas ai repair-report --hours=24 --json', $output);
        $this->assertStringContainsString('atlas ai provider-performance --hours=24 --json', $output);
        $this->assertContains('php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json', $architectureCommands);
        $this->assertStringContainsString('atlas ai agent-behavior-report --hours=24 --json', $output);
        $this->assertStringContainsString('atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json', $output);
        $this->assertStringContainsString('atlas ai self-improve --flow=provider_performance_review --hours=168 --json', $output);
        $this->assertStringContainsString('atlas ai self-improve --flow=provider_release_review --hours=168 --json', $output);
        $this->assertStringContainsString('atlas ai self-improve --flow=agent_behavior_review --hours=168 --json', $output);
        $this->assertStringContainsString('atlas ai self-improvement-schedule-report --hours=24 --json', $output);
        $this->assertStringContainsString('atlas ai inbox-action-report --hours=24 --json', $output);
    }
}
