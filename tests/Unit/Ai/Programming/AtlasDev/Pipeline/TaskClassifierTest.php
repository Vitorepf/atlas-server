<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassifier;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use PHPUnit\Framework\TestCase;

final class TaskClassifierTest extends TestCase
{
    public function test_question_kind_is_recognised(): void
    {
        $classification = $this->classify('explique como funciona o RoutingDecisionEngine', surface: 'atlas_cli_dev');
        $this->assertSame(TaskClassification::KIND_QUESTION, $classification->taskKind);
        $this->assertFalse($classification->writeImplied);
    }

    public function test_explicit_read_only_question_wins_over_risky_domain_tokens(): void
    {
        $classification = $this->classify('Responda usando o codigo: quantos dias de grace period BillingPolicy usa?', surface: 'atlas_desktop_ai');

        $this->assertSame(TaskClassification::KIND_QUESTION, $classification->taskKind);
        $this->assertFalse($classification->writeImplied);
        $this->assertContains('question:responda', $classification->matchedRules);
    }

    public function test_patch_kind_for_clear_action(): void
    {
        $classification = $this->classify('adicione um helper em app/Services/Foo/Bar.php', surface: 'atlas_cli_dev');
        $this->assertSame(TaskClassification::KIND_PATCH, $classification->taskKind);
        $this->assertTrue($classification->writeImplied);
    }

    public function test_repair_kind_for_failing_test(): void
    {
        $classification = $this->classify('corrija o teste falhando em tests/Unit/FooTest.php', surface: 'atlas_cli_dev');
        $this->assertSame(TaskClassification::KIND_REPAIR, $classification->taskKind);
        $this->assertTrue($classification->writeImplied);
    }

    public function test_repair_kind_for_common_portuguese_conserte(): void
    {
        $classification = $this->classify('Conserte FooService para retornar 42', surface: 'atlas_desktop_ai');
        $this->assertSame(TaskClassification::KIND_REPAIR, $classification->taskKind);
        $this->assertTrue($classification->writeImplied);
    }

    public function test_review_kind_is_read_only(): void
    {
        $classification = $this->classify('revisar este diff em app/Foo.php', surface: 'atlas_cli_dev');
        $this->assertSame(TaskClassification::KIND_REVIEW, $classification->taskKind);
        $this->assertFalse($classification->writeImplied);
    }

    public function test_promotion_preview_metadata_does_not_mask_create_action_as_review(): void
    {
        $classification = $this->classify(
            'Create a new PHP class AtlasDevRunProfileGuardEvaluator at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/AtlasDevRunProfileGuardEvaluator.php. Implement public function evaluate(array profile, array job, array decision): array. It validates governed run profile, receipt-before-provider, and promotion-preview escalation signals.',
            surface: 'atlas_cli_dev',
            constraints: [
                'allowed_files=app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/AtlasDevRunProfileGuardEvaluator.php,tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/AtlasDevRunProfileGuardEvaluatorTest.php',
                'validation_command=git diff --check',
            ],
        );

        $this->assertSame(TaskClassification::KIND_PATCH, $classification->taskKind);
        $this->assertTrue($classification->writeImplied);
        $this->assertContains('action:create', $classification->matchedRules);
        $this->assertNotContains('review:review', $classification->matchedRules);
    }

    public function test_frontend_kind_works_on_desktop_and_cli_dev_surfaces(): void
    {
        $frontend = $this->classify('ajustar tipografia da tela de Atlas AI', surface: 'atlas_desktop_ai');
        $this->assertSame(TaskClassification::KIND_FRONTEND, $frontend->taskKind);
        $this->assertTrue($frontend->writeImplied);

        $cli = $this->classify('ajustar tipografia da tela de Atlas AI', surface: 'atlas_cli_dev');
        $this->assertSame(TaskClassification::KIND_FRONTEND, $cli->taskKind);
        $this->assertTrue($cli->writeImplied);
    }

    public function test_risky_kind_wins_over_action(): void
    {
        $classification = $this->classify('corrigir billing migration em production', surface: 'atlas_cli_dev');
        $this->assertSame(TaskClassification::KIND_RISKY, $classification->taskKind);
        $this->assertFalse($classification->writeImplied);
        $this->assertSame(IntakeNormalizer::CLARITY_LOW, $classification->intentClarityLevel);
    }

    public function test_structural_allowed_files_do_not_trigger_risky_session_token(): void
    {
        $classification = $this->classify(
            'Implement the smallest correct scoped repair now inside allowed_files only. OBJECTIVE: Missing test for AutonomousEvolutionSessionReadModelService',
            surface: 'atlas_cli_dev',
            constraints: [
                'allowed_files=app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionReadModelService.php,tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionReadModelServiceTest.php',
                'validation_command=php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionReadModelServiceTest.php',
            ],
        );

        $this->assertSame(TaskClassification::KIND_PATCH, $classification->taskKind);
        $this->assertTrue($classification->writeImplied);
        $this->assertNotContains('risky:session', $classification->matchedRules);
    }

    public function test_session_read_model_phrase_does_not_route_as_auth_session_risk(): void
    {
        $classification = $this->classify(
            'Implement the smallest correct scoped repair now inside allowed_files only. OBJECTIVE: Add focused unit coverage for AP-786 session read model',
            surface: 'atlas_cli_dev',
            constraints: [
                'allowed_files=app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionReadModelService.php,tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionReadModelServiceTest.php',
                'validation_command=php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionReadModelServiceTest.php',
            ],
        );

        $this->assertSame(TaskClassification::KIND_PATCH, $classification->taskKind);
        $this->assertTrue($classification->writeImplied);
        $this->assertNotContains('risky:session', $classification->matchedRules);
    }

    public function test_unknown_intent_falls_back_to_question_with_low_clarity(): void
    {
        // Mirrors what IntakeNormalizer would emit for a 3-token unrecognised
        // intent: clarity=medium. Classifier then demotes to low because no
        // rule matched.
        $classification = $this->classify('foo bar baz', surface: 'atlas_cli_dev', clarity: IntakeNormalizer::CLARITY_MEDIUM);
        $this->assertSame(TaskClassification::KIND_QUESTION, $classification->taskKind);
        $this->assertSame(IntakeNormalizer::CLARITY_LOW, $classification->intentClarityLevel);
        $this->assertContains('fallback:no_match', $classification->matchedRules);
    }

    /**
     * @param  list<string>  $constraints
     */
    private function classify(string $intent, string $surface, string $clarity = IntakeNormalizer::CLARITY_HIGH, array $constraints = []): TaskClassification
    {
        $envelope = $this->envelope($intent, $surface, $clarity, $constraints);

        return (new TaskClassifier)->classify($envelope);
    }

    /**
     * @param  list<string>  $constraints
     */
    private function envelope(string $intent, string $surface, string $clarity, array $constraints = []): OperationEnvelope
    {
        return new OperationEnvelope(
            runId: 'dev-test',
            surfaceId: $surface,
            surfaceContext: new SurfaceContext(productSurface: $surface),
            workspace: '/tmp/ws',
            workspaceHash: hash('sha256', '/tmp/ws'),
            gitState: new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0),
            rawIntent: $intent,
            normalizedIntent: trim($intent),
            userConstraints: $constraints,
            intentClarityLevel: $clarity,
            dirtyWorktreePolicy: IntakeNormalizer::DIRTY_POLICY_PRESERVE,
            preflight: new Preflight(
                workspaceResolved: true,
                permissionMode: IntakeNormalizer::PERMISSION_WRITE_ALLOWED,
                writeAllowed: true,
                operatorExplicit: false,
            ),
            envelopeHash: 'deadbeef',
        );
    }
}
