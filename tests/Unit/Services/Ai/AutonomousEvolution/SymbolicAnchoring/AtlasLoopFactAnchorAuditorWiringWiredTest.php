<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\AutonomousEvolution\SymbolicAnchoring;

use App\Console\Commands\AtlasLoopFactAnchorCommand;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\AnchorAuditVerdict;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\AtlasLoopFactAnchorAuditor;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

/**
 * Wires AnchorAuditVerdict (returned by AtlasLoopFactAnchorAuditor::audit) into the live
 * `atlas:loop:facts:anchor audit` flow. Proves the previously-orphan verdict object is reached
 * by real production code via the operator CLI.
 */
final class AtlasLoopFactAnchorAuditorWiringWiredTest extends TestCase
{
    private string $envPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->envPath = sys_get_temp_dir().'/atlas-fact-anchor-env-'.bin2hex(random_bytes(6));
        AtlasLoopMasterSwitch::$envPathOverride = $this->envPath;
        file_put_contents($this->envPath, 'ATLAS_LOOP_MASTER_ENABLED=true'.PHP_EOL);
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->envPath);
        parent::tearDown();
    }

    public function test_command_class_and_auditor_reference_the_verdict_symbol(): void
    {
        $cliSource = (string) file_get_contents(
            (new ReflectionClass(AtlasLoopFactAnchorCommand::class))->getFileName(),
        );
        $auditorSource = (string) file_get_contents(
            (new ReflectionClass(AtlasLoopFactAnchorAuditor::class))->getFileName(),
        );

        $this->assertStringContainsString(AtlasLoopFactAnchorAuditor::class, $cliSource);
        $this->assertStringContainsString('AnchorAuditVerdict', $auditorSource,
            'auditor module must define/use AnchorAuditVerdict so the CLI reaches it');
    }

    public function test_audit_action_with_phantom_only_fact_returns_rejection_via_verdict(): void
    {
        // Fact text mentioning a clearly non-existent path → extractor yields ONLY unresolved
        // anchors → auditor returns a rejection verdict.
        $exit = Artisan::call('atlas:loop:facts:anchor', [
            'action' => 'audit',
            '--channel' => 'comprehension',
            '--fact-text' => 'See app/Phantom/DoesNotExist_'.bin2hex(random_bytes(4)).'.php for proof.',
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame(1, $exit, 'phantom-only audit must exit non-zero (rejection)');
        $this->assertFalse((bool) $payload['accepted']);
        // The reason MUST be one of the verdict's reject reasons emitted by AnchorAuditVerdict.
        $this->assertContains($payload['reason'], ['only_phantom_anchors', 'no_resolved_anchor']);
        $this->assertArrayHasKey('resolved_count', $payload);
        $this->assertArrayHasKey('unresolved_count', $payload);
    }

    public function test_audit_action_on_non_critical_channel_returns_passthrough_acceptance(): void
    {
        // A channel NOT in the critical set must be accepted by the auditor with the canonical
        // passthrough reason carried on the AnchorAuditVerdict.
        $exit = Artisan::call('atlas:loop:facts:anchor', [
            'action' => 'audit',
            '--channel' => 'some-non-critical-channel-'.bin2hex(random_bytes(3)),
            '--fact-text' => 'irrelevant',
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertTrue((bool) $payload['accepted']);
        $this->assertSame('non_critical_channel_passthrough', $payload['reason']);
    }

    public function test_verdict_static_factories_round_trip(): void
    {
        // Direct smoke test on the previously-orphan symbol.
        $accept = AnchorAuditVerdict::accept(3, 1, 'ok');
        $reject = AnchorAuditVerdict::reject('only_phantom_anchors', 0, 2);

        $this->assertTrue($accept->accepted);
        $this->assertSame(3, $accept->resolvedCount);
        $this->assertSame(1, $accept->unresolvedCount);

        $this->assertFalse($reject->accepted);
        $this->assertSame('only_phantom_anchors', $reject->reason);
        $this->assertSame(2, $reject->unresolvedCount);
    }
}
