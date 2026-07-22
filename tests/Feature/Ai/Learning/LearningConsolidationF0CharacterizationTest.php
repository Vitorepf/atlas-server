<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Learning;

use App\Models\AiLearningProposal;
use App\Models\AiLearningSignal;
use App\Services\Ai\Learning\AtlasAiLearningLoopService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class LearningConsolidationF0CharacterizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('ai_learning_signals');
        Schema::dropIfExists('ai_learning_proposals');
        Schema::dropIfExists('ai_missions');

        $proposalMigration = require base_path('database/migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php');
        $proposalMigration->up();
        $signalMigration = require base_path('database/migrations/2026_05_19_140000_create_ai_learning_signals_table.php');
        $signalMigration->up();

        Schema::create('ai_missions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('uuid', 80)->nullable();
            $table->string('status', 40)->nullable();
            $table->string('primary_domain', 80)->nullable();
            $table->string('risk_level', 40)->nullable();
            $table->text('blocker_reason')->nullable();
            $table->string('evidence_pack_hash', 64)->nullable();
            $table->string('certification_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_missions');
        Schema::dropIfExists('ai_learning_proposals');
        Schema::dropIfExists('ai_learning_signals');

        parent::tearDown();
    }

    public function test_learning_command_and_control_plane_contracts_are_frozen_for_m1a(): void
    {
        $this->insertCompletedMission();
        $collect = $this->runLearningCommand('collect', ['--hours' => 1]);
        $this->assertSame(['schema_version', 'generated_at', 'window', 'summary', 'signals'], array_keys($collect));
        $this->assertSame([
            'signals_collected' => 1,
            'proposals_minted' => 1,
            'proposals_skipped_missing_evidence' => 0,
            'by_source' => ['mission_completed' => 1],
        ], $collect['summary']);
        $this->assertSame('atlas.ai.learning_signal.v1', $collect['schema_version']);
        $this->assertSame(['hours', 'since', 'until'], array_keys($collect['window']));
        $this->assertSame([
            'signal_id', 'source_type', 'risk_level', 'status', 'requires_review', 'proposal_id', 'proposal_skipped_reason',
        ], array_keys($collect['signals'][0]));
        $this->assertSame([
            'source_type' => 'mission_completed',
            'risk_level' => 'low',
            'status' => 'proposed',
            'requires_review' => true,
            'proposal_skipped_reason' => null,
        ], array_intersect_key($collect['signals'][0], array_flip([
            'source_type', 'risk_level', 'status', 'requires_review', 'proposal_skipped_reason',
        ])));
        $this->assertIsString($collect['signals'][0]['proposal_id']);

        $signal = $this->missingEvidenceSignal();
        $this->learningSignal('older', ['collected_at' => now()->subMinute()]);
        $this->learningSignal('other-source', ['source_type' => 'mission_failed']);
        $this->learningSignal('other-flow', ['flow_id' => 'atlas_other']);
        $this->learningSignal('other-risk', ['risk_level' => 'low']);
        $this->learningSignal('other-status', ['status' => AiLearningSignal::STATUS_PROPOSED]);
        $this->pendingProposal('older', [], now()->subMinute());
        $proposal = $this->pendingProposal('matching', [], now()->addMinute());
        $this->pendingProposal('other-status', ['status' => 'approved'], now()->subMinutes(2));
        $this->pendingProposal('other-kind', ['kind' => 'routing'], now()->subMinutes(3));

        $bySource = $this->runLearningCommand('list', ['--source-type' => 'trace_failed', '--limit' => 20]);
        $this->assertSame(5, $bySource['counts']['signals']);
        $byFlow = $this->runLearningCommand('list', ['--source-type' => 'trace_failed', '--flow-id' => 'atlas_learning', '--limit' => 20]);
        $this->assertSame(4, $byFlow['counts']['signals']);
        $byRisk = $this->runLearningCommand('list', ['--source-type' => 'trace_failed', '--flow-id' => 'atlas_learning', '--risk-level' => 'critical', '--limit' => 20]);
        $this->assertSame(3, $byRisk['counts']['signals']);
        $byStatus = $this->runLearningCommand('list', ['--source-type' => 'trace_failed', '--flow-id' => 'atlas_learning', '--risk-level' => 'critical', '--status' => 'collected', '--limit' => 20]);
        $this->assertSame(2, $byStatus['counts']['signals']);
        $proposalStatus = $this->runLearningCommand('list', ['--proposal-status' => 'proposed', '--limit' => 20]);
        $this->assertSame(4, $proposalStatus['counts']['proposals']);
        $proposalKind = $this->runLearningCommand('list', ['--kind' => 'policy', '--limit' => 20]);
        $this->assertSame(3, $proposalKind['counts']['proposals']);

        $unlimited = $this->runLearningCommand('list', [
            '--source-type' => 'trace_failed',
            '--flow-id' => 'atlas_learning',
            '--risk-level' => 'critical',
            '--status' => 'collected',
            '--proposal-status' => 'proposed',
            '--kind' => 'policy',
            '--limit' => 20,
        ]);
        $this->assertSame(['signals' => 2, 'proposals' => 2], $unlimited['counts']);

        $list = $this->runLearningCommand('list', [
            '--source-type' => 'trace_failed',
            '--flow-id' => 'atlas_learning',
            '--risk-level' => 'critical',
            '--status' => 'collected',
            '--proposal-status' => 'proposed',
            '--kind' => 'policy',
            '--limit' => 1,
        ]);
        $this->assertSame(['schema_version', 'generated_at', 'signals', 'proposals', 'counts'], array_keys($list));
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
            'id' => $signal->id,
            'signal_id' => $signal->signal_id,
            'source_type' => 'trace_failed',
            'source_id' => 'trace-matching',
            'mission_id' => $signal->mission_id,
            'flow_id' => 'atlas_learning',
            'outcome' => 'failed',
            'quality_score' => 17,
            'failure_mode' => 'provider_timeout',
            'blocker_reason' => 'retry_exhausted',
            'approval_decision' => 'none',
            'evidence_refs' => [],
            'requires_review' => true,
            'risk_level' => 'critical',
            'status' => 'collected',
            'learning_proposal_id' => null,
            'signal_hash' => $signal->signal_hash,
            'collected_at' => $signal->collected_at?->toJSON(),
        ], $list['signals'][0]);
        $this->assertSame([
            'id', 'kind', 'status', 'scope', 'flow_id', 'summary',
            'requires_human_review', 'decided_by', 'decided_at', 'proposal_hash', 'evidence_refs',
        ], array_keys($list['proposals'][0]));
        $this->assertSame([
            'id' => $proposal->id,
            'kind' => 'policy',
            'status' => 'proposed',
            'scope' => 'atlas_ai_runtime',
            'flow_id' => 'atlas_learning',
            'summary' => 'GOD-DEBULK F0 learning characterization proposal matching',
            'requires_human_review' => true,
            'decided_by' => null,
            'decided_at' => null,
            'proposal_hash' => $proposal->proposal_hash,
            'evidence_refs' => [['type' => 'test', 'id' => 'learning-f0-matching']],
        ], $list['proposals'][0]);

        $summary = $this->app->make(AtlasAiLearningLoopService::class)
            ->controlPlaneSummary(now()->subHour()->toImmutable());
        $this->assertSame([
            'status', 'signals', 'proposals', 'blockers', 'last_signal_at', 'last_proposal_at',
        ], array_keys($summary));
        $this->assertSame([
            'status' => 'ready',
            'signals' => [
                'total' => 7,
                'by_source' => ['mission_completed' => 1, 'mission_failed' => 1, 'trace_failed' => 5],
                'by_risk_level' => ['critical' => 5, 'low' => 2],
                'by_status' => ['collected' => 5, 'proposed' => 2],
            ],
            'proposals' => ['total' => 5, 'by_status' => ['approved' => 1, 'proposed' => 4], 'by_kind' => ['heuristic' => 1, 'policy' => 3, 'routing' => 1], 'pending_review' => 4],
            'blockers' => ['missing_evidence_signals' => 1],
        ], array_intersect_key($this->canonicalControlPlaneSummary($summary), array_flip(['status', 'signals', 'proposals', 'blockers'])));
        $this->assertIsString($summary['last_signal_at']);
        $this->assertIsString($summary['last_proposal_at']);

        $review = $this->runLearningCommand('review', [
            '--proposal' => $proposal->id,
            '--decision' => 'approve',
            '--operator' => 'god-debulk-f0',
        ]);
        $this->assertSame(['ok', 'proposal_id', 'kind', 'status', 'decided_by', 'decided_at'], array_keys($review));
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
        $unsupported = $this->invokeLearningCommand('archive');
        $this->assertSame(1, $unsupported['exit']);
        $this->assertSame([
            'ok' => false,
            'error' => 'invalid_arguments',
            'message' => 'unsupported action [archive]; supported: collect | list | review',
        ], $unsupported['payload']);

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

        Schema::dropIfExists('ai_learning_proposals');
        $tableMissing = $this->invokeLearningCommand('review', [
            '--proposal' => $proposal->id,
            '--decision' => 'approve',
        ]);
        $this->assertSame(1, $tableMissing['exit']);
        $this->assertSame(['ok' => false, 'error' => 'table_missing'], $tableMissing['payload']);
    }

    public function test_control_plane_missing_and_degraded_contracts_are_frozen_for_m1a(): void
    {
        Schema::dropIfExists('ai_learning_signals');

        $empty = [
            'status' => 'missing',
            'signals' => ['total' => 0, 'by_source' => [], 'by_risk_level' => [], 'by_status' => []],
            'proposals' => ['total' => 0, 'by_status' => [], 'by_kind' => [], 'pending_review' => 0],
            'blockers' => ['missing_evidence_signals' => 0],
            'last_signal_at' => null,
            'last_proposal_at' => null,
        ];
        $service = $this->app->make(AtlasAiLearningLoopService::class);
        $this->assertSame($empty, $service->controlPlaneSummary(now()->subHour()->toImmutable()));

        $signalMigration = require base_path('database/migrations/2026_05_19_140000_create_ai_learning_signals_table.php');
        $signalMigration->up();
        $connection = DB::connection();
        $previousDispatcher = $connection->getEventDispatcher();
        $dispatcher = new Dispatcher($this->app);
        // The schema check remains real; only the Eloquent read is faulted so
        // the public degraded envelope is tested on SQLite as well as drivers
        // that naturally report malformed reads.
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'ai_learning_signals') && str_contains($query->sql, 'collected_at')) {
                throw new RuntimeException('learning M1a query fault fixture');
            }
        });
        $connection->setEventDispatcher($dispatcher);

        try {
            $this->assertSame(['status' => 'degraded'] + $empty, $service->controlPlaneSummary(now()->subHour()->toImmutable()));
        } finally {
            $connection->setEventDispatcher($previousDispatcher);
        }
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
        return $this->learningSignal('matching', [
            'evidence_refs' => [],
            'collected_at' => now()->addMinutes(2),
        ]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function learningSignal(string $label, array $overrides = []): AiLearningSignal
    {
        return AiLearningSignal::query()->create(array_merge([
            'id' => Str::uuid()->toString(),
            'schema_version' => AiLearningSignal::SCHEMA_VERSION,
            'signal_id' => 'als_learning_f0_'.$label,
            'source_type' => 'trace_failed',
            'source_id' => 'trace-'.$label,
            'mission_id' => Str::uuid()->toString(),
            'flow_id' => 'atlas_learning',
            'outcome' => 'failed',
            'quality_score' => 17,
            'failure_mode' => 'provider_timeout',
            'blocker_reason' => 'retry_exhausted',
            'approval_decision' => 'none',
            'evidence_refs' => [['type' => 'trace_id', 'id' => 'trace-'.$label]],
            'requires_review' => true,
            'risk_level' => AiLearningSignal::RISK_CRITICAL,
            'status' => AiLearningSignal::STATUS_COLLECTED,
            'signal_hash' => hash('sha256', 'learning-f0-'.$label),
            'collected_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function pendingProposal(string $label = 'review', array $overrides = [], mixed $updatedAt = null): AiLearningProposal
    {
        $proposal = AiLearningProposal::query()->create(array_merge([
            'id' => Str::uuid()->toString(),
            'schema_version' => 'atlas.ai.compounding.learning_proposal.v1',
            'kind' => 'policy',
            'status' => 'proposed',
            'scope' => 'atlas_ai_runtime',
            'flow_id' => 'atlas_learning',
            'summary' => 'GOD-DEBULK F0 learning characterization proposal '.$label,
            'evidence_refs' => [['type' => 'test', 'id' => 'learning-f0-'.$label]],
            'requires_human_review' => true,
            'proposal_hash' => hash('sha256', 'learning-f0:'.$label.':'.Str::random(24)),
        ], $overrides));

        if ($updatedAt !== null) {
            $proposal->timestamps = false;
            $proposal->updated_at = $updatedAt;
            $proposal->save();
        }

        return $proposal->fresh();
    }

    private function insertCompletedMission(): void
    {
        DB::table('ai_missions')->insert([
            'id' => Str::uuid()->toString(),
            'uuid' => 'learning-m1a-completed-mission',
            'status' => 'completed',
            'primary_domain' => 'research',
            'risk_level' => 'low',
            'evidence_pack_hash' => str_repeat('a', 64),
            'certification_hash' => str_repeat('b', 64),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function canonicalControlPlaneSummary(array $summary): array
    {
        foreach ([
            ['signals', 'by_source'],
            ['signals', 'by_risk_level'],
            ['signals', 'by_status'],
            ['proposals', 'by_status'],
            ['proposals', 'by_kind'],
        ] as [$section, $key]) {
            if (isset($summary[$section][$key]) && is_array($summary[$section][$key])) {
                ksort($summary[$section][$key]);
            }
        }

        return $summary;
    }
}
