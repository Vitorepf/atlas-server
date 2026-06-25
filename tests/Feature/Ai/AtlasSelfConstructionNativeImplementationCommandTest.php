<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasSelfConstructionNativeImplementationCommand;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasSelfConstructionNativeImplementationCommandTest extends TestCase
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
        $path = sys_get_temp_dir().'/atlas-ni-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, (string) json_encode($payload));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:self-construction:native-implementation', $args);

        return [$exit, $kernel->output()];
    }

    public function test_templates_action_lists_canonical_templates(): void
    {
        [$exit, $out] = $this->runCmd(['action' => 'templates', '--json' => true]);
        $this->assertSame(AtlasSelfConstructionNativeImplementationCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertContains('stub_class_skeleton', $decoded['templates']);
        $this->assertContains('add_missing_use_statement', $decoded['templates']);
    }

    public function test_plan_action_lints_well_formed_packet(): void
    {
        $packet = $this->fixture([
            'allowed_files' => ['app/Foo.php'],
            'patches' => [['path' => 'app/Foo.php', 'mode' => 'create', 'next' => 'x']],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'plan', '--packet' => $packet, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionNativeImplementationCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertTrue($decoded['plan_ok']);
    }

    public function test_plan_action_rejects_forbidden_path(): void
    {
        $packet = $this->fixture([
            'allowed_files' => ['app/Foo.php'],
            'patches' => [['path' => 'config/atlas.php', 'mode' => 'create', 'next' => 'x']],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'plan', '--packet' => $packet, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionNativeImplementationCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertFalse($decoded['plan_ok']);
        $this->assertContains('forbidden_path:config/atlas.php', $decoded['blockers']);
    }

    public function test_materialize_action_produces_diff(): void
    {
        $packet = $this->fixture([
            'allowed_files' => ['app/Foo.php'],
            'patches' => [['path' => 'app/Foo.php', 'mode' => 'create', 'next' => "line1\nline2"]],
        ]);
        [$exit, $out] = $this->runCmd(['action' => 'materialize', '--packet' => $packet, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionNativeImplementationCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertTrue($decoded['accepted']);
        $this->assertStringContainsString('+line1', $decoded['diffs'][0]['unified_diff']);
    }

    public function test_repair_action_emits_proposal(): void
    {
        $packet = $this->fixture(['allowed_files' => ['app/Foo.php']]);
        $facts = $this->fixture(['failures' => [['failure_kind' => 'missing_class', 'target_path' => 'app/Foo.php', 'class_name' => 'AtlasFoo']]]);
        [$exit, $out] = $this->runCmd(['action' => 'repair', '--packet' => $packet, '--facts' => $facts, '--json' => true]);
        $this->assertSame(AtlasSelfConstructionNativeImplementationCommand::EXIT_OK, $exit);
        $decoded = json_decode(trim($out), true);
        $this->assertSame('stub_class_skeleton', $decoded['proposals'][0]['template_id']);
    }

    public function test_missing_packet_path_fails_closed(): void
    {
        foreach (['plan', 'materialize', 'repair'] as $action) {
            [$exit] = $this->runCmd(['action' => $action]);
            $this->assertSame(AtlasSelfConstructionNativeImplementationCommand::EXIT_USAGE, $exit, "{$action} without --packet must fail closed");
        }
    }

    public function test_unknown_action_fails_closed(): void
    {
        [$exit] = $this->runCmd(['action' => 'BOGUS']);
        $this->assertSame(AtlasSelfConstructionNativeImplementationCommand::EXIT_USAGE, $exit);
    }

    public function test_cli_source_makes_no_provider_or_git_calls(): void
    {
        $src = (string) file_get_contents(base_path('app/Console/Commands/AtlasSelfConstructionNativeImplementationCommand.php'));
        foreach (['file_put_contents', 'shell_exec', 'exec(', 'system(', 'proc_open', 'Process::run', 'curl_', 'git ', 'Http::'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "CLI source must NOT contain {$forbidden}");
        }
    }
}
