<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry;

use App\Services\Ai\Foundry\FoundryEvidenceVerifierService;
use Tests\TestCase;

/**
 * Finding 18 (P2-VERIFY-CLAIMVALUE): the blocker_count / plan_completion gate
 * anchors previously passed on a real green owner receipt WITHOUT comparing the
 * anchor's numeric claim to the owner-derived value, so a fabricated count
 * (delivered=999) attached to a real cycle passed.
 *
 * These tests prove the additive claim_value_mismatch refutation AND that the
 * pre-existing checkGate refute branches still fire (gate is no weaker).
 */
final class FoundryEvidenceVerifierClaimValueTest extends TestCase
{
    private string $repoDir;

    private string $rejectionDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repoDir = sys_get_temp_dir().'/foundry_cv_repo_'.uniqid('', true);
        @mkdir($this->repoDir, 0775, true);
        $this->rejectionDir = sys_get_temp_dir().'/foundry_cv_rej_'.uniqid('', true);
        @mkdir($this->rejectionDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->repoDir);
        $this->rmrf($this->rejectionDir);
        parent::tearDown();
    }

    private function service(): FoundryEvidenceVerifierService
    {
        $svc = app(FoundryEvidenceVerifierService::class);
        $svc->setRepoRootForTesting($this->repoDir);
        $svc->setRejectionStorageDirForTesting($this->rejectionDir);

        return $svc;
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir.'/*') as $entry) {
            is_dir($entry) ? $this->rmrf($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    /**
     * A real green merged cycle delivering exactly one changed file.
     *
     * @return array<string,mixed>
     */
    private function healthyCycle(): array
    {
        return [
            'cycle_id' => 'cycle-merged-1',
            'session_id' => 'sess-1',
            'final_status' => 'merged',
            'merge_performed' => true,
            'selected_finding' => ['finding_id' => 'f1', 'title' => 'A real modest finding'],
            'inbox_item_id' => 'inbox-1',
            'result_bridge_id' => 'bridge-1',
            'inbox_emitted_before_merge_attempt' => true,
            'changed_files' => ['app/Foo.php'],
            'validation' => ['status' => 'passed', 'passed' => true, 'commands' => ['php artisan test']],
            'merge_governance' => ['status' => 'merged', 'merge_commit' => 'abc1234'],
            'evidence_refs' => ['evidence/pack-1.json'],
            'owner_flow' => ['provider_router_used' => false],
        ];
    }

    // ---------- ACCEPTANCE: fabricated count on a real green cycle ----------

    public function test_plan_completion_claim_999_on_real_green_cycle_is_refuted(): void
    {
        $svc = $this->service();

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'plan_completion',
            'anchor_claim' => 999,
            'source_path' => 'plan_completion',
        ], ['cycle' => $this->healthyCycle()]);

        $this->assertSame('refuted', $verdict['verdict']);
        $this->assertSame('claim_value_mismatch', $verdict['drop_reason']);

        // The pre-existing owner-receipt gate still PASSED first (no weakening):
        // every prior check is present and clean before claim_matches_owner fails.
        $names = array_column($verdict['checks'], 'name');
        foreach (['provider_router', 'validation', 'evidence_refs', 'pre_merge_inbox', 'merge_hash', 'integrity'] as $expected) {
            $this->assertContains($expected, $names);
        }
        $claimCheck = $this->checkByName($verdict['checks'], 'claim_matches_owner');
        $this->assertSame('fail', $claimCheck['result']);
    }

    public function test_blocker_count_claim_mismatch_is_refuted(): void
    {
        $svc = $this->service();
        $cycle = $this->healthyCycle();
        // Owner-derived blocked_reasons length is 0 on a clean merged cycle.

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'blocker_count',
            'anchor_claim' => 7,
            'source_path' => 'blocker_count',
        ], ['cycle' => $cycle]);

        $this->assertSame('refuted', $verdict['verdict']);
        $this->assertSame('claim_value_mismatch', $verdict['drop_reason']);
    }

    // ---------- ACCEPTANCE: matching claim still passes ----------

    public function test_plan_completion_claim_matching_owner_value_passes(): void
    {
        $svc = $this->service();

        // healthyCycle is merged with 1 changed file => owner delivered = 1.
        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'plan_completion',
            'anchor_claim' => 1,
            'source_path' => 'plan_completion',
        ], ['cycle' => $this->healthyCycle()]);

        if ($verdict['verdict'] !== 'confirmed') {
            $this->fail('expected confirmed, drop='.$verdict['drop_reason'].' checks='.json_encode($verdict['checks']));
        }
        $claimCheck = $this->checkByName($verdict['checks'], 'claim_matches_owner');
        $this->assertSame('pass', $claimCheck['result']);
    }

    public function test_plan_completion_rollup_override_drives_owner_value(): void
    {
        $svc = $this->service();

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'plan_completion',
            'anchor_claim' => 5,
            'source_path' => 'plan_completion',
        ], ['cycle' => $this->healthyCycle(), 'rollup' => ['delivered' => 5]]);

        $this->assertSame('confirmed', $verdict['verdict']);
    }

    public function test_blocker_count_claim_matching_owner_value_passes(): void
    {
        $svc = $this->service();
        $cycle = $this->healthyCycle();
        $cycle['blocked_reasons'] = ['awaiting_owner_decision', 'budget_pause'];

        // owner-derived blocked_reasons length = 2.
        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'blocker_count',
            'anchor_claim' => 2,
            'source_path' => 'blocker_count',
        ], ['cycle' => $cycle]);

        $this->assertSame('confirmed', $verdict['verdict']);
    }

    public function test_non_numeric_claim_is_refuted_not_coerced(): void
    {
        $svc = $this->service();

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'plan_completion',
            'anchor_claim' => 'lots',
            'source_path' => 'plan_completion',
        ], ['cycle' => $this->healthyCycle()]);

        $this->assertSame('refuted', $verdict['verdict']);
        $this->assertSame('claim_value_mismatch', $verdict['drop_reason']);
    }

    // ---------- NO WEAKENING: all pre-existing refute branches still fire ----------

    public function test_provider_router_branch_still_fires(): void
    {
        $svc = $this->service();
        $cycle = $this->healthyCycle();
        $cycle['owner_flow']['provider_router_used'] = true;

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'plan_completion',
            'anchor_claim' => 1,
            'source_path' => 'plan_completion',
        ], ['cycle' => $cycle]);

        $this->assertSame('provider_router_used', $verdict['drop_reason']);
    }

    public function test_validation_branch_still_fires(): void
    {
        $svc = $this->service();
        $cycle = $this->healthyCycle();
        $cycle['validation'] = ['status' => 'failed', 'passed' => false, 'commands' => ['php artisan test']];

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'plan_completion',
            'anchor_claim' => 1,
            'source_path' => 'plan_completion',
        ], ['cycle' => $cycle]);

        $this->assertSame('validation_not_passed', $verdict['drop_reason']);
    }

    public function test_evidence_refs_branch_still_fires(): void
    {
        $svc = $this->service();
        $cycle = $this->healthyCycle();
        unset($cycle['inbox_item_id'], $cycle['result_bridge_id'], $cycle['evidence_refs']);
        $cycle['owner_flow'] = ['provider_router_used' => false];

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'blocker_count',
            'anchor_claim' => 0,
            'source_path' => 'blocker_count',
        ], ['cycle' => $cycle]);

        $this->assertSame('evidence_refs_empty', $verdict['drop_reason']);
    }

    public function test_cycle_id_not_found_branch_still_fires(): void
    {
        $svc = $this->service();

        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'plan_completion',
            'anchor_claim' => 1,
            'source_path' => 'plan_completion',
        ], ['gate_cycle_id' => 'nope', 'cycles' => []]);

        $this->assertSame('cycle_id_not_found', $verdict['drop_reason']);
    }

    public function test_merge_hash_anchor_unaffected_by_claim_value_gate(): void
    {
        $svc = $this->service();

        // merge_hash anchors keep their prior semantics: the claim-value
        // comparison must NOT apply (no weakening, no new false refute).
        $verdict = $svc->verifyAnchor([
            'anchor_type' => 'merge_hash',
            'anchor_claim' => 'cycle-merged-1',
            'source_path' => 'merge_hash',
        ], ['cycle' => $this->healthyCycle()]);

        $this->assertSame('confirmed', $verdict['verdict']);
        $names = array_column($verdict['checks'], 'name');
        $this->assertNotContains('claim_matches_owner', $names);
    }

    public function test_mismatch_is_recorded_to_rejection_ledger(): void
    {
        $svc = $this->service();

        $svc->verifyAnchor([
            'anchor_type' => 'plan_completion',
            'anchor_claim' => 999,
            'source_path' => 'plan_completion',
        ], ['cycle' => $this->healthyCycle(), 'area_id' => 'agentic_engineering_os']);

        $slug = 'agentic_engineering_os';
        $path = $this->rejectionDir.'/'.$slug.'/false_anchor_rejections.jsonl';
        $this->assertFileExists($path);
        $lines = array_values(array_filter(array_map(
            static fn (string $l): ?array => trim($l) === '' ? null : json_decode($l, true),
            file($path) ?: [],
        )));
        $this->assertSame('claim_value_mismatch', $lines[0]['drop_reason']);
        $this->assertSame('999', $lines[0]['claimed_value']);
    }

    public function test_determinism_identical_inputs_identical_hash(): void
    {
        $svc = $this->service();
        $anchor = [
            'anchor_id' => 'cv-det',
            'anchor_type' => 'plan_completion',
            'anchor_claim' => 999,
            'source_path' => 'plan_completion',
        ];

        $a = $svc->verifyAnchor($anchor, ['cycle' => $this->healthyCycle()]);
        $b = $svc->verifyAnchor($anchor, ['cycle' => $this->healthyCycle()]);

        $this->assertSame($a['verification_hash'], $b['verification_hash']);
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,mixed>
     */
    private function checkByName(array $checks, string $name): array
    {
        foreach ($checks as $check) {
            if (($check['name'] ?? '') === $name) {
                return $check;
            }
        }
        $this->fail("check {$name} not present");
    }
}
