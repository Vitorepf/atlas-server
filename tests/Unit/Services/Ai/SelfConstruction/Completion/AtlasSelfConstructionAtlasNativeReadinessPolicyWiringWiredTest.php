<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeReadinessPolicy;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * brain-orphan-b61e59ede23f — prove AtlasSelfConstructionAtlasNativeReadinessPolicy is wired
 * into the live `atlas:self-construction:atlas-native-completion readiness` CLI action.
 */
class AtlasSelfConstructionAtlasNativeReadinessPolicyWiringWiredTest extends TestCase
{
    private string $factsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas-readiness-facts-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        parent::tearDown();
    }

    public function test_readiness_action_with_full_atlas_native_facts_returns_ready_outcome(): void
    {
        $ordinary = [];
        foreach (AtlasSelfConstructionAtlasNativeReadinessPolicy::REQUIRED_ORDINARY_CAPABILITIES as $cap) {
            $ordinary[$cap] = [
                'owner' => AtlasSelfConstructionAtlasNativeReadinessPolicy::ATLAS_NATIVE_OWNER,
                'verified' => true,
                'evidence_ref' => 'evidence://'.$cap,
            ];
        }
        file_put_contents($this->factsPath, json_encode([
            'ordinary_capabilities' => $ordinary,
            'oversight_capabilities' => ['oversee_release' => ['owner' => 'atlas_native']],
            'emergency_capabilities' => ['emergency_rollback' => ['owner' => 'atlas_native']],
        ]));

        $payload = $this->runCmd();
        self::assertSame('ok', $payload['status']);
        self::assertSame(AtlasSelfConstructionAtlasNativeReadinessPolicy::OUTCOME_READY, $payload['final_state']);
        self::assertSame(AtlasSelfConstructionAtlasNativeReadinessPolicy::OUTCOME_READY, $payload['readiness']['outcome']);
        self::assertSame([], $payload['readiness']['blockers']);
        self::assertNotEmpty($payload['readiness']['owned_by_atlas']);
    }

    public function test_readiness_action_with_missing_capability_reports_evidence_blocker(): void
    {
        file_put_contents($this->factsPath, json_encode([
            'ordinary_capabilities' => [],
        ]));

        $payload = $this->runCmd();
        self::assertSame(AtlasSelfConstructionAtlasNativeReadinessPolicy::OUTCOME_BLOCKED_EVIDENCE, $payload['readiness']['outcome']);
        self::assertNotEmpty($payload['readiness']['blockers']);
    }

    public function test_readiness_action_with_external_owner_reports_dependency_blocker(): void
    {
        $ordinary = [];
        foreach (AtlasSelfConstructionAtlasNativeReadinessPolicy::REQUIRED_ORDINARY_CAPABILITIES as $cap) {
            $ordinary[$cap] = [
                'owner' => 'codex',
                'verified' => true,
                'evidence_ref' => 'evidence://'.$cap,
            ];
        }
        file_put_contents($this->factsPath, json_encode(['ordinary_capabilities' => $ordinary]));

        $payload = $this->runCmd();
        self::assertSame(AtlasSelfConstructionAtlasNativeReadinessPolicy::OUTCOME_BLOCKED_DEPENDENCY, $payload['readiness']['outcome']);
        self::assertNotEmpty($payload['readiness']['blockers']);
    }

    public function test_command_source_imports_and_invokes_the_policy(): void
    {
        $source = (string) file_get_contents(
            base_path('app/Console/Commands/AtlasSelfConstructionAtlasNativeCompletionCommand.php')
        );
        self::assertStringContainsString(AtlasSelfConstructionAtlasNativeReadinessPolicy::class, $source);
        self::assertStringContainsString("'readiness'", $source);
        self::assertStringContainsString('->evaluate(', $source);
    }

    /**
     * @return array<string,mixed>
     */
    private function runCmd(): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->call('atlas:self-construction:atlas-native-completion', [
            'action' => 'readiness',
            '--facts' => $this->factsPath,
            '--json' => true,
        ], $buf);

        $decoded = json_decode(trim($buf->fetch()), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
