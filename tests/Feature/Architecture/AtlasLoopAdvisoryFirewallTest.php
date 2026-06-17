<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * ARBOR-GRAFT FW1 — the advisory→gate firewall (the single highest-leverage floor protection).
 *
 * The Arbor graft introduces several ADVISORY surfaces that shape the NEXT draft but must NEVER
 * certify: the idea-tree narrative columns (node_insight / node_kind / tree_status / hypothesis on
 * AtlasLoopTarget), the constraints-block, the insight-backprop distillation, the operator steering
 * note, and the SELECT priority adjuster. Arbor's classic trust hazard is "insight-as-fact" — a
 * narrative quietly becoming a decision. A docblock that says "advisory only" is a PROMISE; this test
 * makes it an ENFORCED INVARIANT.
 *
 * Rule: no class on the certification / merge / trust spine may reference any advisory tree field or
 * advisory producer class. If a future careless `use` or `->node_insight` read sneaks a narrative into
 * a gate, this test fails closed — exactly the wall the whole graft's floor-safety rests on.
 *
 * Scanned tokens are leak-SPECIFIC (arrow-access of the narrative fields + the specific column literals
 * + the advisory producer class basenames) so the test has no false positives on generic words like
 * "depth"; the narrative columns are otherwise only reachable through the AtlasLoopIdeaTreeAccessor,
 * whose basename is itself a forbidden token here.
 */
class AtlasLoopAdvisoryFirewallTest extends TestCase
{
    /**
     * The certification / merge / trust spine — every authority that decides whether a change is REAL.
     * None of these may read an advisory tree field or depend on an advisory producer.
     *
     * @return list<string>
     */
    private function gateSpineFiles(): array
    {
        $candidates = [
            'Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
            'Services/Ai/AutonomousEvolution/AtlasLoopSemanticImplementationCertifier.php',
            'Services/Ai/AutonomousEvolution/AtlasLoopProposalPromotionGate.php',
            'Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php',
            'Services/Ai/AutonomousEvolution/AtlasLoopObraAutoMergeService.php',
            'Services/Ai/AutonomousEvolution/AtlasLoopProposalMaterializer.php',
            'Services/Ai/AutonomousEvolution/AtlasLoopNetDirectionGuard.php',
            'Services/Ai/AutonomousEvolution/AtlasLoopUtilityGradeService.php',
            'Services/Ai/Governance/AtlasChangeClassTrustLadder.php',
            'Services/Ai/AutonomousEvolution/Discovery/AtlasLoopTrustLadder.php',
            'Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AdversarialProofPanelService.php',
        ];

        $present = [];
        foreach ($candidates as $rel) {
            $abs = app_path($rel);
            if (is_file($abs)) {
                $present[] = $abs;
            }
        }

        return $present;
    }

    /**
     * Advisory tokens that must never appear in a gate-spine class.
     * Arrow-access forms catch a direct Eloquent read of the narrative attribute; column literals catch
     * a raw query/where on the column; class basenames catch a dependency on an advisory producer.
     *
     * @return list<string>
     */
    private function forbiddenAdvisoryTokens(): array
    {
        return [
            // narrative attribute reads (Eloquent arrow-access) — the insight-as-fact leak
            '->node_insight',
            '->node_kind',
            '->tree_status',
            '->hypothesis',
            // narrative column literals (raw query / where) — specific to this feature, no false positives
            "'node_insight'",
            '"node_insight"',
            "'node_kind'",
            '"node_kind"',
            "'tree_status'",
            '"tree_status"',
            // advisory producer classes — a gate must never depend on these
            'AtlasLoopIdeaTreeAccessor',
            'AtlasLoopConstraintsBlockAssembler',
            'AtlasLoopInsightBackpropService',
            'AtlasLoopSelectAdjuster',
            'AtlasLoopSteerCommand',
        ];
    }

    public function test_gate_spine_files_exist(): void
    {
        // Guard against a silent rename hollowing out the firewall: at least the core gates must resolve.
        $files = $this->gateSpineFiles();
        $this->assertNotEmpty($files, 'No gate-spine files resolved — the firewall would scan nothing.');
        $joined = implode('|', $files);
        $this->assertStringContainsString('AtlasEvolutionFrozenJudge.php', $joined);
        $this->assertStringContainsString('AtlasLoopSemanticImplementationCertifier.php', $joined);
    }

    public function test_no_gate_class_references_an_advisory_tree_field_or_producer(): void
    {
        $tokens = $this->forbiddenAdvisoryTokens();
        $violations = [];

        foreach ($this->gateSpineFiles() as $file) {
            $src = (string) file_get_contents($file);
            foreach ($tokens as $token) {
                if (str_contains($src, $token)) {
                    $violations[] = basename($file).' references advisory token '.$token;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Advisory→gate firewall breached. A certification/merge/trust class is reading a narrative ".
            "tree field or depending on an advisory producer — that turns 'never gates' from an invariant ".
            "into a violated promise:\n".implode("\n", $violations)
        );
    }
}
