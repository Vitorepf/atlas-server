<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopRealWorkScorecardService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * REAL-WORK CAMPAIGN SCORECARD · C0 — the honesty ruler's frozen contract.
 *
 * These tests pin the ONE thing C0 must guarantee: a campaign can never claim "produced real work" unless
 * the task classification proves it — at least one real-work task, zero cosmetic, proxy not dominating,
 * nothing unclassifiable. The dangerous direction is a FALSE POSITIVE (claiming real when it is proxy /
 * cosmetic), so every blocker is asserted by name and the success path is the narrow, earned case.
 */
final class AtlasLoopRealWorkScorecardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        if (! Schema::hasTable('atlas_loop_pipeline_state')) {
            (require base_path('database/migrations/2026_06_18_000100_create_atlas_loop_pipeline_state.php'))->up();
        }
    }

    private function service(): AtlasLoopRealWorkScorecardService
    {
        return app(AtlasLoopRealWorkScorecardService::class);
    }

    private function makeCampaign(string $goal = 'c0-scorecard-test'): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => $goal,
            'config' => [],
            'max_seconds' => 60,
            'objective' => $goal,
            'status' => 'running',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function makeTask(string $campaignId, array $payload, string $objective = 'an objective'): AtlasLoopTask
    {
        return AtlasLoopTask::query()->create([
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => 'pending',
            'source' => 'discovery',
            'self_contained' => false,
            'target_path' => 'app/Services/X/Foo.php',
            'objective' => $objective,
            'payload' => $payload,
            'priority' => 0,
            'max_attempts' => 1,
            'dedupe_key' => 'dk-'.bin2hex(random_bytes(5)),
        ]);
    }

    /** A canonical Claude-B real bug_fix payload. */
    private function bugFixPayload(): array
    {
        return [
            'objective_kind' => 'bug_fix',
            'revert_recheck' => true,
            'acceptance' => [
                'red_required' => true,
                'commands' => ['./vendor/bin/phpunit --filter SomeFailingTest'],
            ],
        ];
    }

    /** A canonical behaviour-preserving proxy refactor payload. */
    private function proxyRefactorPayload(): array
    {
        return [
            'objective_kind' => 'refactor_reduce_complexity',
            'revert_recheck' => false,
            'acceptance' => ['commands' => ['./vendor/bin/phpunit --filter Frozen']],
        ];
    }

    // ── empty campaign ────────────────────────────────────────────────────────

    public function test_empty_campaign_refuses_claim_with_no_tasks_blocker(): void
    {
        $campaign = $this->makeCampaign();

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(AtlasLoopRealWorkScorecardService::SCHEMA_VERSION, $sc['schema_version']);
        $this->assertSame(0, $sc['tasks_total']);
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
        $this->assertContains('no_tasks_observed', $sc['claim_policy']['blockers']);
    }

    // ── one real bug_fix ──────────────────────────────────────────────────────

    public function test_single_real_bug_fix_allows_claim(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, $this->bugFixPayload());

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(1, $sc['tasks_total']);
        $this->assertSame(1, $sc['real_work_tasks']);
        $this->assertSame(1, $sc['bug_fix_tasks']);
        $this->assertSame(0, $sc['proxy_refactor_tasks']);
        $this->assertSame(0, $sc['cosmetic_tasks']);
        $this->assertSame(0, $sc['unknown_tasks']);
        $this->assertSame(1.0, $sc['real_work_ratio']);
        $this->assertTrue($sc['claim_policy']['loop_real_work_claim_allowed']);
        $this->assertSame([], $sc['claim_policy']['blockers']);
    }

    public function test_red_required_alone_counts_as_real_bug_fix(): void
    {
        $campaign = $this->makeCampaign();
        // No objective_kind, but a RED-required acceptance command = behaviour-changing real work.
        $this->makeTask($campaign->id, [
            'acceptance' => ['red_required' => true, 'commands' => ['./vendor/bin/phpunit --filter X']],
        ]);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(1, $sc['real_work_tasks']);
        $this->assertSame(1, $sc['bug_fix_tasks']);
        $this->assertTrue($sc['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_feature_objective_counts_as_real_feature(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, [
            'objective_kind' => 'feature_add',
            'acceptance' => ['commands' => ['./vendor/bin/phpunit --filter NewFeature']],
        ]);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(1, $sc['real_work_tasks']);
        $this->assertSame(1, $sc['feature_tasks']);
        $this->assertSame(0, $sc['bug_fix_tasks']);
        $this->assertTrue($sc['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_characterization_test_with_acceptance_counts_as_verification(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, [
            'objective_kind' => 'characterization_test',
            'acceptance' => ['commands' => ['./vendor/bin/phpunit --filter Characterize']],
        ]);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(1, $sc['real_work_tasks']);
        $this->assertSame(1, $sc['verification_tasks']);
        $this->assertTrue($sc['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_characterization_test_without_acceptance_is_unknown(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, ['objective_kind' => 'characterization_test']);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(0, $sc['real_work_tasks']);
        $this->assertSame(1, $sc['unknown_tasks']);
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
        $this->assertContains('unknown_work_observed', $sc['claim_policy']['blockers']);
    }

    // ── proxy vs real ─────────────────────────────────────────────────────────

    public function test_one_proxy_and_one_bug_fix_refuses_claim_when_proxy_ties_real(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, $this->bugFixPayload());
        $this->makeTask($campaign->id, $this->proxyRefactorPayload());

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(2, $sc['tasks_total']);
        $this->assertSame(1, $sc['real_work_tasks']);
        $this->assertSame(1, $sc['proxy_refactor_tasks']);
        // Operator rule for the short C0 campaign: proxy >= real_work ⇒ claim REFUSED.
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
        $this->assertContains('proxy_refactor_dominates', $sc['claim_policy']['blockers']);
    }

    public function test_proxy_dominating_refuses_claim(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, $this->bugFixPayload());
        $this->makeTask($campaign->id, $this->proxyRefactorPayload());
        $this->makeTask($campaign->id, $this->proxyRefactorPayload());

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(1, $sc['real_work_tasks']);
        $this->assertSame(2, $sc['proxy_refactor_tasks']);
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
        $this->assertContains('proxy_refactor_dominates', $sc['claim_policy']['blockers']);
    }

    public function test_real_work_dominating_proxy_allows_claim(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, $this->bugFixPayload());
        $this->makeTask($campaign->id, $this->bugFixPayload());
        $this->makeTask($campaign->id, $this->proxyRefactorPayload());

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(2, $sc['real_work_tasks']);
        $this->assertSame(1, $sc['proxy_refactor_tasks']);
        $this->assertTrue($sc['claim_policy']['loop_real_work_claim_allowed']);
        $this->assertSame([], $sc['claim_policy']['blockers']);
    }

    // ── cosmetic ──────────────────────────────────────────────────────────────

    public function test_cosmetic_flag_refuses_claim_even_with_real_work(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, $this->bugFixPayload());
        $this->makeTask($campaign->id, ['objective_kind' => 'refactor_extract_class', 'cosmetic' => true]);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(1, $sc['cosmetic_tasks']);
        $this->assertSame(1, $sc['real_work_tasks']);
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
        $this->assertContains('cosmetic_work_observed', $sc['claim_policy']['blockers']);
    }

    public function test_cosmetic_takes_precedence_over_real_signal(): void
    {
        $campaign = $this->makeCampaign();
        // A contradictory payload: claims bug_fix + red_required BUT also cosmetic=true. The conservative,
        // anti-Goodhart direction classifies it cosmetic (the blocker wins) — never laundered as real.
        $this->makeTask($campaign->id, [
            'objective_kind' => 'bug_fix',
            'cosmetic' => true,
            'acceptance' => ['red_required' => true, 'commands' => ['x']],
        ]);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(0, $sc['real_work_tasks']);
        $this->assertSame(1, $sc['cosmetic_tasks']);
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
        $this->assertContains('cosmetic_work_observed', $sc['claim_policy']['blockers']);
    }

    public function test_whitespace_only_objective_text_without_acceptance_is_cosmetic(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, ['objective_kind' => 'refactor'], 'fix trailing whitespace and reformatting in the file');

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(1, $sc['cosmetic_tasks']);
        $this->assertSame(0, $sc['proxy_refactor_tasks']);
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
        $this->assertContains('cosmetic_work_observed', $sc['claim_policy']['blockers']);
    }

    public function test_pattern_rejection_reason_naming_cosmetic_is_cosmetic(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, [
            'objective_kind' => 'refactor',
            'pattern' => [
                'selected' => null,
                'rejected' => true,
                'reason' => 'Cosmetic / behaviour-preserving / proxy work earns zero leverage.',
            ],
        ]);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(1, $sc['cosmetic_tasks']);
        $this->assertContains('cosmetic_work_observed', $sc['claim_policy']['blockers']);
    }

    // ── unknown ───────────────────────────────────────────────────────────────

    public function test_empty_payload_is_unknown_and_refuses_claim(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, []);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(1, $sc['unknown_tasks']);
        $this->assertSame(0, $sc['real_work_tasks']);
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
        $this->assertContains('no_real_work_tasks', $sc['claim_policy']['blockers']);
        $this->assertContains('unknown_work_observed', $sc['claim_policy']['blockers']);
    }

    // ── partition invariant ───────────────────────────────────────────────────

    public function test_buckets_partition_tasks_total(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, $this->bugFixPayload());
        $this->makeTask($campaign->id, $this->proxyRefactorPayload());
        $this->makeTask($campaign->id, ['cosmetic' => true]);
        $this->makeTask($campaign->id, []);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(4, $sc['tasks_total']);
        $this->assertSame(
            $sc['tasks_total'],
            $sc['real_work_tasks'] + $sc['proxy_refactor_tasks'] + $sc['cosmetic_tasks'] + $sc['unknown_tasks'],
            'every task lands in exactly one bucket'
        );
        // sub-kinds partition real_work
        $this->assertSame(
            $sc['real_work_tasks'],
            $sc['bug_fix_tasks'] + $sc['feature_tasks'] + $sc['verification_tasks'],
        );
    }

    // ── pattern signals (optional, must not break when absent) ─────────────────

    public function test_pattern_mode_signals_are_counted_and_absence_does_not_break(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, $this->bugFixPayload() + ['pattern' => ['mode' => 'driver', 'selected' => 'tdd_red_green']]);
        $this->makeTask($campaign->id, $this->bugFixPayload() + ['pattern' => ['mode' => 'advisory', 'selected' => 'bounded_change']]);
        $this->makeTask($campaign->id, $this->bugFixPayload()); // no pattern metadata at all ⇒ missing

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(1, $sc['pattern_signals']['pattern_driver_tasks']);
        $this->assertSame(1, $sc['pattern_signals']['pattern_advisory_tasks']);
        $this->assertSame(1, $sc['pattern_signals']['pattern_missing_tasks']);
        $this->assertSame(['bounded_change', 'tdd_red_green'], $sc['pattern_signals']['patterns_selected']);
        // driver+advisory+missing partitions tasks_total
        $this->assertSame(
            $sc['tasks_total'],
            $sc['pattern_signals']['pattern_driver_tasks']
                + $sc['pattern_signals']['pattern_advisory_tasks']
                + $sc['pattern_signals']['pattern_missing_tasks'],
        );
    }

    public function test_no_pattern_metadata_yields_zero_driver_and_advisory(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, $this->bugFixPayload());

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(0, $sc['pattern_signals']['pattern_driver_tasks']);
        $this->assertSame(0, $sc['pattern_signals']['pattern_advisory_tasks']);
        $this->assertSame(1, $sc['pattern_signals']['pattern_missing_tasks']);
        $this->assertSame([], $sc['pattern_signals']['patterns_selected']);
    }

    // ── adversarial regressions (holes found + closed by the verify pass) ──────

    public function test_feature_kind_without_acceptance_is_unknown_not_real(): void
    {
        // Feature, like verification, requires a concrete acceptance contract — an empty feature stub is
        // NOT proven real work (anti-laundering: a bare 'feature_add' label cannot mint a real claim).
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, ['objective_kind' => 'feature_add']);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(0, $sc['real_work_tasks']);
        $this->assertSame(0, $sc['feature_tasks']);
        $this->assertSame(1, $sc['unknown_tasks']);
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_featurelike_kinds_do_not_match_the_feature_lane(): void
    {
        // 'featured' / 'featureless_refactor' must NOT be classified feature — the lane matches the proper
        // `feature` / `feature_*` / `feature-*` token, never a bare 7-char prefix.
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, [
            'objective_kind' => 'featured',
            'acceptance' => ['commands' => ['./vendor/bin/phpunit --filter X']],
        ]);
        $this->makeTask($campaign->id, [
            'objective_kind' => 'featureless_refactor',
            'acceptance' => ['commands' => ['./vendor/bin/phpunit --filter Y']],
        ]);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(0, $sc['feature_tasks']);
        $this->assertSame(0, $sc['real_work_tasks'], 'neither featurelike kind is real work');
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_bare_feature_flag_does_not_launder_proxy_refactor(): void
    {
        // A behaviour-preserving refactor with a bolted-on `feature:true` flag must stay PROXY, never REAL.
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, [
            'objective_kind' => 'refactor_extract_method',
            'feature' => true,
            'acceptance' => ['commands' => ['./vendor/bin/phpunit --filter Frozen']],
        ]);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(0, $sc['real_work_tasks']);
        $this->assertSame(1, $sc['proxy_refactor_tasks']);
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_revert_recheck_with_concrete_acceptance_alone_is_real_bug_fix(): void
    {
        // Pins the spec's distinct REAL trigger in isolation (no objective_kind, no red_required).
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, [
            'revert_recheck' => true,
            'acceptance' => ['commands' => ['./vendor/bin/phpunit --filter Repro']],
        ]);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(1, $sc['real_work_tasks']);
        $this->assertSame(1, $sc['bug_fix_tasks']);
        $this->assertTrue($sc['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_acceptance_revert_recheck_alias_counts_as_real(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, [
            'acceptance' => ['revert_recheck' => true, 'commands' => ['./vendor/bin/phpunit --filter Alias']],
        ]);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(1, $sc['real_work_tasks']);
        $this->assertSame(1, $sc['bug_fix_tasks']);
        $this->assertTrue($sc['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_revert_recheck_without_acceptance_on_refactor_is_not_real(): void
    {
        // The dangerous false-positive direction: a self-asserted revert_recheck on a refactor WITHOUT a
        // runnable acceptance command must NOT be laundered into real work.
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, ['objective_kind' => 'refactor_x', 'revert_recheck' => true]);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(0, $sc['real_work_tasks']);
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_noop_command_does_not_satisfy_concrete_acceptance(): void
    {
        // A no-op placeholder command (`:`) is NOT a concrete acceptance contract, so it cannot suppress the
        // cosmetic-text guard nor satisfy the revert_recheck real lane.
        $campaign = $this->makeCampaign();
        // verification kind + no-op command + cosmetic text ⇒ cosmetic (not real verification).
        $this->makeTask($campaign->id, [
            'objective_kind' => 'verification',
            'acceptance' => ['command' => ':'],
        ], 'remove trailing whitespace and reformat comments only');
        // refactor + revert_recheck + no-op command + cosmetic text ⇒ cosmetic (not real bug_fix).
        $this->makeTask($campaign->id, [
            'objective_kind' => 'refactor_x',
            'revert_recheck' => true,
            'acceptance' => ['command' => ':'],
        ], 'fix typo in comment, whitespace cleanup');

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(0, $sc['real_work_tasks'], 'no-op commands never mint real work');
        $this->assertSame(2, $sc['cosmetic_tasks']);
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
        $this->assertContains('cosmetic_work_observed', $sc['claim_policy']['blockers']);
    }

    /**
     * @return array<int,array{0:string}>
     */
    public static function noOpCommandProvider(): array
    {
        return [
            ['true'],            // literal no-op present in real loop data
            ['true && true'],    // compound no-op
            [':; true'],         // separator-joined no-ops
            ['exit  0'],         // double-space defeats a naive 'exit 0' literal
            ['sleep 0'],
            ['pwd'],
            ['cat /dev/null'],
            ['/usr/bin/true'],
            ['echo ok'],
        ];
    }

    #[DataProvider('noOpCommandProvider')]
    public function test_noop_command_variants_never_mint_real_work(string $command): void
    {
        // A feature lane is the cleanest single-task path to claim=true, so prove a no-op acceptance command
        // there is NOT a concrete contract: the task degrades to unknown and the claim is refused.
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, [
            'objective_kind' => 'feature_add',
            'acceptance' => ['commands' => [$command]],
        ], 'add a new capability');

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(0, $sc['real_work_tasks'], "no-op command '{$command}' must not mint real work");
        $this->assertSame(0, $sc['feature_tasks']);
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_real_php_test_file_command_is_a_concrete_contract(): void
    {
        // The loop's DOMINANT real acceptance shape (`php tests/<file>.php`) MUST be recognised as concrete —
        // the allow-list closes the no-op hole without rejecting the loop's own commands.
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, [
            'objective_kind' => 'feature_add',
            'acceptance' => ['commands' => ['php tests/atlas_generated_0.php']],
        ], 'add a new capability');

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(1, $sc['real_work_tasks']);
        $this->assertSame(1, $sc['feature_tasks']);
        $this->assertTrue($sc['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_cosmetic_false_flag_keeps_real_work_real(): void
    {
        // An explicit non-true cosmetic flag must NOT trip the cosmetic blocker (truthy-coercion contract).
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, $this->bugFixPayload() + ['cosmetic' => false]);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame(0, $sc['cosmetic_tasks']);
        $this->assertSame(1, $sc['real_work_tasks']);
        $this->assertTrue($sc['claim_policy']['loop_real_work_claim_allowed']);
    }

    public function test_non_scalar_payload_fields_do_not_crash_and_refuse_claim(): void
    {
        // The never-crash fail-safe: array-valued objective_kind / pattern.mode / pattern.reason must NOT
        // throw an Array-to-string ErrorException out of the per-row loop. The row degrades to a safe class
        // and the claim is refused — the scorecard stays well-formed (status ok, no exception).
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, ['objective_kind' => ['feature']]);
        $this->makeTask($campaign->id, ['objective_kind' => 'refactor', 'pattern' => ['mode' => ['a', 'b'], 'selected' => ['x']]]);
        $this->makeTask($campaign->id, ['objective_kind' => 'refactor', 'pattern' => ['rejected' => true, 'reason' => ['cosmetic']]]);

        $sc = $this->service()->scorecard($campaign->id);

        $this->assertSame('ok', $sc['status'], 'scorecard never crashes on malformed payloads');
        $this->assertSame(3, $sc['tasks_total']);
        $this->assertSame(0, $sc['real_work_tasks']);
        $this->assertFalse($sc['claim_policy']['loop_real_work_claim_allowed']);
        // partition invariant survives malformed rows
        $this->assertSame(
            $sc['tasks_total'],
            $sc['real_work_tasks'] + $sc['proxy_refactor_tasks'] + $sc['cosmetic_tasks'] + $sc['unknown_tasks'],
        );
        $this->assertSame(
            $sc['tasks_total'],
            $sc['pattern_signals']['pattern_driver_tasks']
                + $sc['pattern_signals']['pattern_advisory_tasks']
                + $sc['pattern_signals']['pattern_missing_tasks'],
        );
    }

    // ── command parity + receipt ──────────────────────────────────────────────

    public function test_command_json_matches_service_counts(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, $this->bugFixPayload());
        $this->makeTask($campaign->id, $this->proxyRefactorPayload());

        $service = $this->service()->scorecard($campaign->id);

        $exit = Artisan::call('atlas:loop:real-work-scorecard', [
            '--campaign' => $campaign->id,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded, 'command must emit a JSON object');
        $this->assertSame(AtlasLoopRealWorkScorecardService::SCHEMA_VERSION, $decoded['schema_version']);

        foreach (['tasks_total', 'real_work_tasks', 'bug_fix_tasks', 'feature_tasks', 'verification_tasks',
            'proxy_refactor_tasks', 'cosmetic_tasks', 'unknown_tasks'] as $k) {
            $this->assertSame($service[$k], $decoded[$k], "counter {$k} must match the service");
        }
        $this->assertSame(
            $service['claim_policy']['loop_real_work_claim_allowed'],
            $decoded['claim_policy']['loop_real_work_claim_allowed'],
        );
    }

    public function test_write_receipt_persists_canonical_json(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, $this->bugFixPayload());

        $path = storage_path('app/atlas/evidence/loop-real-work-scorecard/c0-test-'.bin2hex(random_bytes(4)).'.json');
        if (File::exists($path)) {
            File::delete($path);
        }

        $exit = Artisan::call('atlas:loop:real-work-scorecard', [
            '--campaign' => $campaign->id,
            '--receipt' => $path,
        ]);
        $this->assertSame(0, $exit);
        $this->assertTrue(File::exists($path), 'receipt file must be written');

        $decoded = json_decode((string) File::get($path), true);
        $this->assertIsArray($decoded);
        $this->assertSame(AtlasLoopRealWorkScorecardService::SCHEMA_VERSION, $decoded['schema_version']);
        $this->assertSame(1, $decoded['real_work_tasks']);

        File::delete($path);
    }

    public function test_write_receipt_default_path_lands_in_evidence_dir(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeTask($campaign->id, $this->bugFixPayload());

        $exit = Artisan::call('atlas:loop:real-work-scorecard', [
            '--campaign' => $campaign->id,
            '--write-receipt' => true,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('receipt_path', $decoded, 'json mode carries the receipt path inside the payload');
        $this->assertStringContainsString('atlas/evidence/loop-real-work-scorecard', $decoded['receipt_path']);
        $this->assertTrue(File::exists($decoded['receipt_path']));

        File::delete($decoded['receipt_path']);
    }
}
