<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Support;

use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Support\SpecComposerBuildersSupport;
use PHPUnit\Framework\TestCase;

/**
 * Pure SpecComposer builders — no I/O, no Laravel app, no SpecComposer DI.
 */
final class SpecComposerBuildersSupportTest extends TestCase
{
    public function test_read_only_acceptance_criteria_is_manual_single_entry(): void
    {
        $criteria = SpecComposerBuildersSupport::buildAcceptanceCriteria(
            mode: SpecComposerBuildersSupport::MODE_READ_ONLY,
            verificationProfile: SpecComposerBuildersSupport::PROFILE_PHP_LARAVEL,
            verificationCommands: ['composer test'],
            expectedFiles: ['app/Foo.php'],
            intentVerbs: ['fix'],
            e2Enabled: true,
        );

        $this->assertCount(1, $criteria);
        $this->assertSame('ac_1', $criteria[0]['id']);
        $this->assertSame('manual', $criteria[0]['verification']);
    }

    public function test_patch_acceptance_includes_behavioral_command_and_scope(): void
    {
        $criteria = SpecComposerBuildersSupport::buildAcceptanceCriteria(
            mode: SpecComposerBuildersSupport::MODE_PATCH,
            verificationProfile: SpecComposerBuildersSupport::PROFILE_PHP_LARAVEL,
            verificationCommands: ['composer test -- --filter=FooTest'],
            expectedFiles: ['app/Foo.php'],
            intentVerbs: ['fix', 'add'],
            e2Enabled: true,
        );

        $ids = array_column($criteria, 'id');
        $this->assertContains('ac_behavior_fix', $ids);
        $this->assertContains('ac_behavior_add', $ids);
        $this->assertContains('ac_cmd_1', $ids);
        $this->assertContains('ac_scope', $ids);

        $behavioral = $criteria[0];
        $this->assertSame('test', $behavioral['verification']);
        $this->assertSame('composer test -- --filter=FooTest', $behavioral['verification_ref']);
    }

    public function test_e2_off_skips_behavioral_acceptance(): void
    {
        $criteria = SpecComposerBuildersSupport::buildAcceptanceCriteria(
            mode: SpecComposerBuildersSupport::MODE_PATCH,
            verificationProfile: SpecComposerBuildersSupport::PROFILE_PHP_LARAVEL,
            verificationCommands: ['composer test'],
            expectedFiles: [],
            intentVerbs: ['fix'],
            e2Enabled: false,
        );

        $ids = array_column($criteria, 'id');
        $this->assertNotContains('ac_behavior_fix', $ids);
        $this->assertContains('ac_cmd_1', $ids);
    }

    public function test_behavioral_uses_profile_default_when_commands_empty(): void
    {
        $criteria = SpecComposerBuildersSupport::buildBehavioralAcceptanceCriteria(
            SpecComposerBuildersSupport::PROFILE_TS_REACT,
            [],
            ['fix'],
        );

        $this->assertCount(1, $criteria);
        $this->assertSame('pnpm test', $criteria[0]['verification_ref']);
    }

    public function test_behavioral_empty_when_generic_profile_and_no_commands(): void
    {
        $this->assertSame([], SpecComposerBuildersSupport::buildBehavioralAcceptanceCriteria(
            SpecComposerBuildersSupport::PROFILE_GENERIC_NO_TEST,
            [],
            ['fix'],
        ));
    }

    public function test_verb_slug_normalizes(): void
    {
        $this->assertSame('fix_bug', SpecComposerBuildersSupport::verbSlug('  Fix-Bug  '));
        $this->assertSame('add', SpecComposerBuildersSupport::verbSlug('add'));
    }

    public function test_non_goals_mode_variants(): void
    {
        $repair = SpecComposerBuildersSupport::buildNonGoals(SpecComposerBuildersSupport::MODE_REPAIR);
        $this->assertContains('nao reescrever feature, apenas restaurar comportamento esperado', $repair);

        $escalate = SpecComposerBuildersSupport::buildNonGoals(SpecComposerBuildersSupport::MODE_ESCALATE_PREVIEW);
        $this->assertContains('nao aplicar patch no fast path', $escalate);
        $this->assertContains('nao tomar decisao automatica de promotion', $escalate);
    }

    public function test_expected_behavior_by_mode_and_verbs(): void
    {
        $readOnly = SpecComposerBuildersSupport::buildExpectedBehavior(
            SpecComposerBuildersSupport::MODE_READ_ONLY,
            [],
        );
        $this->assertSame('human', $readOnly[0]['observable_by']);

        $withVerbs = SpecComposerBuildersSupport::buildExpectedBehavior(
            SpecComposerBuildersSupport::MODE_PATCH,
            ['fix'],
        );
        $this->assertStringContainsString("verbo de intenção 'fix'", $withVerbs[0]['description']);
        $this->assertSame('test', $withVerbs[0]['observable_by']);

        $fallback = SpecComposerBuildersSupport::buildExpectedBehavior(
            SpecComposerBuildersSupport::MODE_PATCH,
            [],
        );
        $this->assertStringContainsString('comportamento esperado verificavel', $fallback[0]['description']);
    }

    public function test_assumptions_from_discovery_confidence(): void
    {
        $hypothesis = SpecComposerBuildersSupport::buildAssumptions(
            SpecComposerBuildersSupport::MODE_PATCH,
            CodeDiscoveryManifest::CONFIDENCE_HYPOTHESIS,
        );
        $this->assertCount(1, $hypothesis);
        $this->assertSame('inference', $hypothesis[0]['confidence']);

        $blocking = SpecComposerBuildersSupport::buildAssumptions(
            SpecComposerBuildersSupport::MODE_ESCALATE_PREVIEW,
            CodeDiscoveryManifest::CONFIDENCE_BLOCKING_AMBIGUITY,
        );
        $this->assertCount(2, $blocking);
        $this->assertSame('blocking', $blocking[0]['confidence']);
        $this->assertSame('blocking', $blocking[1]['confidence']);
    }

    public function test_completion_criteria_and_rollback(): void
    {
        $readOnly = SpecComposerBuildersSupport::buildCompletionCriteria(
            SpecComposerBuildersSupport::MODE_READ_ONLY,
            [],
        );
        $this->assertSame(['resposta entregue com refs e limites de confianca'], $readOnly);

        $patch = SpecComposerBuildersSupport::buildCompletionCriteria(
            SpecComposerBuildersSupport::MODE_PATCH,
            ['composer test'],
        );
        $this->assertContains('todos os comandos da verification_plan passam', $patch);
        $this->assertContains('scope_guard nao reporta forbidden_touch', $patch);

        $this->assertSame(
            'sem rollback aplicavel (no patch)',
            SpecComposerBuildersSupport::buildRollback(SpecComposerBuildersSupport::MODE_REVIEW, ['a.php']),
        );
        $this->assertSame(
            'git checkout -- app/Foo.php',
            SpecComposerBuildersSupport::buildRollback(SpecComposerBuildersSupport::MODE_PATCH, ['app/Foo.php']),
        );
        $this->assertSame(
            'git checkout -- .',
            SpecComposerBuildersSupport::buildRollback(SpecComposerBuildersSupport::MODE_PATCH, []),
        );
    }

    public function test_goal_blocked_evidence_intent_text(): void
    {
        $this->assertSame('implementar X', SpecComposerBuildersSupport::buildGoal('  implementar X  '));
        $this->assertSame(
            'goal nao inferivel; aguardar clarificacao do operador',
            SpecComposerBuildersSupport::buildGoal('   '),
        );

        $blocked = SpecComposerBuildersSupport::buildBlockedActions();
        $this->assertContains('production_write', $blocked);
        $this->assertContains('forge_invoke_direct', $blocked);

        $this->assertSame(['context_refs'], SpecComposerBuildersSupport::buildEvidenceRequired(
            SpecComposerBuildersSupport::MODE_READ_ONLY,
        ));
        $this->assertContains('diff_hash', SpecComposerBuildersSupport::buildEvidenceRequired(
            SpecComposerBuildersSupport::MODE_PATCH,
        ));

        $this->assertSame(
            'normalized',
            SpecComposerBuildersSupport::resolveIntentText('normalized', 'raw', true),
        );
        $this->assertSame(
            'raw only',
            SpecComposerBuildersSupport::resolveIntentText('', 'raw only', true),
        );
        $this->assertSame(
            '',
            SpecComposerBuildersSupport::resolveIntentText('', '', false),
        );
        $this->assertSame(
            'write_task_intent_unavailable',
            SpecComposerBuildersSupport::resolveIntentText('', '', true),
        );
    }

    public function test_path_and_ref_helpers(): void
    {
        $this->assertSame(
            'app/Foo.php',
            SpecComposerBuildersSupport::relativise('/repo', '/repo/app/Foo.php'),
        );
        $this->assertSame(
            '/other/Foo.php',
            SpecComposerBuildersSupport::relativise('/repo', '/other/Foo.php'),
        );

        $this->assertSame('tests/Unit/FooTest.php', SpecComposerBuildersSupport::testRefToPath('file://tests/Unit/FooTest.php'));
        $this->assertSame('tests/Unit/FooTest.php', SpecComposerBuildersSupport::testRefToPath('tests/Unit/FooTest.php'));
        $this->assertNull(SpecComposerBuildersSupport::testRefToPath('symbol://Foo'));

        $this->assertSame('FooTest', SpecComposerBuildersSupport::phpUnitFilterFromPath('tests/Unit/FooTest.php'));
        $this->assertNull(SpecComposerBuildersSupport::phpUnitFilterFromPath('app/Foo.php'));

        $this->assertSame('test', SpecComposerBuildersSupport::mapContextRefKind(ContextRef::KIND_TEST));
        $this->assertSame('context', SpecComposerBuildersSupport::mapContextRefKind('unknown_kind'));
    }

    public function test_scope_candidates_escalation_forbidden_write_policy(): void
    {
        $this->assertSame(
            SpecComposerBuildersSupport::SCOPE_STRUCTURAL,
            SpecComposerBuildersSupport::resolveScopeMode(RiskLevelScorer::R3),
        );
        $this->assertSame(
            SpecComposerBuildersSupport::SCOPE_COMPACT,
            SpecComposerBuildersSupport::resolveScopeMode(RiskLevelScorer::R1),
        );

        $this->assertSame(4, SpecComposerBuildersSupport::maxCandidates(RiskLevelScorer::R0));
        $this->assertSame(12, SpecComposerBuildersSupport::maxCandidates(RiskLevelScorer::R5));

        $r4 = SpecComposerBuildersSupport::resolveEscalationTriggers(
            TaskClassification::KIND_PATCH,
            RiskLevelScorer::R4,
            true,
        );
        $this->assertContains('human_action_required', $r4);

        $repair = SpecComposerBuildersSupport::resolveEscalationTriggers(
            TaskClassification::KIND_REPAIR,
            RiskLevelScorer::R2,
            true,
        );
        $this->assertContains('same_signature_failure_twice', $repair);

        $forbidden = SpecComposerBuildersSupport::forbiddenFilesFor(
            SpecComposerBuildersSupport::MODE_ESCALATE_PREVIEW,
            ['.env', 'vendor/*', 'secrets/.env.local'],
        );
        $this->assertContains('database/migrations/*', $forbidden);
        $this->assertContains('config/*', $forbidden);
        $this->assertNotContains('.env', $forbidden);
        $this->assertNotContains('secrets/.env.local', $forbidden);

        $this->assertFalse(SpecComposerBuildersSupport::writeAllowed(true, SpecComposerBuildersSupport::MODE_READ_ONLY));
        $this->assertFalse(SpecComposerBuildersSupport::writeAllowed(false, SpecComposerBuildersSupport::MODE_PATCH));
        $this->assertTrue(SpecComposerBuildersSupport::writeAllowed(true, SpecComposerBuildersSupport::MODE_PATCH));
    }

    public function test_constraint_value_and_model_family(): void
    {
        $this->assertSame(
            'claude_cli',
            SpecComposerBuildersSupport::constraintValue(['provider=claude_cli', 'other=x'], 'provider'),
        );
        $this->assertNull(SpecComposerBuildersSupport::constraintValue(['provider='], 'provider'));
        $this->assertNull(SpecComposerBuildersSupport::constraintValue(['foo=bar'], 'provider'));

        $this->assertSame('sonnet', SpecComposerBuildersSupport::normalizeModelFamily('claude_cli', 'claude-sonnet-4'));
        $this->assertSame('opus', SpecComposerBuildersSupport::normalizeModelFamily('claude_cli', 'opus'));
        $this->assertSame('gpt-4', SpecComposerBuildersSupport::normalizeModelFamily('codex_cli', 'gpt-4'));
    }
}
