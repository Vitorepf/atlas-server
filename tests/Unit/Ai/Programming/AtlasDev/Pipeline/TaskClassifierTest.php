<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassifier;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use PHPUnit\Framework\TestCase;

final class TaskClassifierTest extends TestCase
{
    public function test_question_kind_is_recognised(): void
    {
        $classification = $this->classify('explique como funciona o RoutingDecisionEngine', surface: 'atlas_cli_dev');
        $this->assertSame(TaskClassification::KIND_QUESTION, $classification->taskKind);
        $this->assertFalse($classification->writeImplied);
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

    public function test_frontend_kind_requires_frontend_surface(): void
    {
        $frontend = $this->classify('ajustar tipografia da tela de Atlas AI', surface: 'atlas_desktop_ai');
        $this->assertSame(TaskClassification::KIND_FRONTEND, $frontend->taskKind);
        $this->assertTrue($frontend->writeImplied);

        $cli = $this->classify('ajustar tipografia da tela de Atlas AI', surface: 'atlas_cli_dev');
        $this->assertNotSame(TaskClassification::KIND_FRONTEND, $cli->taskKind);
    }

    public function test_risky_kind_wins_over_action(): void
    {
        $classification = $this->classify('corrigir billing migration em production', surface: 'atlas_cli_dev');
        $this->assertSame(TaskClassification::KIND_RISKY, $classification->taskKind);
        $this->assertFalse($classification->writeImplied);
        $this->assertSame(IntakeNormalizer::CLARITY_LOW, $classification->intentClarityLevel);
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

    private function classify(string $intent, string $surface, string $clarity = IntakeNormalizer::CLARITY_HIGH): TaskClassification
    {
        $envelope = $this->envelope($intent, $surface, $clarity);

        return (new TaskClassifier())->classify($envelope);
    }

    private function envelope(string $intent, string $surface, string $clarity): OperationEnvelope
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
            userConstraints: [],
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
