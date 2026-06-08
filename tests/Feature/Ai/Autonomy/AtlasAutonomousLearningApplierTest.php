<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Autonomy;

use App\Models\AiLearningProposal;
use App\Services\Ai\Aaeos\Generated\AtlasLearningProposalsService;
use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use App\Services\Ai\Autonomy\AtlasAutonomousLearningApplier;
use App\Services\Ai\Compounding\AtlasLearningProposalApplier;
use App\Services\Ai\Compounding\AtlasLearningProposalService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Tests\TestCase;

/**
 * The fail-closed gate stack of the autonomous loop, proven at the decision boundary
 * (no DB): critical kinds default-deny even when they have a live applier; sensitive
 * and unclassified privacy always queue; only a clean, non-critical, non-sensitive,
 * well-evidenced proposal passes all four gates.
 */
class AtlasAutonomousLearningApplierTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    private function tmp(string $tag): string
    {
        $p = sys_get_temp_dir().'/atlas-auto-'.$tag.'-'.bin2hex(random_bytes(4)).'.jsonl';
        $this->tmp[] = $p;

        return $p;
    }

    private function consumer(): AtlasAutonomousLearningApplier
    {
        $kernel = new AtlasConstitutionalKernelService();
        $kernel->setViolationsLogPathForTesting($this->tmp('kernel'));
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->tmp('tickets'));

        return new AtlasAutonomousLearningApplier(
            new AtlasLearningProposalsService(),
            $admission,
            new AtlasLearningProposalService(),
            new AtlasLearningProposalApplier(new AtlasConductorRoutingMemory()),
        );
    }

    /**
     * @param  array<string,mixed>  $state
     */
    private function proposal(string $kind, array $state): AiLearningProposal
    {
        return (new AiLearningProposal())->forceFill(['kind' => $kind, 'status' => 'proposed', 'proposed_state' => $state]);
    }

    public function test_critical_kind_is_rejected_by_default_deny_even_with_a_live_applier(): void
    {
        // routing IS critical AND is the one kind with a live applier+reverser — the
        // consumer must STILL reject it (the guard is the allowlist, not applier-absence).
        $d = $this->consumer()->decide($this->proposal('routing', [
            'privacy_class' => 'internal', 'task_category' => 'x', 'role' => 'y', 'provider' => 'z',
        ]));

        $this->assertFalse($d['auto_apply']);
        $this->assertStringContainsString('kind_not_auto_applyable', $d['reason']);
    }

    public function test_policy_and_gate_kinds_are_rejected(): void
    {
        foreach (['policy', 'gate', 'heuristic', 'eval_gate', 'benchmark', 'documentation_health'] as $kind) {
            $d = $this->consumer()->decide($this->proposal($kind, ['privacy_class' => 'internal', 'claim' => 'x', 'body' => 'y']));
            $this->assertFalse($d['auto_apply'], "kind {$kind} must not auto-apply");
        }
    }

    public function test_sensitive_privacy_is_queued_never_applied(): void
    {
        foreach (['secret', 'cyber', 'sensitive'] as $p) {
            $d = $this->consumer()->decide($this->proposal('memory', ['privacy_class' => $p, 'claim' => 'x', 'body' => 'y']));
            $this->assertFalse($d['auto_apply'], "privacy {$p} must queue");
            $this->assertStringContainsString('privacy_', $d['reason']);
        }
    }

    public function test_unclassified_privacy_is_queued_fail_closed(): void
    {
        $d = $this->consumer()->decide($this->proposal('memory', ['claim' => 'x', 'body' => 'y']));

        $this->assertFalse($d['auto_apply']);
        $this->assertSame('privacy_unclassified', $d['reason']);
    }

    public function test_clean_non_critical_internal_with_evidence_passes_all_gates(): void
    {
        config(['atlas.ai.trust_ladder.enabled' => false]);

        $d = $this->consumer()->decide($this->proposal('failure_pattern', [
            'privacy_class' => 'normal',
            'claim' => 'retry transient db blips before parking the cycle',
            'body' => 'When a transient Postgres blip occurs, retry+reconnect before aborting.',
            'evidence_refs' => ['ev-1', 'ev-2', 'ev-3'],
            'sample_size' => 40,
            'effect_size' => 0.8,
        ]));

        $this->assertTrue($d['auto_apply'], 'clean internal non-critical with strong evidence should pass; got: '.$d['reason']);
    }

    public function test_no_critical_kind_is_ever_auto_applyable_in_either_taxonomy(): void
    {
        // Lock against a future taxonomy edit sneaking a critical kind into auto-apply:
        // the applier's auto-applyable set must be disjoint from BOTH critical tables.
        $applier = new AtlasLearningProposalApplier(new AtlasConductorRoutingMemory());
        $critical = array_unique(array_merge(
            \App\Services\Ai\Aaeos\Generated\AtlasLearningProposalsService::CRITICAL_KINDS,
            AtlasLearningProposalService::CRITICAL_KINDS,
        ));

        foreach ($critical as $kind) {
            $this->assertFalse($applier->supportsAutoApply($kind), "critical kind '{$kind}' must never be auto-applyable");
        }
    }

    public function test_disabled_by_default_applies_nothing(): void
    {
        $report = $this->consumer()->run(10);

        $this->assertFalse($report['enabled']);
        $this->assertSame(0, $report['applied']);
        $this->assertSame(0, $report['queued']);
    }
}
