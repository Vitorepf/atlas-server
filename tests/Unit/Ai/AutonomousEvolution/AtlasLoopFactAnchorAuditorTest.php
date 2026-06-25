<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\AtlasLoopFactAnchorAuditor;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\AtlasLoopFactAnchorExtractor;
use Tests\TestCase;

final class AtlasLoopFactAnchorAuditorTest extends TestCase
{
    private string $root = '';

    private string $factsEnvPath = '';

    private ?string $prevOverride = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-anchor-auditor-'.bin2hex(random_bytes(6));
        @mkdir($this->root.'/app/Demo', 0o755, true);
        file_put_contents($this->root.'/app/Demo/Foo.php', "<?php\nfinal class Foo {}\n");
        $this->factsEnvPath = $this->root.'/.env';
        $this->prevOverride = AtlasLoopMasterSwitch::$envPathOverride;
        AtlasLoopMasterSwitch::$envPathOverride = $this->factsEnvPath;
        $this->setMaster(true);
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = $this->prevOverride;
        @unlink($this->root.'/app/Demo/Foo.php');
        @rmdir($this->root.'/app/Demo');
        @rmdir($this->root.'/app');
        @unlink($this->factsEnvPath);
        @rmdir($this->root);
        parent::tearDown();
    }

    private function setMaster(bool $on): void
    {
        file_put_contents($this->factsEnvPath, AtlasLoopMasterSwitch::KEY.'='.($on ? '1' : '0')."\n");
    }

    private function auditor(): AtlasLoopFactAnchorAuditor
    {
        return new AtlasLoopFactAnchorAuditor(
            new AtlasLoopFactAnchorExtractor($this->root),
            ['comprehension', 'decision', 'grading'],
        );
    }

    public function test_accept_when_resolved_anchor_present_in_critical_channel(): void
    {
        $verdict = $this->auditor()->audit('comprehension', 'See app/Demo/Foo.php for the class.');
        $this->assertTrue($verdict->accepted);
        $this->assertGreaterThan(0, $verdict->resolvedCount);
    }

    public function test_reject_when_no_anchor_at_all_in_critical_channel(): void
    {
        $verdict = $this->auditor()->audit('comprehension', 'just narrative text');
        $this->assertFalse($verdict->accepted);
        $this->assertSame('no_resolved_anchor', $verdict->reason);
    }

    public function test_reject_when_only_phantom_anchors_in_critical_channel(): void
    {
        $verdict = $this->auditor()->audit('decision', 'See app/Phantom/Missing.php and lib/also-missing.js');
        $this->assertFalse($verdict->accepted);
        $this->assertSame('only_phantom_anchors', $verdict->reason);
    }

    public function test_non_critical_channel_is_passthrough_accept(): void
    {
        $verdict = $this->auditor()->audit('side_channel', 'just narrative text');
        $this->assertTrue($verdict->accepted);
        $this->assertSame('non_critical_channel_passthrough', $verdict->reason);
    }

    public function test_master_switch_off_makes_every_input_accepted_byte_identically(): void
    {
        $auditor = $this->auditor();

        // Same payload that would be REJECTED with master on:
        $rejectedFact = 'just narrative text — would be no_resolved_anchor';
        $rejected = $auditor->audit('comprehension', $rejectedFact);
        $this->assertFalse($rejected->accepted);

        // Flip master OFF — same payload must now ACCEPT identically.
        $this->setMaster(false);
        $accepted = $auditor->audit('comprehension', $rejectedFact);
        $this->assertTrue($accepted->accepted);
        $this->assertSame('master_switch_off_passthrough', $accepted->reason);
    }
}
