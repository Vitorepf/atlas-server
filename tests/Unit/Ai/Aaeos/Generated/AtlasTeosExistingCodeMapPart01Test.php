<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasTeosExistingCodeMapPart01Service;
use Tests\TestCase;

/**
 * Pins the documented TEOS Existing Code Map (Part 01) rules: the classification
 * taxonomy (only greenfield permits a new class), the five "Nao-greenfield" hard
 * blocks (each forbidden parallel routes to its existing reuse target + §14 rule),
 * the greenfield allow-list, the inventory lookup, and the "Regras para IA".
 *
 * @see docs/engineering-knowledge-base/atlas-teos-existing-code-map-part-01.md
 */
class AtlasTeosExistingCodeMapPart01Test extends TestCase
{
    private function service(): AtlasTeosExistingCodeMapPart01Service
    {
        return new AtlasTeosExistingCodeMapPart01Service();
    }

    /**
     * Classification taxonomy: reuse/extend/adapter/do_not_touch/deprecate_later
     * all FORBID a new parallel class; only greenfield and greenfield_optional
     * permit one. An unknown token is refused.
     */
    public function test_only_greenfield_classifications_allow_a_new_parallel_class(): void
    {
        $svc = $this->service();

        foreach (['reuse', 'extend', 'adapter', 'do_not_touch', 'deprecate_later'] as $token) {
            $row = $svc->classify($token);
            $this->assertTrue($row['known'], "{$token} must be a known classification");
            $this->assertFalse(
                $row['allows_new_class'],
                "{$token} must NOT allow creating a new parallel class"
            );
        }

        $this->assertTrue($svc->classify('greenfield')['allows_new_class']);
        $this->assertTrue($svc->classify('greenfield_optional')['allows_new_class']);

        $unknown = $svc->classify('totally-made-up-classification');
        $this->assertFalse($unknown['known']);
        $this->assertFalse($unknown['allows_new_class']);
    }

    /**
     * The core gate: each of the five documented "Nao-greenfield" proposals is
     * blocked and routed to its exact existing reuse target + §14 rule. Namespace
     * prefixes and human-readable spelling normalize to the same block.
     */
    public function test_the_five_non_greenfield_proposals_are_blocked_with_their_reuse_target(): void
    {
        $svc = $this->service();

        $compaction = $svc->evaluateProposal('App\\Services\\Ai\\LongHorizonCompactionEngine');
        $this->assertSame('blocked', $compaction['verdict']);
        $this->assertFalse($compaction['allowed']);
        $this->assertSame('AiCompactionService', $compaction['reuse_target']);
        $this->assertSame('sec14_rule_2', $compaction['anti_duplication_rule']);

        $ledger = $svc->evaluateProposal('Long Horizon Decision Ledger');
        $this->assertSame('blocked', $ledger['verdict']);
        $this->assertSame('sec14_rule_1', $ledger['anti_duplication_rule']);

        $memory = $svc->evaluateProposal('LongHorizonMemoryStore');
        $this->assertSame('blocked', $memory['verdict']);
        $this->assertSame('AtlasMemoryEntry::SCOPES', $memory['reuse_target']);
        $this->assertSame('sec14_rule_4', $memory['anti_duplication_rule']);

        // §14 r5: a proposed long-horizon measurement runner is blocked because
        // the existing readiness suite is the only path (it never creates a runner).
        $runner = $svc->evaluateProposal('LongHorizonMeasurementRunner');
        $this->assertSame('blocked', $runner['verdict']);
        $this->assertSame('sec14_rule_5', $runner['anti_duplication_rule']);

        $forge = $svc->evaluateProposal('LongHorizonForgeState');
        $this->assertSame('blocked', $forge['verdict']);
        $this->assertSame('sec14_rule_3', $forge['anti_duplication_rule']);
    }

    /**
     * The greenfield allow-list: the documented new components are authorized,
     * CausalDecisionGraph is the only one flagged optional in I1, and an unlisted
     * proposal must first search for an existing component (never an auto-yes).
     */
    public function test_greenfield_components_are_allowed_and_unlisted_proposals_need_a_search(): void
    {
        $svc = $this->service();

        $gate = $svc->evaluateProposal('LongHorizonContextFreshnessGate');
        $this->assertSame('allowed_greenfield', $gate['verdict']);
        $this->assertTrue($gate['allowed']);
        $this->assertFalse($gate['optional']);
        $this->assertSame('App\\Services\\Ai\\Programming\\LongHorizon', $gate['namespace']);

        $causal = $svc->evaluateProposal('CausalDecisionGraph');
        $this->assertSame('allowed_greenfield', $causal['verdict']);
        $this->assertTrue($causal['optional']);

        $unlisted = $svc->evaluateProposal('SomeBrandNewParallelService');
        $this->assertSame('needs_existing_component_search', $unlisted['verdict']);
        $this->assertFalse($unlisted['allowed']);
    }

    /**
     * Inventory lookup: AiCompactionService is `extend` (so no parallel class
     * allowed), MissionLifecycleService is `do_not_touch` with zero alteracao, and
     * an unknown component id is reported as a gap.
     */
    public function test_inventory_lookup_reports_classification_and_gaps(): void
    {
        $svc = $this->service();

        $compaction = $svc->lookupComponent('ai_compaction_service');
        $this->assertTrue($compaction['found']);
        $this->assertSame('extend', $compaction['classification']);
        $this->assertFalse($compaction['allows_new_class']);
        $this->assertTrue($compaction['forbids_parallel_class']);
        $this->assertSame('app/Services/Ai/AiCompactionService.php', $compaction['file']);

        $lifecycle = $svc->lookupComponent('mission_lifecycle_service');
        $this->assertSame('do_not_touch', $lifecycle['classification']);
        $this->assertSame('zero', $lifecycle['alteracao']);

        $gap = $svc->lookupComponent('ai_some_unmapped_thing');
        $this->assertFalse($gap['found']);
        $this->assertSame(
            'component_not_in_part01_inventory_register_as_gap_before_implementation',
            $gap['reason']
        );
    }

    /**
     * Regras para IA / forbidden_changes: declaring reuse without evidence,
     * creating a parallel component, or skipping the existing-component search each
     * fail; a clean proposal passes with the documented reason.
     */
    public function test_ai_rules_flag_violations_and_allow_a_clean_proposal(): void
    {
        $svc = $this->service();

        $this->assertFalse($svc->isProposalCompliant(['declares_reuse_without_evidence' => true]));
        $this->assertFalse($svc->isProposalCompliant(['creates_parallel_component' => true]));
        $this->assertFalse($svc->isProposalCompliant(['searched_existing_component_first' => false]));
        $this->assertFalse($svc->isProposalCompliant(['mixes_roadmap_with_inventory' => true]));

        $dirty = $svc->checkAiRules(['creates_parallel_component' => true]);
        $this->assertFalse($dirty['allowed']);
        $this->assertContains('parallel_component_creation_is_forbidden', $dirty['violations']);

        // Default facts (searched first, no parallel, with evidence) are clean.
        $clean = $svc->checkAiRules([]);
        $this->assertTrue($clean['allowed']);
        $this->assertSame('no_ai_rule_violated', $clean['reason']);
        $this->assertSame([], $clean['violations']);
    }
}
