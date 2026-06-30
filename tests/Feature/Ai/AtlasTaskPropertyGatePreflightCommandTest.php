<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasTaskPropertyGatePreflightCommandTest extends TestCase
{
    private function preflight(array $files, array $extra = []): array
    {
        $args = array_merge(['--files' => $files, '--json' => true], $extra);
        $exitCode = Artisan::call('atlas:task:property-gate-preflight', $args);
        $output = Artisan::output();
        $decoded = json_decode(trim($output), true);

        return ['exit_code' => $exitCode, 'output' => $output, 'data' => is_array($decoded) ? $decoded : []];
    }

    public function test_ordinary_files_are_classified_and_emitted(): void
    {
        $result = $this->preflight(['app/Console/Commands/AtlasFoo.php', 'app/Services/Ai/AtlasBarService.php']);

        $this->assertSame(0, $result['exit_code']);
        $this->assertArrayHasKey('ordinary', $result['data']);
        $this->assertArrayHasKey('property_gated', $result['data']);
        $this->assertArrayHasKey('forbidden', $result['data']);
        $this->assertArrayHasKey('required_evidence', $result['data']);
        $this->assertContains('app/Console/Commands/AtlasFoo.php', $result['data']['ordinary']);
        $this->assertSame([], $result['data']['property_gated']);
        $this->assertSame([], $result['data']['forbidden']);
        $this->assertSame([], $result['data']['required_evidence']);
    }

    public function test_property_gated_brain_file_emits_required_evidence_contract(): void
    {
        $result = $this->preflight([
            'app/Console/Commands/AtlasFoo.php',
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainSomethingNew.php',
        ]);

        $this->assertSame(0, $result['exit_code'], 'property_gated alone must not exit with failure');
        $this->assertContains(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainSomethingNew.php',
            $result['data']['property_gated'],
        );
        $this->assertContains('constitution_gate_receipt', $result['data']['required_evidence'],
            'property_gated target must mandate constitution_gate_receipt');
        $this->assertSame([], $result['data']['forbidden']);
    }

    public function test_forbidden_target_exits_failure_and_emits_forbidden_field(): void
    {
        $result = $this->preflight([
            'app/Console/Commands/AtlasFoo.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php',
        ]);

        $this->assertNotSame(0, $result['exit_code'], 'forbidden target must cause non-zero exit');
        $this->assertContains(
            'app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php',
            $result['data']['forbidden'],
        );
    }

    public function test_mixed_fixture_emits_all_three_classification_fields(): void
    {
        $result = $this->preflight([
            'app/Console/Commands/AtlasFoo.php',
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainX.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopMasterSwitch.php',
        ]);

        // forbidden wins → failure exit
        $this->assertNotSame(0, $result['exit_code']);
        $this->assertNotEmpty($result['data']['ordinary']);
        $this->assertNotEmpty($result['data']['property_gated']);
        $this->assertNotEmpty($result['data']['forbidden']);
        $this->assertContains('constitution_gate_receipt', $result['data']['required_evidence']);
    }

    public function test_command_is_read_only_no_git_no_enqueue_no_provider(): void
    {
        $src = (string) file_get_contents(
            base_path('app/Console/Commands/AtlasTaskPropertyGatePreflightCommand.php')
        );
        foreach (['shell_exec', 'exec(', 'proc_open', '`git ', 'Http::', 'curl_', 'DB::insert', 'DB::update', 'dispatch(', '->report(', '->commit('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src,
                "preflight command must not contain {$forbidden}");
        }
    }

    public function test_no_files_exits_failure(): void
    {
        $exitCode = Artisan::call('atlas:task:property-gate-preflight', []);
        $this->assertNotSame(0, $exitCode);
    }

    public function test_task_packet_id_without_files_emits_warning_not_error(): void
    {
        // --task-packet-id alone warns but does not crash; exits failure because no files resolved
        $exitCode = Artisan::call('atlas:task:property-gate-preflight', [
            '--task-packet-id' => 'some-fake-packet-id',
        ]);
        $output = Artisan::output();
        $this->assertStringContainsString('not yet wired', $output);
        $this->assertNotSame(0, $exitCode);
    }
}
