<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Learning;

use App\Models\AiLearningProposal;
use App\Models\AiLearningSignal;
use App\Services\Ai\Learning\AtlasAiLearningLoopService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class LearningConsolidationF0CharacterizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('ai_learning_proposals');
        Schema::dropIfExists('ai_learning_signals');

        Schema::create('ai_learning_signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 80);
            $table->string('signal_id', 80)->unique();
            $table->string('source_type', 60);
            $table->json('evidence_refs');
            $table->boolean('requires_review')->default(true);
            $table->string('risk_level', 16);
            $table->string('status', 24);
            $table->string('signal_hash', 64)->unique();
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_learning_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 80);
            $table->string('kind', 40);
            $table->string('status', 32);
            $table->string('scope', 120);
            $table->text('summary');
            $table->json('evidence_refs');
            $table->boolean('requires_human_review')->default(true);
            $table->string('proposal_hash', 64)->unique();
            $table->string('decided_by', 120)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_learning_proposals');
        Schema::dropIfExists('ai_learning_signals');

        parent::tearDown();
    }

    public function test_learning_command_and_control_plane_contracts_are_frozen_for_m1a(): void
    {
        $collect = $this->runLearningCommand('collect', ['--hours' => 1]);
        $this->assertSame([
            'schema_version' => 'atlas.ai.learning_signal.v1',
            'summary' => [
                'signals_collected' => 0,
                'proposals_minted' => 0,
                'proposals_skipped_missing_evidence' => 0,
                'by_source' => [],
            ],
            'signals' => [],
        ], array_intersect_key($collect, array_flip(['schema_version', 'summary', 'signals'])));
        $this->assertSame(['hours', 'since', 'until'], array_keys($collect['window']));

        $signal = $this->missingEvidenceSignal();
        $proposal = $this->pendingProposal();
        $list = $this->runLearningCommand('list', [
            '--source-type' => 'trace_failed',
            '--risk-level' => 'critical',
            '--status' => 'collected',
            '--proposal-status' => 'proposed',
            '--kind' => 'policy',
            '--limit' => 1,
        ]);
        $this->assertSame([
            'schema_version' => 'atlas.ai.learning_signal.v1',
            'counts' => ['signals' => 1, 'proposals' => 1],
        ], array_intersect_key($list, array_flip(['schema_version', 'counts'])));
        $this->assertSame([
            'id', 'signal_id', 'source_type', 'source_id', 'mission_id', 'flow_id', 'outcome',
            'quality_score', 'failure_mode', 'blocker_reason', 'approval_decision', 'evidence_refs',
            'requires_review', 'risk_level', 'status', 'learning_proposal_id', 'signal_hash', 'collected_at',
        ], array_keys($list['signals'][0]));
        $this->assertSame([
            'signal_id' => $signal->signal_id,
            'source_type' => 'trace_failed',
            'evidence_refs' => [],
            'requires_review' => true,
            'risk_level' => 'critical',
            'status' => 'collected',
            'signal_hash' => $signal->signal_hash,
        ], array_intersect_key($list['signals'][0], array_flip([
            'signal_id', 'source_type', 'evidence_refs', 'requires_review', 'risk_level', 'status', 'signal_hash',
        ])));
        $this->assertSame([
            'id', 'kind', 'status', 'scope', 'flow_id', 'summary',
            'requires_human_review', 'decided_by', 'decided_at', 'proposal_hash', 'evidence_refs',
        ], array_keys($list['proposals'][0]));
        $this->assertSame($proposal->id, $list['proposals'][0]['id']);
        $this->assertSame('proposed', $list['proposals'][0]['status']);

        $summary = $this->app->make(AtlasAiLearningLoopService::class)
            ->controlPlaneSummary(now()->subHour()->toImmutable());
        $this->assertSame([
            'status' => 'ready',
            'signals' => [
                'total' => 1,
                'by_source' => ['trace_failed' => 1],
                'by_risk_level' => ['critical' => 1],
                'by_status' => ['collected' => 1],
            ],
            'proposals' => ['total' => 1, 'by_status' => ['proposed' => 1], 'by_kind' => ['policy' => 1], 'pending_review' => 1],
            'blockers' => ['missing_evidence_signals' => 1],
        ], array_intersect_key($summary, array_flip(['status', 'signals', 'proposals', 'blockers'])));
        $this->assertIsString($summary['last_signal_at']);
        $this->assertIsString($summary['last_proposal_at']);

        $review = $this->runLearningCommand('review', [
            '--proposal' => $proposal->id,
            '--decision' => 'approve',
            '--operator' => 'god-debulk-f0',
        ]);
        $this->assertSame([
            'ok' => true,
            'proposal_id' => $proposal->id,
            'kind' => 'policy',
            'status' => 'approved',
            'decided_by' => 'god-debulk-f0',
        ], array_intersect_key($review, array_flip(['ok', 'proposal_id', 'kind', 'status', 'decided_by'])));
        $this->assertArrayHasKey('decided_at', $review);
    }

    public function test_learning_review_error_contracts_are_frozen_for_m1a(): void
    {
        $missing = $this->invokeLearningCommand('review');
        $this->assertSame(1, $missing['exit']);
        $this->assertSame([
            'ok' => false,
            'error' => 'missing_required_options',
            'required' => ['proposal', 'decision'],
        ], $missing['payload']);

        $proposal = $this->pendingProposal();
        $invalid = $this->invokeLearningCommand('review', [
            '--proposal' => $proposal->id,
            '--decision' => 'defer',
        ]);
        $this->assertSame(1, $invalid['exit']);
        $this->assertSame([
            'ok' => false,
            'error' => 'invalid_decision',
            'expected' => ['approve', 'reject'],
        ], $invalid['payload']);

        $notFound = $this->invokeLearningCommand('review', [
            '--proposal' => 'learning-f0-missing',
            '--decision' => 'approve',
        ]);
        $this->assertSame(1, $notFound['exit']);
        $this->assertSame([
            'ok' => false,
            'error' => 'proposal_not_found',
            'proposal_id' => 'learning-f0-missing',
        ], $notFound['payload']);

        $approved = $this->invokeLearningCommand('review', [
            '--proposal' => $proposal->id,
            '--decision' => 'approve',
            '--operator' => 'god-debulk-f0',
        ]);
        $this->assertSame(0, $approved['exit']);

        $alreadyDecided = $this->invokeLearningCommand('review', [
            '--proposal' => $proposal->id,
            '--decision' => 'reject',
        ]);
        $this->assertSame(1, $alreadyDecided['exit']);
        $this->assertSame([
            'ok' => false,
            'error' => 'already_decided',
            'status' => 'approved',
        ], $alreadyDecided['payload']);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function runLearningCommand(string $action, array $options = []): array
    {
        $result = $this->invokeLearningCommand($action, $options);

        $this->assertSame(0, $result['exit']);

        return $result['payload'];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{exit:int,payload:array<string,mixed>}
     */
    private function invokeLearningCommand(string $action, array $options = []): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:ai:learning', array_merge([
            'action' => $action,
            '--json' => true,
        ], $options), $output);

        return [
            'exit' => $exit,
            'payload' => json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private function missingEvidenceSignal(): AiLearningSignal
    {
        return AiLearningSignal::query()->create([
            'id' => Str::uuid()->toString(),
            'schema_version' => AiLearningSignal::SCHEMA_VERSION,
            'signal_id' => 'als_learning_f0_missing_evidence',
            'source_type' => 'trace_failed',
            'evidence_refs' => [],
            'requires_review' => true,
            'risk_level' => AiLearningSignal::RISK_CRITICAL,
            'status' => AiLearningSignal::STATUS_COLLECTED,
            'signal_hash' => hash('sha256', 'learning-f0-missing-evidence'),
            'collected_at' => now(),
        ]);
    }

    private function pendingProposal(): AiLearningProposal
    {
        return AiLearningProposal::query()->create([
            'id' => Str::uuid()->toString(),
            'schema_version' => 'atlas.ai.compounding.learning_proposal.v1',
            'kind' => 'policy',
            'status' => 'proposed',
            'scope' => 'atlas_ai_runtime',
            'summary' => 'GOD-DEBULK F0 learning characterization proposal',
            'evidence_refs' => [['type' => 'test', 'id' => 'learning-f0']],
            'requires_human_review' => true,
            'proposal_hash' => hash('sha256', 'learning-f0:'.Str::random(24)),
        ]);
    }
}
