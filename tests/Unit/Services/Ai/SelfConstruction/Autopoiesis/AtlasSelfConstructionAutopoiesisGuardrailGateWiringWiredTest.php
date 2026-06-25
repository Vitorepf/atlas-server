<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Autopoiesis;

use App\Console\Commands\AtlasSelfConstructionAutopoiesisCommand;
use App\Services\Ai\SelfConstruction\Autopoiesis\AtlasSelfConstructionAutopoiesisGuardrailGate;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

/**
 * Wires AtlasSelfConstructionAutopoiesisGuardrailGate into the live
 * `atlas:self-construction:autopoiesis guardrail` flow. Proves the previously-orphan gate is
 * reached by real production code via the operator CLI.
 */
final class AtlasSelfConstructionAutopoiesisGuardrailGateWiringWiredTest extends TestCase
{
    private string $factsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas-autopoiesis-guardrail-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        parent::tearDown();
    }

    public function test_command_class_imports_the_guardrail_gate_symbol(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AtlasSelfConstructionAutopoiesisCommand::class))->getFileName(),
        );
        $this->assertStringContainsString(
            AtlasSelfConstructionAutopoiesisGuardrailGate::class,
            $source,
            'autopoiesis command must reference the guardrail gate so it is no longer an orphan',
        );
    }

    public function test_guardrail_blocks_bypass_kernel_experiment(): void
    {
        file_put_contents($this->factsPath, (string) json_encode([
            'bypasses_kernel' => true,
        ]));

        $exit = Artisan::call('atlas:self-construction:autopoiesis', [
            'action' => 'guardrail',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('ok', $payload['status']);
        $verdict = (array) $payload['guardrail'];
        $this->assertFalse((bool) $verdict['accepted']);
        $this->assertSame(AtlasSelfConstructionAutopoiesisGuardrailGate::ACTION_BLOCK, $verdict['action']);
        $this->assertSame(AtlasSelfConstructionAutopoiesisGuardrailGate::CLASS_REJECTED, $verdict['allowed_class']);
        $this->assertContains('experiment_bypasses_kernel', $verdict['blockers']);
    }

    public function test_guardrail_allows_read_only_experiment(): void
    {
        file_put_contents($this->factsPath, (string) json_encode([
            'read_only' => true,
        ]));

        $exit = Artisan::call('atlas:self-construction:autopoiesis', [
            'action' => 'guardrail',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $verdict = (array) $payload['guardrail'];
        $this->assertTrue((bool) $verdict['accepted']);
        $this->assertSame(AtlasSelfConstructionAutopoiesisGuardrailGate::CLASS_READ_ONLY, $verdict['allowed_class']);
    }

    public function test_guardrail_usage_error_without_facts(): void
    {
        $exit = Artisan::call('atlas:self-construction:autopoiesis', [
            'action' => 'guardrail',
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame('usage_error', $payload['status']);
    }
}
