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

    public function test_a_clean_topic_with_a_tool_researches_advisory_only(): void
    {
        $res = (new AtlasLoopExternalResearchService(searchToolAvailable: true))->research('constraint theory in software delivery', base_path());
        $this->assertFalse($res['blocked']);
        $this->assertTrue($res['researched']);
        $this->assertNotNull($res['note']);
    }
}
