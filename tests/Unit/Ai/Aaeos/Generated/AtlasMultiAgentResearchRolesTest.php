<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasMultiAgentResearchRolesService;
use Tests\TestCase;

/**
 * Pins the documented Atlas AI Multi-Agent Research Roles contract:
 *   - the 12 canonical roles as a closed set;
 *   - the Artifact Rule (every active role must persist >= 1 artifact);
 *   - the four Anti-Duplication invariants (A1..A4);
 *   - the critical-report Promotion quorum and the status ceiling that applies
 *     when it is not met ("remains draft or low-risk summary").
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/multi-agent-research-roles.md
 */
class AtlasMultiAgentResearchRolesTest extends TestCase
{
    private function service(): AtlasMultiAgentResearchRolesService
    {
        return new AtlasMultiAgentResearchRolesService();
    }

    /** The role roster is exactly the 12 documented roles, no more, no less. */
    public function test_roles_are_the_twelve_documented_closed_set(): void
    {
        $svc = $this->service();
        $roles = $svc->roles();

        $this->assertCount(12, $roles);
        $this->assertArrayHasKey(AtlasMultiAgentResearchRolesService::ROLE_RESEARCH_DIRECTOR, $roles);
        $this->assertArrayHasKey(AtlasMultiAgentResearchRolesService::ROLE_RED_TEAM_AGENT, $roles);
        $this->assertTrue($svc->isRole(AtlasMultiAgentResearchRolesService::ROLE_CITATION_AUDITOR));
        $this->assertFalse($svc->isRole('marketing_agent'));
    }

    /** Artifact Rule: a role active with zero artifacts breaks compliance. */
    public function test_artifact_rule_flags_role_with_no_artifact(): void
    {
        $svc = $this->service();

        $ok = $svc->assessArtifacts([
            AtlasMultiAgentResearchRolesService::ROLE_SOURCE_SCOUT => ['source_candidates'],
            AtlasMultiAgentResearchRolesService::ROLE_CLAIM_VERIFIER => ['claims'],
        ]);
        $this->assertTrue($ok['compliant']);
        $this->assertSame([], $ok['roles_missing_artifacts']);

        $bad = $svc->assessArtifacts([
            AtlasMultiAgentResearchRolesService::ROLE_SOURCE_SCOUT => ['source_candidates'],
            AtlasMultiAgentResearchRolesService::ROLE_SYNTHESIS_WRITER => [], // no artifact
        ]);
        $this->assertFalse($bad['compliant']);
        $this->assertSame(
            [AtlasMultiAgentResearchRolesService::ROLE_SYNTHESIS_WRITER],
            $bad['roles_missing_artifacts'],
        );
    }

    /**
     * Anti-Duplication: A1 catches re-issuing an already-declared search; A3
     * catches the Synthesis Writer citing a source absent from the artifacts;
     * A4 catches the Red Team writing the final report.
     */
    public function test_anti_duplication_catches_a1_a3_a4_violations(): void
    {
        $svc = $this->service();

        $clean = $svc->assessAntiDuplication([
            'declared_searched' => ['q:eval', 'src:arxiv'],
            'reissued_searches' => [],
            'artifact_sources' => ['src:arxiv'],
            'writer_cited_sources' => ['src:arxiv'],
            'red_team_wrote_report' => false,
        ]);
        $this->assertTrue($clean['ok']);
        $this->assertSame([], $clean['violations']);

        $dirty = $svc->assessAntiDuplication([
            'declared_searched' => ['q:eval', 'src:arxiv'],
            'reissued_searches' => ['q:eval'], // A1: duplicate
            'artifact_sources' => ['src:arxiv'],
            'writer_cited_sources' => ['src:invented'], // A3: invented source
            'red_team_wrote_report' => true, // A4: red team wrote report
        ]);
        $this->assertFalse($dirty['ok']);
        $this->assertcontains('A1_declare_searched', $dirty['violations']);
        $this->assertContains('A3_writer_no_invented_sources', $dirty['violations']);
        $this->assertContains('A4_red_team_emits_findings_only', $dirty['violations']);
        $this->assertSame(['q:eval'], $dirty['rules']['A1_declare_searched']['duplicate_searches']);
        $this->assertSame(['src:invented'], $dirty['rules']['A3_writer_no_invented_sources']['invented_sources']);
    }

    /** Anti-Duplication A2: only the Source Scout may introduce new sources. */
    public function test_anti_duplication_a2_scout_owns_discovery(): void
    {
        $svc = $this->service();

        $bad = $svc->assessAntiDuplication([
            'new_sources_by_role' => [
                AtlasMultiAgentResearchRolesService::ROLE_SOURCE_SCOUT => ['src:ok'],
                AtlasMultiAgentResearchRolesService::ROLE_WEB_AGENT => ['src:smuggled'],
            ],
            'scout_support_verdicts' => 2, // Scout must not stamp support verdicts
        ]);
        $this->assertFalse($bad['rules']['A2_scout_owns_discovery']['ok']);
        $this->assertArrayHasKey(
            AtlasMultiAgentResearchRolesService::ROLE_WEB_AGENT,
            $bad['rules']['A2_scout_owns_discovery']['non_scout_new_sources'],
        );
        $this->assertSame(2, $bad['rules']['A2_scout_owns_discovery']['scout_support_verdicts']);
    }

    /**
     * Promotion Rule: a critical report missing a guard role is capped at draft;
     * missing the Research Director drops it to a low-risk summary; the full
     * quorum makes it promotable. A non-critical report is never gated.
     */
    public function test_promotion_quorum_caps_status_when_incomplete(): void
    {
        $svc = $this->service();
        $quorum = $svc->criticalQuorum();

        // Full quorum -> promotable.
        $full = $svc->assessPromotion($quorum, true);
        $this->assertTrue($full['quorum_met']);
        $this->assertSame(AtlasMultiAgentResearchRolesService::STATUS_PROMOTABLE, $full['allowed_status']);

        // Missing only the Citation Auditor -> capped at draft.
        $noAuditor = array_values(array_filter(
            $quorum,
            fn (string $r) => $r !== AtlasMultiAgentResearchRolesService::ROLE_CITATION_AUDITOR,
        ));
        $draft = $svc->assessPromotion($noAuditor, true);
        $this->assertFalse($draft['quorum_met']);
        $this->assertSame(AtlasMultiAgentResearchRolesService::STATUS_DRAFT, $draft['allowed_status']);
        $this->assertSame(
            [AtlasMultiAgentResearchRolesService::ROLE_CITATION_AUDITOR],
            $draft['missing_quorum_roles'],
        );

        // Missing the Research Director -> low-risk summary ceiling.
        $noDirector = array_values(array_filter(
            $quorum,
            fn (string $r) => $r !== AtlasMultiAgentResearchRolesService::ROLE_RESEARCH_DIRECTOR,
        ));
        $low = $svc->assessPromotion($noDirector, true);
        $this->assertSame(
            AtlasMultiAgentResearchRolesService::STATUS_LOW_RISK_SUMMARY,
            $low['allowed_status'],
        );

        // Non-critical report is not gated even with no roles at all.
        $nonCritical = $svc->assessPromotion([], false);
        $this->assertSame(AtlasMultiAgentResearchRolesService::STATUS_PROMOTABLE, $nonCritical['allowed_status']);
    }

    /** The roll-up holds the run when any rule family fails, promotes when clean. */
    public function test_assess_rollup_promotes_clean_run_and_holds_on_blocker(): void
    {
        $svc = $this->service();

        $clean = $svc->assess($svc->demoRun());
        $this->assertSame('promote', $clean['decision']);
        $this->assertSame(AtlasMultiAgentResearchRolesService::STATUS_PROMOTABLE, $clean['allowed_status']);
        $this->assertSame([], $clean['blockers']);

        // Drop the Red Team from a critical run -> promotion quorum blocker.
        $run = $svc->demoRun();
        $run['active_roles'] = array_values(array_filter(
            (array) $run['active_roles'],
            fn ($r) => $r !== AtlasMultiAgentResearchRolesService::ROLE_RED_TEAM_AGENT,
        ));
        $held = $svc->assess($run);
        $this->assertSame('hold', $held['decision']);
        $this->assertContains('promotion_quorum', $held['blockers']);
    }
}
