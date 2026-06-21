<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExternalResearchService;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 5 · Slice 12 — the egress filter is the load-bearing safety property: a topic that would
 * leak the repo (a real path, a diff, a secret) is REJECTED, and with no search tool the service fail-closes
 * (never a silent fallback that emits the topic anyway).
 */
final class AtlasLoopExternalResearchServiceTest extends TestCase
{
    public function test_a_real_repo_path_fragment_is_blocked(): void
    {
        $svc = new AtlasLoopExternalResearchService(searchToolAvailable: true);
        $real = 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopExternalResearchService.php';
        $res = $svc->research("how does $real work", base_path());

        $this->assertTrue($res['blocked']);
        $this->assertFalse($res['researched']);
        $this->assertStringContainsString('egress_blocked:repo_path_fragment', $res['reason']);
    }

    public function test_a_diff_payload_is_blocked(): void
    {
        $svc = new AtlasLoopExternalResearchService(searchToolAvailable: true);
        $res = $svc->research("diff --git a/x b/x\n+leaked", base_path());
        $this->assertTrue($res['blocked']);
        $this->assertStringContainsString('diff_payload', $res['reason']);
    }

    public function test_a_secret_pattern_is_blocked(): void
    {
        $svc = new AtlasLoopExternalResearchService(searchToolAvailable: true);
        $res = $svc->research('research this api_key=sk-abcdefabcdefabcdefabcdef', base_path());
        $this->assertTrue($res['blocked']);
        $this->assertStringContainsString('secret_pattern', $res['reason']);
    }

    public function test_a_clean_topic_fail_closes_with_no_search_tool(): void
    {
        // Default: no tool wired ⇒ a clean topic does NOT research (fail-closed), never a silent emit.
        $res = (new AtlasLoopExternalResearchService())->research('theory of constraints bottleneck analysis', base_path());
        $this->assertFalse($res['blocked'], 'a clean topic is not a leak');
        $this->assertFalse($res['researched'], 'fail-closed: no tool ⇒ no research');
        $this->assertStringContainsString('no_search_tool', $res['reason']);
    }

    public function test_armed_but_no_backend_fail_closes_no_stub_launder(): void
    {
        // The honest contract: arming the flag WITHOUT a wired backend is NOT research. The old
        // 'research:'.$topic stub is gone — a flag alone can never launder a fake note as evidence.
        $res = (new AtlasLoopExternalResearchService(searchToolAvailable: true))->research('constraint theory in software delivery', base_path());
        $this->assertFalse($res['blocked'], 'a clean topic is not a leak');
        $this->assertFalse($res['researched'], 'fail-closed: armed but no backend ⇒ no research');
        $this->assertNull($res['note'], 'no backend ⇒ no note (never a stub)');
        $this->assertStringContainsString('no_research_backend', $res['reason']);
    }

    public function test_a_clean_topic_with_a_wired_backend_researches_advisory_only(): void
    {
        // A real (here faked) backend does the pull; the egress filter still runs BEFORE it.
        $backend = static fn (string $topic): string => "STATE OF THE ART for: {$topic}";
        $res = (new AtlasLoopExternalResearchService(searchToolAvailable: true, backend: $backend))
            ->research('constraint theory in software delivery', base_path());
        $this->assertFalse($res['blocked']);
        $this->assertTrue($res['researched']);
        $this->assertSame('STATE OF THE ART for: constraint theory in software delivery', $res['note']);
    }

    public function test_a_backend_that_yields_nothing_fail_closes(): void
    {
        // A backend that returns '' / null is not a research result — fail-closed, never an empty note.
        $empty = static fn (string $topic): ?string => null;
        $res = (new AtlasLoopExternalResearchService(searchToolAvailable: true, backend: $empty))
            ->research('a clean topic', base_path());
        $this->assertFalse($res['researched']);
        $this->assertNull($res['note']);
        $this->assertStringContainsString('backend_empty', $res['reason']);
    }

    public function test_a_blocked_topic_never_reaches_the_backend(): void
    {
        // Sovereignty over capability: the egress filter runs BEFORE the backend, so a leaking topic is
        // rejected without the pull ever being invoked (the backend would fail the test if called).
        $real = 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopExternalResearchService.php';
        $backend = function (string $topic): string {
            $this->fail('the backend must NEVER be invoked for an egress-blocked topic');
        };
        $res = (new AtlasLoopExternalResearchService(searchToolAvailable: true, backend: $backend))
            ->research("how does $real work", base_path());
        $this->assertTrue($res['blocked']);
        $this->assertFalse($res['researched']);
    }
}
