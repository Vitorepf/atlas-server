<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopAutopoieticConstitutionCommand;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution\AtlasLoopAutopoieticConstitutionDriftDetector;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution\AtlasLoopAutopoieticConstitutionRegistry;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasLoopAutopoieticConstitutionCommandTest extends TestCase
{
    private string $fingerprintFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fingerprintFile = sys_get_temp_dir().'/atlas-constitution-fingerprint-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->fingerprintFile);
        parent::tearDown();
    }

    private function bindDetectorWithFingerprint(?string $writtenFingerprint): void
    {
        if ($writtenFingerprint !== null) {
            file_put_contents($this->fingerprintFile, json_encode(['fingerprint' => $writtenFingerprint]));
        }
        $this->app->instance(
            AtlasLoopAutopoieticConstitutionDriftDetector::class,
            new AtlasLoopAutopoieticConstitutionDriftDetector(
                $this->app->make(AtlasLoopAutopoieticConstitutionRegistry::class),
                $this->fingerprintFile,
            ),
        );
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:loop:autopoiesis:constitution', $args);

        return [$exit, $kernel->output()];
    }

    public function test_inspect_json_prints_fingerprint_forbidden_scopes_and_approval_thresholds(): void
    {
        [$exit, $out] = $this->runCmd(['action' => 'inspect', '--json' => true]);

        $this->assertSame(AtlasLoopAutopoieticConstitutionCommand::EXIT_OK, $exit, $out);
        $decoded = json_decode(trim($out), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('fingerprint', $decoded);
        $this->assertArrayHasKey('forbidden_scopes', $decoded);
        $this->assertArrayHasKey('approval_thresholds', $decoded);
    }

    public function test_verify_with_simulated_drift_exits_two_and_says_drift_detected(): void
    {
        // Persist a fingerprint anchor that does NOT match the registry's live fingerprint ⇒ drifted=true.
        $this->bindDetectorWithFingerprint(str_repeat('0', 64));

        [$exit, $out] = $this->runCmd(['action' => 'verify']);

        $this->assertSame(AtlasLoopAutopoieticConstitutionCommand::EXIT_DRIFT, $exit);
        $this->assertStringContainsString('drift_detected', $out);
    }

    public function test_verify_with_matching_fingerprint_exits_zero(): void
    {
        $registry = $this->app->make(AtlasLoopAutopoieticConstitutionRegistry::class);
        $this->bindDetectorWithFingerprint($registry->fingerprint());

        [$exit, $out] = $this->runCmd(['action' => 'verify']);

        $this->assertSame(AtlasLoopAutopoieticConstitutionCommand::EXIT_OK, $exit, $out);
    }

    public function test_verify_with_no_fingerprint_anchor_exits_three(): void
    {
        $this->bindDetectorWithFingerprint(null);

        [$exit, $out] = $this->runCmd(['action' => 'verify']);

        $this->assertSame(AtlasLoopAutopoieticConstitutionCommand::EXIT_MISSING_ANCHOR, $exit);
        $this->assertStringContainsString('missing_fingerprint_anchor', $out);
    }

    public function test_command_source_contains_no_write_or_append_call_sites(): void
    {
        // Pure-observability invariant: a static grep over the command source must find no write paths to the
        // fingerprint file nor append paths to the receipt ledger.
        $source = (string) file_get_contents(base_path('app/Console/Commands/AtlasLoopAutopoieticConstitutionCommand.php'));

        $this->assertStringNotContainsString('file_put_contents', $source);
        $this->assertStringNotContainsString('fwrite', $source);
        $this->assertStringNotContainsString('->append(', $source);
        $this->assertStringNotContainsString('LOCK_EX', $source);
    }
}
