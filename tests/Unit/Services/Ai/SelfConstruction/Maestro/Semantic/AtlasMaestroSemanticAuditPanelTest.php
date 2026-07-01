<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Semantic;

use App\Services\Ai\SelfConstruction\Maestro\Semantic\AtlasMaestroSemanticAuditPanel;
use Tests\TestCase;

final class AtlasMaestroSemanticAuditPanelTest extends TestCase
{
    private function checker(bool $ok): object
    {
        return new class($ok)
        {
            public function __construct(private readonly bool $ok) {}

            public function check(array $packet): array
            {
                return ['ok' => $this->ok];
            }
        };
    }

    private function verifier(bool $ok): object
    {
        return new class($ok)
        {
            public function __construct(private readonly bool $ok) {}

            public function verify(array $packet): array
            {
                return ['ok' => $this->ok];
            }
        };
    }

    private function resolver(bool $exists): object
    {
        return new class($exists)
        {
            public function __construct(private readonly bool $exists) {}

            public function resolve(string $symbol): array
            {
                return $this->exists
                    ? ['symbol' => $symbol, 'exists' => true, 'file' => 'app/X.php', 'line' => 1]
                    : ['symbol' => $symbol, 'exists' => false];
            }
        };
    }

    private function panel(bool $checkerOk, bool $verifierOk, bool $resolverExists): AtlasMaestroSemanticAuditPanel
    {
        return new AtlasMaestroSemanticAuditPanel(
            allowedFilesIntentChecker: $this->checker($checkerOk),
            orphanCallerVerifier: $this->verifier($verifierOk),
            symbolResolver: $this->resolver($resolverExists),
        );
    }

    // ── hard preflight rejections ────────────────────────────────────────────────

    public function test_proxy_only_objective_text_is_rejected_before_any_voter_runs(): void
    {
        $panel = $this->panel(true, true, true);

        $result = $panel->audit([
            'objective' => 'stub-only: just wrap the legacy resolver.',
            'acceptance_criteria' => ['Assert AtlasFoo resolves.'],
        ]);

        self::assertFalse($result['pass']);
        self::assertSame([], $result['votes']);
        self::assertSame('proxy_only_work_rejected', $result['panel_reason']);
        self::assertSame('proxy_only', $result['rejection_family']);
    }

    public function test_blind_orphan_wiring_proxy_flag_is_rejected_before_any_voter_runs(): void
    {
        $panel = $this->panel(true, true, true);

        $result = $panel->audit([
            'objective' => 'Wire the orphan caller.',
            'blind_orphan_wiring_proxy' => true,
            'acceptance_criteria' => ['Assert AtlasFoo resolves.'],
        ]);

        self::assertFalse($result['pass']);
        self::assertSame('proxy_only_work_rejected', $result['panel_reason']);
    }

    public function test_brain_lane_files_outside_autonomous_evolution_brain_are_rejected_before_quorum(): void
    {
        $panel = $this->panel(true, true, true);

        $result = $panel->audit([
            'objective' => 'Update the brain module docs.',
            'lane' => 'final-brain',
            'allowed_files' => ['docs/some-doc.md'],
            'acceptance_criteria' => ['No runnable test.'],
        ]);

        self::assertFalse($result['pass']);
        self::assertSame([], $result['votes']);
        self::assertSame('lane_file_coherence_failed', $result['panel_reason']);
        self::assertSame('lane_file_mismatch', $result['rejection_family']);
    }

    // ── two-of-three quorum pass / voter_reasons on failure ─────────────────────

    public function test_exactly_two_of_three_passing_voters_yields_quorum_pass(): void
    {
        $panel = $this->panel(true, true, false);

        $result = $panel->audit([
            'acceptance_criteria' => ['No symbol cited here.'],
        ]);

        self::assertTrue($result['pass']);
        self::assertSame([true, true, false], $result['votes']);
        self::assertSame('semantic_quorum_passed', $result['panel_reason']);
        self::assertSame([], $result['voter_reasons']);
        self::assertNull($result['rejection_family']);
    }

    public function test_only_one_of_three_passing_voters_fails_quorum_and_lists_all_failed_reasons(): void
    {
        $panel = $this->panel(false, false, true);

        $result = $panel->audit([
            'acceptance_criteria' => ['Assert AtlasFoo resolves.'],
        ]);

        self::assertFalse($result['pass']);
        self::assertSame([false, false, true], $result['votes']);
        self::assertSame('semantic_quorum_failed', $result['panel_reason']);
        self::assertSame(['allowed_files_intent_failed', 'orphan_caller_failed'], $result['voter_reasons']);
        self::assertSame('semantic_quorum', $result['rejection_family']);
    }

    // ── runnable acceptance detection ────────────────────────────────────────────

    public function test_runnable_acceptance_true_when_artisan_test_is_cited(): void
    {
        $panel = $this->panel(true, true, true);

        $result = $panel->audit([
            'acceptance_criteria' => ['Runnable: /opt/homebrew/bin/php artisan test --filter=Foo'],
        ]);

        self::assertTrue($result['runnable_acceptance']);
    }

    public function test_runnable_acceptance_false_when_no_test_command_is_cited(): void
    {
        $panel = $this->panel(true, true, true);

        $result = $panel->audit([
            'acceptance_criteria' => ['Just document the change.'],
        ]);

        self::assertFalse($result['runnable_acceptance']);
    }

    // ── duplicate symbol risk detection ──────────────────────────────────────────

    public function test_duplicate_symbol_risk_true_when_two_allowed_files_share_basename(): void
    {
        $panel = $this->panel(true, true, true);

        $result = $panel->audit([
            'allowed_files' => ['app/A/Foo.php', 'app/B/Foo.php'],
            'acceptance_criteria' => ['Assert AtlasFoo resolves.'],
        ]);

        self::assertTrue($result['duplicate_symbol_risk']);
    }

    public function test_duplicate_symbol_risk_false_when_allowed_files_have_distinct_basenames(): void
    {
        $panel = $this->panel(true, true, true);

        $result = $panel->audit([
            'allowed_files' => ['app/A/Foo.php', 'app/B/Bar.php'],
            'acceptance_criteria' => ['Assert AtlasFoo resolves.'],
        ]);

        self::assertFalse($result['duplicate_symbol_risk']);
    }

    // ── real Atlas symbol citation requirement ───────────────────────────────────

    public function test_resolvable_atlas_symbol_in_acceptance_criteria_supplies_a_passing_vote(): void
    {
        $panel = $this->panel(false, false, true);

        $result = $panel->audit(['acceptance_criteria' => ['Assert AtlasRealSymbol resolves.']]);

        self::assertTrue($result['votes'][2]);
    }

    public function test_unresolvable_atlas_symbol_does_not_supply_a_passing_vote(): void
    {
        $panel = $this->panel(false, false, false);

        $result = $panel->audit(['acceptance_criteria' => ['Assert AtlasFakeSymbol resolves.']]);

        self::assertFalse($result['votes'][2]);
    }

    public function test_no_atlas_symbol_cited_at_all_does_not_supply_a_passing_vote(): void
    {
        $panel = $this->panel(false, false, true);

        $result = $panel->audit(['acceptance_criteria' => ['No symbol mentioned here at all.']]);

        self::assertFalse($result['votes'][2]);
    }
}
