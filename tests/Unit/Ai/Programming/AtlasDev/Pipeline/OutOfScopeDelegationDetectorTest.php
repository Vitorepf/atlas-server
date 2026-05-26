<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Pipeline\DelegationSuggestion;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\OutOfScopeDelegationDetector;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use PHPUnit\Framework\TestCase;

final class OutOfScopeDelegationDetectorTest extends TestCase
{
    private OutOfScopeDelegationDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new OutOfScopeDelegationDetector;
    }

    public function test_conceptual_question_without_workspace_routes_to_research(): void
    {
        $suggestion = $this->detector->detect(
            envelope: $this->envelope(
                intent: 'explique como funciona OAuth 2.0',
                workspaceResolved: false,
            ),
            classification: $this->classification(TaskClassification::KIND_QUESTION, false),
        );

        $this->assertNotNull($suggestion);
        $this->assertSame(DelegationSuggestion::FLOW_RESEARCH, $suggestion->suggestedFlow);
        $this->assertSame('conceptual_question_without_workspace_target', $suggestion->reason);
    }

    public function test_conceptual_question_with_workspace_and_file_ref_is_in_scope(): void
    {
        $suggestion = $this->detector->detect(
            envelope: $this->envelope(
                intent: 'explique como funciona o app/Services/Auth/OAuthService.php neste repo',
                workspaceResolved: true,
            ),
            classification: $this->classification(TaskClassification::KIND_QUESTION, false),
        );

        $this->assertNull($suggestion);
    }

    public function test_free_form_chat_routes_to_conversation(): void
    {
        $suggestion = $this->detector->detect(
            envelope: $this->envelope(
                intent: 'vamos discutir o futuro do produto',
                workspaceResolved: false,
            ),
            classification: $this->classification(TaskClassification::KIND_QUESTION, false),
        );

        $this->assertNotNull($suggestion);
        $this->assertSame(DelegationSuggestion::FLOW_CONVERSATION, $suggestion->suggestedFlow);
    }

    public function test_debug_traceback_without_code_target_routes_to_debug(): void
    {
        $suggestion = $this->detector->detect(
            envelope: $this->envelope(
                intent: 'analise este traceback de produção',
                workspaceResolved: false,
            ),
            classification: $this->classification(TaskClassification::KIND_QUESTION, false),
        );

        $this->assertNotNull($suggestion);
        $this->assertSame(DelegationSuggestion::FLOW_DEBUG, $suggestion->suggestedFlow);
    }

    public function test_obra_marker_routes_to_forge_even_with_workspace(): void
    {
        $suggestion = $this->detector->detect(
            envelope: $this->envelope(
                intent: 'reescrever o sistema inteiro de autenticação',
                workspaceResolved: true,
            ),
            classification: $this->classification(TaskClassification::KIND_PATCH, true),
        );

        $this->assertNotNull($suggestion);
        $this->assertSame(DelegationSuggestion::FLOW_FORGE, $suggestion->suggestedFlow);
    }

    public function test_explanation_only_intent_routes_to_explain(): void
    {
        $suggestion = $this->detector->detect(
            envelope: $this->envelope(
                intent: 'apenas explique o conceito de event sourcing, sem mudar codigo',
                workspaceResolved: false,
            ),
            classification: $this->classification(TaskClassification::KIND_QUESTION, false),
        );

        $this->assertNotNull($suggestion);
        $this->assertSame(DelegationSuggestion::FLOW_EXPLAIN, $suggestion->suggestedFlow);
    }

    public function test_patch_intent_with_workspace_is_in_scope(): void
    {
        $suggestion = $this->detector->detect(
            envelope: $this->envelope(
                intent: 'corrigir o teste falhando em tests/Unit/FooTest.php',
                workspaceResolved: true,
            ),
            classification: $this->classification(TaskClassification::KIND_REPAIR, true),
        );

        $this->assertNull($suggestion);
    }

    public function test_delegation_suggestion_rejects_unknown_flow(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DelegationSuggestion(suggestedFlow: 'atlas_unknown', reason: 'r');
    }

    private function classification(string $kind, bool $writeImplied): TaskClassification
    {
        return new TaskClassification(
            taskKind: $kind,
            intentClarityLevel: IntakeNormalizer::CLARITY_HIGH,
            matchedRules: [],
            writeImplied: $writeImplied,
        );
    }

    private function envelope(string $intent, bool $workspaceResolved): OperationEnvelope
    {
        return new OperationEnvelope(
            runId: 'dev-test',
            surfaceId: 'atlas_cli_dev',
            surfaceContext: new SurfaceContext(productSurface: 'atlas_cli_dev'),
            workspace: $workspaceResolved ? '/ws' : '',
            workspaceHash: hash('sha256', '/ws'),
            gitState: new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0),
            rawIntent: $intent,
            normalizedIntent: $intent,
            userConstraints: [],
            intentClarityLevel: IntakeNormalizer::CLARITY_HIGH,
            dirtyWorktreePolicy: IntakeNormalizer::DIRTY_POLICY_PRESERVE,
            preflight: new Preflight(
                workspaceResolved: $workspaceResolved,
                permissionMode: IntakeNormalizer::PERMISSION_WRITE_ALLOWED,
                writeAllowed: $workspaceResolved,
                operatorExplicit: false,
            ),
            envelopeHash: 'deadbeef',
        );
    }
}
