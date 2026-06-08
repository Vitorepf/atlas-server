<?php

namespace Tests\Feature\Ai\OperatorIntelligence;

use App\Models\OperatorLearningCandidate;
use App\Models\OperatorProfileFeedbackEvent;
use App\Models\OperatorProfileItem;
use App\Models\OperatorProfilePolicyRule;
use App\Services\Ai\AtlasOpenBrainContextInjectionService;
use App\Services\Ai\OperatorIntelligence\OperatorContextComposer;
use App\Services\Ai\ValueObjects\AiContextPack;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesOperatorIntelligenceTables;
use Tests\TestCase;

final class OperatorLearningReviewCommandTest extends TestCase
{
    use CreatesOperatorIntelligenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOperatorIntelligenceTables();
        config([
            'atlas_operator_intelligence.default_operator_id' => 'vitor',
            'atlas_operator_intelligence.projection_path' => storage_path('framework/testing/operator-intelligence'),
            'atlas_operator_intelligence.auto_apply_enabled' => false,
            'atlas_operator_intelligence.shadow_mode' => true,
            'atlas.token' => 'test-token-with-enough-length-123',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/operator-intelligence'));
        $this->dropOperatorIntelligenceTables();
        parent::tearDown();
    }

    public function test_operator_learning_cli_runs_full_review_to_context_projection_flow(): void
    {
        $captureExit = Artisan::call('atlas:operator-learning', [
            'action' => 'capture',
            '--operator' => 'vitor',
            '--claim' => 'Prefiro respostas curtas quando eu pedir status.',
            '--taxonomy' => 'COL-156',
            '--confidence' => '0.95',
            '--profile-key' => 'communication.status_detail',
            '--effect' => 'response_style',
            '--json' => true,
        ]);
        $capture = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $captureExit);
        $this->assertTrue($capture['ok']);
        $candidateId = data_get($capture, 'candidate.id');
        $this->assertNotEmpty($candidateId);
        $this->assertSame('candidate', data_get($capture, 'candidate.status'));

        $approveExit = Artisan::call('atlas:operator-learning', [
            'action' => 'approve',
            '--candidate' => $candidateId,
            '--operator' => 'vitor',
            '--json' => true,
        ]);
        $approved = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $approveExit);
        $this->assertSame('approved', $approved['decision']);
        $this->assertSame('communication.status_detail', data_get($approved, 'profile_item.profile_key'));
        $this->assertSame(1, OperatorProfileItem::query()->count());
        $this->assertSame(1, OperatorProfilePolicyRule::query()->count());

        $contextExit = Artisan::call('atlas:operator-profile', [
            'action' => 'context',
            '--operator' => 'vitor',
            '--flow' => 'status',
            '--provider-external' => true,
            '--json' => true,
        ]);
        $context = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $contextExit);
        $this->assertSame('atlas.operator_context.v1', $context['schema_version']);
        $this->assertCount(1, $context['items']);
        $this->assertSame('communication.status_detail', data_get($context, 'items.0.profile_key'));
        $this->assertSame(1, OperatorProfileFeedbackEvent::query()->count());

        $digestExit = Artisan::call('atlas:operator-learning', [
            'action' => 'digest',
            '--operator' => 'vitor',
            '--json' => true,
        ]);
        $digest = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $digestExit);
        $this->assertSame(1, data_get($digest, 'counts.active_profile_items'));
        $this->assertNotEmpty($digest['snapshot_id']);

        $projectExit = Artisan::call('atlas:operator-learning', [
            'action' => 'project',
            '--operator' => 'vitor',
            '--json' => true,
        ]);
        $projection = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $projectExit);
        $this->assertTrue(File::exists(data_get($projection, 'paths.profile')));
        $this->assertStringContainsString('communication.status_detail', File::get(data_get($projection, 'paths.profile')));
    }

    public function test_external_context_omits_secret_profile_items(): void
    {
        OperatorProfileItem::query()->create([
            'operator_id' => 'vitor',
            'taxonomy_item_id' => 'OP-140',
            'profile_key' => 'security.secret_boundary',
            'value' => ['effect' => 'do_not_do'],
            'summary' => 'Never expose secret project material.',
            'privacy_class' => 'secret',
            'risk_level' => 'high',
            'confidence' => 0.99,
            'automation_level' => 'observe',
            'status' => 'active',
        ]);

        app(\App\Services\Ai\OperatorIntelligence\OperatorProfilePolicyCompiler::class)
            ->compileOperator('vitor');

        $exit = Artisan::call('atlas:operator-profile', [
            'action' => 'context',
            '--operator' => 'vitor',
            '--provider-external' => true,
            '--json' => true,
        ]);
        $context = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertCount(0, $context['items']);
        $this->assertSame('privacy_class_not_allowed', data_get($context, 'omitted.0.reason'));
    }

    public function test_review_queue_lists_pending_candidates_without_promoting(): void
    {
        Artisan::call('atlas:operator-learning', [
            'action' => 'capture',
            '--operator' => 'vitor',
            '--claim' => 'Talvez prefira resumo curto.',
            '--confidence' => '0.50',
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:operator-learning', [
            'action' => 'review',
            '--operator' => 'vitor',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertCount(1, $payload['items']);
        $this->assertSame(1, OperatorLearningCandidate::query()->pending()->count());
        $this->assertSame(0, OperatorProfileItem::query()->count());
    }

    public function test_safe_high_confidence_operator_learning_auto_applies_when_enabled_outside_shadow_mode(): void
    {
        config([
            'atlas_operator_intelligence.auto_apply_enabled' => true,
            'atlas_operator_intelligence.shadow_mode' => false,
        ]);

        $exit = Artisan::call('atlas:operator-learning', [
            'action' => 'capture',
            '--operator' => 'vitor',
            '--claim' => 'Prefiro respostas objetivas para progresso de implementação.',
            '--taxonomy' => 'COL-156',
            '--confidence' => '0.96',
            '--profile-key' => 'communication.implementation_progress',
            '--effect' => 'response_style',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue(data_get($payload, 'automation.applied'));
        $this->assertSame('all_gates_passed', data_get($payload, 'automation.reason'));
        $this->assertSame('approved', data_get($payload, 'candidate.status'));
        $this->assertSame('atlas-operator-intelligence-auto', data_get($payload, 'candidate.decided_by'));
        $this->assertSame(0, OperatorLearningCandidate::query()->pending()->count());
        $this->assertSame(1, OperatorProfileItem::query()->count());
        $this->assertSame('auto_apply_reversible', OperatorProfileItem::query()->sole()->automation_level);
        $this->assertSame(1, OperatorProfilePolicyRule::query()->count());
    }

    public function test_shadow_mode_blocks_auto_apply_even_when_candidate_is_eligible(): void
    {
        config([
            'atlas_operator_intelligence.auto_apply_enabled' => true,
            'atlas_operator_intelligence.shadow_mode' => true,
        ]);

        Artisan::call('atlas:operator-learning', [
            'action' => 'capture',
            '--operator' => 'vitor',
            '--claim' => 'Prefiro que status sejam curtos.',
            '--taxonomy' => 'COL-156',
            '--confidence' => '0.96',
            '--profile-key' => 'communication.shadow_status',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse(data_get($payload, 'automation.applied'));
        $this->assertSame('shadow_mode', data_get($payload, 'automation.reason'));
        $this->assertSame('candidate', data_get($payload, 'candidate.status'));
        $this->assertSame(1, OperatorLearningCandidate::query()->pending()->count());
        $this->assertSame(0, OperatorProfileItem::query()->count());
    }

    public function test_operator_intelligence_api_captures_reviews_and_composes_context(): void
    {
        $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

        $capture = $this->postJson('/atlas/operator-intelligence/capture', [
            'operator_id' => 'vitor',
            'claim' => 'Prefiro receber perguntas de clarificação só quando bloqueiam a execução.',
            'taxonomy_item_id' => 'COL-157',
            'confidence' => 0.91,
            'value' => [
                'profile_key' => 'collaboration.clarification_threshold',
                'effect' => 'response_style',
            ],
        ], $headers)->assertOk()->json();

        $candidateId = data_get($capture, 'candidate.id');
        $this->assertNotEmpty($candidateId);

        $this->postJson('/atlas/operator-intelligence/candidates/'.$candidateId.'/review', [
            'operator_id' => 'vitor',
            'decision' => 'approve',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('decision', 'approved')
            ->assertJsonPath('profile_item.profile_key', 'collaboration.clarification_threshold');

        $this->getJson('/atlas/operator-intelligence/context?operator_id=vitor&flow=general&provider_external=1', $headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.operator_context.v1')
            ->assertJsonPath('items.0.profile_key', 'collaboration.clarification_threshold');
    }

    public function test_open_brain_context_injection_includes_operator_profile_items(): void
    {
        OperatorProfileItem::query()->create([
            'operator_id' => 'vitor',
            'taxonomy_item_id' => 'COL-156',
            'profile_key' => 'communication.implementation_progress',
            'value' => ['effect' => 'response_style'],
            'summary' => 'Prefere progresso de implementacao curto, direto e com validacoes executadas.',
            'privacy_class' => 'normal',
            'confidence' => 0.97,
            'automation_level' => 'auto_apply_reversible',
            'status' => 'active',
        ]);

        app(\App\Services\Ai\OperatorIntelligence\OperatorProfilePolicyCompiler::class)
            ->compileOperator('vitor');

        $knowledge = $this->createMock(EngineeringKnowledgeBaseService::class);
        $knowledge->method('contextRefs')->willReturn([]);
        $code = $this->createMock(EngineeringCodeIntelligenceService::class);
        $code->method('contextRefs')->willReturn([]);

        $service = new AtlasOpenBrainContextInjectionService(
            $knowledge,
            $code,
            null,
            null,
            app(OperatorContextComposer::class),
        );

        $task = AiTaskRequest::fromInput('implementar Operator Intelligence', [
            'source_type' => 'manual',
            'payload' => [
                'atlas_workflow_mode' => 'dev',
                'routing_task' => 'programming.dev',
                'operator_id' => 'vitor',
            ],
        ], ['agent' => 'desenvolvedor', 'intent' => 'test']);

        $pack = new AiContextPack([
            'task' => [
                'type' => 'dev',
                'desired_mode' => 'dev',
                'risk_level' => 'low',
                'domain' => 'developer',
                'objective' => 'implementar Operator Intelligence',
            ],
            'surface' => ['kind' => 'mac_cli', 'workspace' => base_path()],
            'memory' => ['semantic' => []],
            'constraints' => [],
        ], []);

        $result = $service->inject('implementar Operator Intelligence', $task, $pack, [
            'source_type' => 'manual',
            'payload' => [
                'atlas_workflow_mode' => 'dev',
                'routing_task' => 'programming.dev',
                'operator_id' => 'vitor',
            ],
        ]);

        $this->assertSame(1, data_get($result, 'summary.operator_context.item_count'));
        $this->assertSame(1, data_get($result, 'summary.operator_profile_refs'));
        $this->assertStringContainsString('## Operator Intelligence', $result['prompt_section'] ?? '');
        $this->assertStringContainsString('communication.implementation_progress', $result['prompt_section'] ?? '');
        $this->assertSame(1, OperatorProfileFeedbackEvent::query()->count());
    }
}
