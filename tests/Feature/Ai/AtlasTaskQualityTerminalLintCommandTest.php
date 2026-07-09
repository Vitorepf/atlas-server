<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasTaskQualityTerminalLintCommandTest extends TestCase
{
    private string $packetPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packetPath = sys_get_temp_dir().'/atlas_tq_terminal_packet_'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->packetPath);
        parent::tearDown();
    }

    private function writeJson(array $data): void
    {
        file_put_contents($this->packetPath, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public function test_terminal_good_packet_is_accepted(): void
    {
        $this->writeJson([
            'packet_id' => 'terminal-good',
            'objective' => 'Polish the atlas:cli:inbox terminal cockpit for review actions',
            'allowed_files' => [
                'app/Console/Commands/AtlasCliInboxCommand.php',
                'tests/Feature/Ai/AtlasCliInboxOperatorActionsTest.php',
            ],
            'acceptance_criteria' => [
                '/opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasCliInboxOperatorActionsTest.php exits 0',
            ],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            'worker_instructions' => 'Implement and test the cockpit changes.',
        ]);

        $exit = Artisan::call('atlas:task:quality', ['action' => 'lint', '--packet' => $this->packetPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($p['lint']['accepted']);
        $this->assertTrue($p['lint']['terminal_lint']['accepted']);
        $this->assertSame([], $p['lint']['terminal_lint']['findings']);
    }

    public function test_terminal_packet_without_test_file_is_rejected(): void
    {
        $this->writeJson([
            'packet_id' => 'terminal-no-test',
            'objective' => 'Add CLI dashboard widget for Atlas Terminal',
            'allowed_files' => [
                'app/Services/Ai/Cli/AtlasCliDashboardService.php',
            ],
            'acceptance_criteria' => [
                '/opt/homebrew/bin/php artisan atlas:cli:dashboard --json returns structured JSON',
            ],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            'worker_instructions' => 'Add the widget.',
        ]);

        $exit = Artisan::call('atlas:task:quality', ['action' => 'lint', '--packet' => $this->packetPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertFalse($p['lint']['accepted']);
        $this->assertContains('terminal_missing_test_file', $p['lint']['terminal_lint']['findings']);
    }

    public function test_terminal_packet_without_runnable_php_acceptance_is_rejected(): void
    {
        $this->writeJson([
            'packet_id' => 'terminal-no-php',
            'objective' => 'Review the Atlas Terminal inbox surface',
            'allowed_files' => [
                'app/Console/Commands/AtlasCliInboxCommand.php',
                'tests/Feature/Ai/AtlasCliInboxOperatorActionsTest.php',
            ],
            'acceptance_criteria' => [
                'The inbox surface is polished and useful.',
            ],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            'worker_instructions' => 'Polish the surface.',
        ]);

        $exit = Artisan::call('atlas:task:quality', ['action' => 'lint', '--packet' => $this->packetPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertContains('terminal_acceptance_not_runnable_with_php', $p['lint']['terminal_lint']['findings']);
    }

    public function test_terminal_packet_missing_required_evidence_is_rejected(): void
    {
        $this->writeJson([
            'packet_id' => 'terminal-missing-evidence',
            'objective' => 'Add cockpit actions to Atlas Terminal CLI',
            'allowed_files' => [
                'app/Console/Commands/AtlasCliInboxCommand.php',
                'tests/Feature/Ai/AtlasCliInboxOperatorActionsTest.php',
            ],
            'acceptance_criteria' => [
                '/opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasCliInboxOperatorActionsTest.php exits 0',
            ],
            'required_evidence' => ['tests_or_gates_result'],
            'worker_instructions' => 'Add actions.',
        ]);

        $exit = Artisan::call('atlas:task:quality', ['action' => 'lint', '--packet' => $this->packetPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertContains('terminal_missing_evidence_implementation_notes', $p['lint']['terminal_lint']['findings']);
    }

    public function test_terminal_cosmetic_wrapper_is_rejected(): void
    {
        $this->writeJson([
            'packet_id' => 'terminal-cosmetic',
            'objective' => 'Cosmetic wrapper around the Atlas Terminal CLI',
            'allowed_files' => [
                'app/Console/Commands/AtlasCliInboxCommand.php',
                'tests/Feature/Ai/AtlasCliInboxOperatorActionsTest.php',
            ],
            'acceptance_criteria' => [
                '/opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasCliInboxOperatorActionsTest.php exits 0',
            ],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            'worker_instructions' => 'Wrap the CLI.',
        ]);

        $exit = Artisan::call('atlas:task:quality', ['action' => 'lint', '--packet' => $this->packetPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertContains('terminal_cosmetic_template_wrapper', $p['lint']['terminal_lint']['findings']);
    }

    public function test_non_terminal_packet_skips_terminal_lint(): void
    {
        $this->writeJson([
            'packet_id' => 'non-terminal',
            'objective' => 'Add background job processor for Atlas AI',
            'allowed_files' => [
                'app/Services/Ai/Jobs/Processor.php',
            ],
            'acceptance_criteria' => [
                'The processor handles jobs correctly.',
            ],
            'required_evidence' => [],
            'worker_instructions' => 'Implement the processor.',
        ]);

        $exit = Artisan::call('atlas:task:quality', ['action' => 'lint', '--packet' => $this->packetPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($p['lint']['terminal_lint']['accepted']);
        $this->assertSame([], $p['lint']['terminal_lint']['findings']);
    }
}