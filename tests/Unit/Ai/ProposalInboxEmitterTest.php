<?php

namespace Tests\Unit\Ai;

use App\Models\AiContextBundle;
use App\Models\AiInboxItem;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\Mobile\ContextBundleService;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProposalInboxEmitterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ai_context_bundles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestamps();
        });

        Schema::create('ai_inbox_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('dedupe_key')->nullable();
            $table->string('status')->default('unread');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_inbox_items');
        Schema::dropIfExists('ai_context_bundles');

        parent::tearDown();
    }

    public function test_proposal_preserves_review_signal_contract_in_bundle_and_inbox_payload(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000001';

                return $bundle;
            }
        };

        $inbox = new class extends AtlasInboxService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function __construct() {}

            public function create(array $data): AiInboxItem
            {
                $this->created = $data;

                $item = new AiInboxItem;
                $item->id = '00000000-0000-0000-0000-000000000002';
                $item->payload = $data['payload'];
                $item->context_bundle_id = $data['context_bundle_id'];

                return $item;
            }
        };

        $item = (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Corrigir fontes obrigatorias ausentes no Open Brain',
            'category' => 'self_improvement',
            'problem' => 'Open Brain bloqueou contexto obrigatorio.',
            'solution' => 'Atualizar evidence replay antes de repetir.',
            'worth_it' => 'Evita execucao com contexto fraco.',
            'dedupe_key' => 'self-improvement:open-brain-retrieval:test',
            'confidence' => 0.88,
            'source_refs' => [[
                'type' => 'open_brain_access_log',
                'id' => 'log-1',
                'required_unavailable_sources' => ['evidence_replay'],
            ]],
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.open_brain_retrieval.v1',
                'review_signal' => [
                    'status' => 'blocking',
                    'severity' => 'high',
                    'recommended_action' => 'refresh_evidence_replay_or_attach_trace_before_retry',
                ],
            ],
        ]);

        $this->assertInstanceOf(AiInboxItem::class, $item);
        $this->assertSame('atlas.self_improvement.open_brain_retrieval.v1', data_get($inbox->created, 'payload.proposal_contract.schema_version'));
        $this->assertSame('blocking', data_get($inbox->created, 'payload.proposal_contract.review_signal.status'));
        $this->assertSame('refresh_evidence_replay_or_attach_trace_before_retry', data_get($inbox->created, 'payload.proposal_contract.review_signal.recommended_action'));
        $this->assertSame('open_brain_access_log', data_get($inbox->created, 'payload.proposal_contract.source_refs.0.type'));
        $this->assertSame('critical', data_get($inbox->created, 'severity'));
        $this->assertSame(85, data_get($inbox->created, 'priority_score'));
        $this->assertSame('atlas.self_improvement.open_brain_retrieval.v1', data_get($bundles->created, 'raw_payload.proposal_contract.schema_version'));
        $this->assertSame('blocking', data_get($bundles->created, 'raw_payload.proposal_contract.review_signal.status'));
        $this->assertSame('open_brain_access_log', data_get($bundles->created, 'raw_payload.proposal_contract.source_refs.0.type'));
    }

    public function test_proposal_maps_review_signal_to_inbox_severity_and_priority(): void
    {
        $bundles = new class extends ContextBundleService
        {
            public function create(array $data): AiContextBundle
            {
                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000003';

                return $bundle;
            }
        };

        $inbox = new class extends AtlasInboxService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function __construct() {}

            public function create(array $data): AiInboxItem
            {
                $this->created = $data;

                $item = new AiInboxItem;
                $item->id = '00000000-0000-0000-0000-000000000004';

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Restaurar proposta critica',
            'problem' => 'Finding de alta severidade precisa aparecer como urgente.',
            'solution' => 'Propagar review_signal para severidade e prioridade do Inbox.',
            'worth_it' => 'Evita que reparos importantes parecam informativos.',
            'dedupe_key' => 'proposal:review-signal-severity:test',
            'metadata' => [
                'review_signal' => [
                    'status' => 'blocking',
                    'severity' => 'high',
                    'recommended_action' => 'restore_or_reemit_missing_self_improvement_inbox_items',
                ],
            ],
        ]);

        $this->assertSame('critical', data_get($inbox->created, 'severity'));
        $this->assertSame(85, data_get($inbox->created, 'priority_score'));
        $this->assertSame('high', data_get($inbox->created, 'payload.proposal_contract.review_signal.severity'));
    }

    public function test_proposal_preserves_custom_actions_and_payload_for_assisted_operations(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000005';

                return $bundle;
            }
        };

        $inbox = new class extends AtlasInboxService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function __construct() {}

            public function create(array $data): AiInboxItem
            {
                $this->created = $data;

                $item = new AiInboxItem;
                $item->id = '00000000-0000-0000-0000-000000000006';
                $item->payload = $data['payload'];
                $item->available_actions = $data['available_actions'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Rodar projection assistida',
            'problem' => 'Read models estao atrasados em relacao ao Evidence Ledger.',
            'solution' => 'Executar backfill seguro pelo Inbox.',
            'worth_it' => 'Fecha o loop sem sair do fluxo operacional.',
            'dedupe_key' => 'proposal:ledger-projection-action:test',
            'available_actions' => [
                ['id' => 'run_ledger_projection', 'label' => 'Rodar projection', 'style' => 'primary'],
                ['id' => 'review_patch', 'label' => 'Revisar evidencia', 'style' => 'secondary'],
            ],
            'payload' => [
                'projection_health' => [
                    'schema_version' => 'atlas.ledger_projection_drift.v1',
                    'status' => 'attention_required',
                ],
                'ledger_projection' => [
                    'hours' => 24,
                    'limit' => 500,
                ],
            ],
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.ledger_projection_drift.v1',
                'review_signal' => [
                    'status' => 'warning',
                    'severity' => 'medium',
                    'recommended_action' => 'open_reviewable_ledger_projection_backfill_proposal',
                ],
            ],
        ]);

        $this->assertSame('run_ledger_projection', data_get($inbox->created, 'available_actions.0.id'));
        $this->assertSame('review_patch', data_get($inbox->created, 'available_actions.1.id'));
        $this->assertSame('discuss', data_get($inbox->created, 'available_actions.2.id'));
        $this->assertSame('attention_required', data_get($inbox->created, 'payload.projection_health.status'));
        $this->assertSame(24, data_get($inbox->created, 'payload.ledger_projection.hours'));
        $this->assertSame('atlas.self_improvement.ledger_projection_drift.v1', data_get($inbox->created, 'payload.proposal_contract.schema_version'));
        $this->assertSame('attention_required', data_get($bundles->created, 'raw_payload.projection_health.status'));
    }
}
