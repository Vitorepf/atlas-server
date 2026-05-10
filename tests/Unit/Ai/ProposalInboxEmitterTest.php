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

    public function test_proposal_refs_cannot_smuggle_runtime_payloads(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000037';

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
                $item->id = '00000000-0000-0000-0000-000000000038';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Refs com payload perigoso',
            'problem' => 'Evidence refs tentaram carregar comandos.',
            'solution' => 'Remover chaves executaveis de refs.',
            'worth_it' => 'Mantem refs como evidencia fria.',
            'dedupe_key' => 'proposal:refs-runtime-payload-safety:test',
            'source_refs' => [[
                'type' => 'ledger_event',
                'id' => 'evt-1',
                'required_unavailable_sources' => ['evidence_replay'],
                'command' => 'php artisan migrate',
                'payload' => ['auto_apply' => true],
                'nested' => [
                    'provider_direct_channel' => true,
                    'path' => 'docs/ap/AP-144-rivals-review-inbox.md',
                ],
            ]],
        ]);

        $expected = [[
            'type' => 'ledger_event',
            'id' => 'evt-1',
            'required_unavailable_sources' => ['evidence_replay'],
            'nested' => [
                'path' => 'docs/ap/AP-144-rivals-review-inbox.md',
            ],
        ]];

        $this->assertSame($expected, data_get($bundles->created, 'source_refs'));
        $this->assertSame($expected, data_get($inbox->created, 'payload.proposal_contract.source_refs'));
        $this->assertSame($expected, data_get($bundles->created, 'raw_payload.proposal_contract.source_refs'));
    }

    public function test_proposal_schema_version_rejects_payload_shapes(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000043';

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
                $item->id = '00000000-0000-0000-0000-000000000044';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Schema version com payload perigoso',
            'problem' => 'Metadata tentou usar schema_version como payload composto.',
            'solution' => 'Aceitar somente string simples.',
            'worth_it' => 'Mantem contrato documental serializavel.',
            'dedupe_key' => 'proposal:schema-version-shape:test',
            'metadata' => [
                'schema_version' => [
                    'id' => 'atlas.self_improvement.test.v1',
                    'command' => 'php artisan migrate',
                ],
            ],
        ]);

        $this->assertNull(data_get($inbox->created, 'payload.proposal_contract.schema_version'));
        $this->assertNull(data_get($bundles->created, 'raw_payload.proposal_contract.schema_version'));
    }

    public function test_proposal_branch_rejects_shell_or_path_traversal_values(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<int,array<string,mixed>> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created[] = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000045';

                return $bundle;
            }
        };

        $inbox = new class extends AtlasInboxService
        {
            /** @var array<int,array<string,mixed>> */
            public array $created = [];

            public function __construct() {}

            public function create(array $data): AiInboxItem
            {
                $this->created[] = $data;

                $item = new AiInboxItem;
                $item->id = '00000000-0000-0000-0000-000000000046';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        $emitter = new ProposalInboxEmitter($bundles, $inbox);

        foreach (['feature/proposal-safe', 'feature/safe;php_artisan_migrate', '../main', '/absolute'] as $index => $branch) {
            $emitter->emit([
                'title' => "Branch {$index}",
                'problem' => 'Branch externa veio de payload.',
                'solution' => 'Aceitar somente refname simples.',
                'worth_it' => 'Evita branch virar vetor de comando/path.',
                'dedupe_key' => "proposal:branch-safety:{$index}",
                'branch' => $branch,
            ]);
        }

        $this->assertSame('feature/proposal-safe', data_get($inbox->created, '0.payload.branch'));
        $this->assertSame('feature/proposal-safe', data_get($bundles->created, '0.raw_payload.branch'));
        $this->assertNull(data_get($inbox->created, '1.payload.branch'));
        $this->assertNull(data_get($inbox->created, '2.payload.branch'));
        $this->assertNull(data_get($inbox->created, '3.payload.branch'));
        $this->assertNull(data_get($bundles->created, '1.raw_payload.branch'));
    }

    public function test_proposal_dedupe_key_rejects_shell_or_path_traversal_values(): void
    {
        $bundles = new class extends ContextBundleService
        {
            public function create(array $data): AiContextBundle
            {
                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000047';

                return $bundle;
            }
        };

        $inbox = new class extends AtlasInboxService
        {
            /** @var array<int,array<string,mixed>> */
            public array $created = [];

            public function __construct() {}

            public function create(array $data): AiInboxItem
            {
                $this->created[] = $data;

                $item = new AiInboxItem;
                $item->id = '00000000-0000-0000-0000-000000000048';
                $item->dedupe_key = $data['dedupe_key'];

                return $item;
            }
        };

        $emitter = new ProposalInboxEmitter($bundles, $inbox);

        $validTitle = 'Dedupe valido';
        $validProblem = 'Dedupe key canonica deve ser preservada.';
        $validSolution = 'Aceitar chave segura.';

        $emitter->emit([
            'title' => $validTitle,
            'problem' => $validProblem,
            'solution' => $validSolution,
            'worth_it' => 'Evita duplicacao.',
            'dedupe_key' => 'self-improvement:inbox-action-replay:'.sha1('safe'),
        ]);

        $unsafeTitle = 'Dedupe inseguro';
        $unsafeProblem = 'Dedupe key tentou embutir comando.';
        $unsafeSolution = 'Gerar fallback deterministico.';

        $emitter->emit([
            'title' => $unsafeTitle,
            'problem' => $unsafeProblem,
            'solution' => $unsafeSolution,
            'worth_it' => 'Evita vetor operacional em dedupe.',
            'dedupe_key' => 'proposal:test;php_artisan_migrate',
        ]);

        $this->assertSame('self-improvement:inbox-action-replay:'.sha1('safe'), data_get($inbox->created, '0.dedupe_key'));
        $this->assertSame(
            'proposal:'.md5($unsafeTitle.'|'.$unsafeProblem.'|'.$unsafeSolution),
            data_get($inbox->created, '1.dedupe_key'),
        );
    }

    public function test_proposal_confidence_rejects_non_scalar_or_out_of_range_values(): void
    {
        $bundles = new class extends ContextBundleService
        {
            public function create(array $data): AiContextBundle
            {
                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000039';

                return $bundle;
            }
        };

        $inbox = new class extends AtlasInboxService
        {
            /** @var array<int,array<string,mixed>> */
            public array $created = [];

            public function __construct() {}

            public function create(array $data): AiInboxItem
            {
                $this->created[] = $data;

                $item = new AiInboxItem;
                $item->id = '00000000-0000-0000-0000-000000000040';

                return $item;
            }
        };

        $emitter = new ProposalInboxEmitter($bundles, $inbox);

        foreach ([['score' => 0.9], 1.4, -0.1, 'not-numeric'] as $index => $confidence) {
            $emitter->emit([
                'title' => "Confidence insegura {$index}",
                'problem' => 'Confidence externa tentou usar shape invalido.',
                'solution' => 'Aceitar somente numero normalizado entre 0 e 1.',
                'worth_it' => 'Evita prioridade opaca por payload externo.',
                'dedupe_key' => "proposal:confidence-invalid:{$index}",
                'confidence' => $confidence,
            ]);
        }

        $emitter->emit([
            'title' => 'Confidence valida',
            'problem' => 'Confidence numerica deve ser preservada.',
            'solution' => 'Aceitar escalar numerico dentro do range.',
            'worth_it' => 'Mantem sinal util sem aceitar payload composto.',
            'dedupe_key' => 'proposal:confidence-valid:test',
            'confidence' => '0.42',
        ]);

        $this->assertNull(data_get($inbox->created, '0.confidence_score'));
        $this->assertNull(data_get($inbox->created, '1.confidence_score'));
        $this->assertNull(data_get($inbox->created, '2.confidence_score'));
        $this->assertNull(data_get($inbox->created, '3.confidence_score'));
        $this->assertSame(0.42, data_get($inbox->created, '4.confidence_score'));
    }

    public function test_proposal_reuses_existing_active_item_for_same_dedupe_key(): void
    {
        $existing = new AiInboxItem;
        $existing->id = '00000000-0000-0000-0000-000000000009';
        $existing->dedupe_key = 'proposal:dedupe:test';
        $existing->status = 'unread';
        $existing->created_at = now();
        $existing->updated_at = now();
        $existing->save();

        $bundles = new class extends ContextBundleService
        {
            public bool $created = false;

            public function create(array $data): AiContextBundle
            {
                $this->created = true;

                return new AiContextBundle;
            }
        };

        $inbox = new class extends AtlasInboxService
        {
            public bool $created = false;

            public function __construct() {}

            public function create(array $data): AiInboxItem
            {
                $this->created = true;

                return new AiInboxItem;
            }
        };

        $item = (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Proposta duplicada',
            'problem' => 'Mesmo dedupe key ativo.',
            'solution' => 'Reusar item aberto.',
            'worth_it' => 'Evita spam operacional.',
            'dedupe_key' => 'proposal:dedupe:test',
        ]);

        $this->assertSame($existing->id, $item?->id);
        $this->assertFalse($bundles->created);
        $this->assertFalse($inbox->created);
        $this->assertSame(1, AiInboxItem::query()->where('dedupe_key', 'proposal:dedupe:test')->count());
    }

    public function test_proposal_emit_fails_closed_when_required_tables_are_missing(): void
    {
        Schema::dropIfExists('ai_inbox_items');

        $bundles = new class extends ContextBundleService
        {
            public bool $created = false;

            public function create(array $data): AiContextBundle
            {
                $this->created = true;

                return new AiContextBundle;
            }
        };

        $inbox = new class extends AtlasInboxService
        {
            public bool $created = false;

            public function __construct() {}

            public function create(array $data): AiInboxItem
            {
                $this->created = true;

                return new AiInboxItem;
            }
        };

        $item = (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Tabela ausente',
            'problem' => 'Inbox ainda nao existe.',
            'solution' => 'Nao emitir proposta parcial.',
            'worth_it' => 'Evita write path quebrado.',
        ]);

        $this->assertNull($item);
        $this->assertFalse($bundles->created);
        $this->assertFalse($inbox->created);
    }

    public function test_proposal_generates_stable_dedupe_key_when_missing(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000013';

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
                $item->id = '00000000-0000-0000-0000-000000000014';
                $item->dedupe_key = $data['dedupe_key'];

                return $item;
            }
        };

        $title = 'Proposta sem dedupe';
        $problem = 'Mesmo conteudo deve ter chave estavel.';
        $solution = 'Gerar hash por titulo problema e solucao.';

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => $title,
            'problem' => $problem,
            'solution' => $solution,
            'worth_it' => 'Evita duplicacao acidental entre rodadas.',
            'branch' => 'feature/proposal-dedupe',
        ]);

        $expected = 'proposal:'.md5($title.'|'.$problem.'|'.$solution);

        $this->assertSame($expected, data_get($inbox->created, 'dedupe_key'));
        $this->assertSame('feature/proposal-dedupe', data_get($inbox->created, 'payload.branch'));
        $this->assertSame('feature/proposal-dedupe', data_get($bundles->created, 'raw_payload.branch'));
        $this->assertSame('proposal', data_get($bundles->created, 'purpose'));
        $this->assertSame('Proposta sem dedupe', data_get($bundles->created, 'title'));
    }

    public function test_proposal_can_create_new_item_when_previous_dedupe_item_is_resolved(): void
    {
        $existing = new AiInboxItem;
        $existing->id = '00000000-0000-0000-0000-000000000010';
        $existing->dedupe_key = 'proposal:dedupe-resolved:test';
        $existing->status = 'resolved';
        $existing->created_at = now()->subMinute();
        $existing->updated_at = now()->subMinute();
        $existing->save();

        $bundles = new class extends ContextBundleService
        {
            public function create(array $data): AiContextBundle
            {
                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000011';

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
                $item->id = '00000000-0000-0000-0000-000000000012';
                $item->dedupe_key = $data['dedupe_key'];
                $item->payload = $data['payload'];

                return $item;
            }
        };

        $item = (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Proposta reaberta',
            'problem' => 'Item antigo ja foi resolvido.',
            'solution' => 'Criar nova proposta revisavel.',
            'worth_it' => 'Permite nova rodada sem reviver item fechado.',
            'dedupe_key' => 'proposal:dedupe-resolved:test',
            'metadata' => [
                'review_signal' => [
                    'severity' => 'low',
                ],
            ],
        ]);

        $this->assertSame('00000000-0000-0000-0000-000000000012', $item?->id);
        $this->assertSame('proposal:dedupe-resolved:test', data_get($inbox->created, 'dedupe_key'));
        $this->assertSame('warning', data_get($inbox->created, 'severity'));
        $this->assertSame(65, data_get($inbox->created, 'priority_score'));
        $this->assertSame('Item antigo ja foi resolvido.', data_get($inbox->created, 'payload.problem'));
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

    public function test_proposal_filters_invalid_actions_and_keeps_operator_review_defaults(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000007';

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
                $item->id = '00000000-0000-0000-0000-000000000008';
                $item->available_actions = $data['available_actions'];
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Backfill assistido com acoes saneadas',
            'problem' => 'Input pode trazer acao vazia ou duplicada.',
            'solution' => 'Preservar somente acoes nomeadas e manter revisao humana.',
            'worth_it' => 'Evita Inbox sem caminho seguro de revisao.',
            'dedupe_key' => 'proposal:ledger-projection-action-filter:test',
            'available_actions' => [
                ['id' => 'run_ledger_projection', 'label' => 'Rodar projection', 'style' => 'primary'],
                ['label' => 'Sem id', 'style' => 'secondary'],
                'invalid-action',
                ['id' => 'discuss', 'label' => 'Discutir com operador', 'style' => 'secondary'],
            ],
            'payload' => [
                'ledger_projection' => [
                    'hours' => 12,
                    'limit' => 250,
                ],
            ],
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.ledger_projection_drift.v1',
                'review_signal' => [
                    'severity' => 'medium',
                    'recommended_action' => 'open_reviewable_ledger_projection_backfill_proposal',
                ],
            ],
        ]);

        $this->assertSame('run_ledger_projection', data_get($inbox->created, 'available_actions.0.id'));
        $this->assertSame('discuss', data_get($inbox->created, 'available_actions.1.id'));
        $this->assertSame('review_patch', data_get($inbox->created, 'available_actions.2.id'));
        $this->assertSame('discard', data_get($inbox->created, 'available_actions.3.id'));
        $this->assertCount(4, data_get($inbox->created, 'available_actions'));
        $this->assertSame('warning', data_get($inbox->created, 'severity'));
        $this->assertSame(75, data_get($inbox->created, 'priority_score'));
        $this->assertSame(12, data_get($inbox->created, 'payload.ledger_projection.hours'));
        $this->assertFalse((bool) data_get($inbox->created, 'payload.policy.auto_commit'));
        $this->assertFalse((bool) data_get($inbox->created, 'payload.policy.auto_merge'));
        $this->assertTrue((bool) data_get($inbox->created, 'payload.policy.requires_operator_review'));
        $this->assertSame('open_reviewable_ledger_projection_backfill_proposal', data_get($inbox->created, 'payload.proposal_contract.review_signal.recommended_action'));
        $this->assertSame(12, data_get($bundles->created, 'raw_payload.ledger_projection.hours'));
    }

    public function test_proposal_policy_overrides_cannot_enable_automatic_application(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000015';

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
                $item->id = '00000000-0000-0000-0000-000000000016';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Policy insegura recebida',
            'problem' => 'Input externo tentou aplicar proposta automaticamente.',
            'solution' => 'Forcar revisao humana no contrato emitido.',
            'worth_it' => 'Evita Curator aplicar mudanca sem operador.',
            'dedupe_key' => 'proposal:policy-safe-defaults:test',
            'policy' => [
                'auto_commit' => true,
                'auto_merge' => true,
                'requires_operator_review' => false,
                'requires_tests_passed' => false,
                'review_window' => 'operator',
            ],
        ]);

        $this->assertFalse((bool) data_get($inbox->created, 'payload.policy.auto_commit'));
        $this->assertFalse((bool) data_get($inbox->created, 'payload.policy.auto_merge'));
        $this->assertTrue((bool) data_get($inbox->created, 'payload.policy.requires_operator_review'));
        $this->assertFalse((bool) data_get($inbox->created, 'payload.policy.requires_tests_passed'));
        $this->assertSame('operator', data_get($inbox->created, 'payload.policy.review_window'));
        $this->assertFalse((bool) data_get($bundles->created, 'raw_payload.policy.auto_commit'));
        $this->assertFalse((bool) data_get($bundles->created, 'raw_payload.policy.auto_merge'));
        $this->assertTrue((bool) data_get($bundles->created, 'raw_payload.policy.requires_operator_review'));
    }

    public function test_policy_overrides_must_keep_safe_scalar_shapes(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000029';

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
                $item->id = '00000000-0000-0000-0000-000000000030';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Policy com shape inseguro',
            'problem' => 'Input tentou poluir campos permitidos com payload composto.',
            'solution' => 'Aceitar somente boolean/string simples nos overrides seguros.',
            'worth_it' => 'Mantem contrato proposal-only serializavel.',
            'dedupe_key' => 'proposal:policy-safe-shape:test',
            'policy' => [
                'requires_tests_passed' => ['false'],
                'review_window' => ['operator'],
                'auto_commit' => true,
                'requires_operator_review' => false,
            ],
        ]);

        $this->assertTrue((bool) data_get($inbox->created, 'payload.policy.requires_tests_passed'));
        $this->assertNull(data_get($inbox->created, 'payload.policy.review_window'));
        $this->assertFalse((bool) data_get($inbox->created, 'payload.policy.auto_commit'));
        $this->assertTrue((bool) data_get($inbox->created, 'payload.policy.requires_operator_review'));
        $this->assertTrue((bool) data_get($bundles->created, 'raw_payload.policy.requires_tests_passed'));
        $this->assertNull(data_get($bundles->created, 'raw_payload.policy.review_window'));
    }

    public function test_policy_review_window_is_bounded_single_line_text(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000057';

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
                $item->id = '00000000-0000-0000-0000-000000000058';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Policy review window limitado',
            'problem' => 'Review window tentou carregar texto longo e multiline.',
            'solution' => 'Manter review_window como texto curto de contrato.',
            'worth_it' => 'Evita policy virar payload paralelo.',
            'dedupe_key' => 'proposal:policy-review-window-bounded:test',
            'policy' => [
                'review_window' => "operator review\n".str_repeat('x', 120),
            ],
        ]);

        $reviewWindow = data_get($inbox->created, 'payload.policy.review_window');

        $this->assertLessThanOrEqual(80, strlen($reviewWindow));
        $this->assertStringNotContainsString("\n", $reviewWindow);
        $this->assertSame('operator review '.str_repeat('x', 64), $reviewWindow);
        $this->assertSame($reviewWindow, data_get($bundles->created, 'raw_payload.policy.review_window'));
    }

    public function test_policy_review_window_rejects_shell_or_path_traversal_values(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000059';

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
                $item->id = '00000000-0000-0000-0000-000000000060';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Policy review window inseguro',
            'problem' => 'Review window tentou carregar comando.',
            'solution' => 'Rejeitar shell e traversal no campo.',
            'worth_it' => 'Mantem policy proposal-only.',
            'dedupe_key' => 'proposal:policy-review-window-shell:test',
            'policy' => [
                'review_window' => '../operator; php artisan migrate',
            ],
        ]);

        $this->assertNull(data_get($inbox->created, 'payload.policy.review_window'));
        $this->assertNull(data_get($bundles->created, 'raw_payload.policy.review_window'));
    }

    public function test_nested_payload_policy_cannot_bypass_operator_review_defaults(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000017';

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
                $item->id = '00000000-0000-0000-0000-000000000018';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Payload policy insegura',
            'problem' => 'Payload aninhado tentou sobrepor governanca.',
            'solution' => 'Sempre reconstruir policy depois do payload.',
            'worth_it' => 'Fecha bypass por payload externo.',
            'dedupe_key' => 'proposal:nested-payload-policy-safe-defaults:test',
            'payload' => [
                'policy' => [
                    'auto_commit' => true,
                    'auto_merge' => true,
                    'requires_operator_review' => false,
                    'runtime_promotion_allowed' => true,
                ],
            ],
            'policy' => [
                'requires_tests_passed' => false,
                'review_window' => 'operator',
            ],
        ]);

        $this->assertFalse((bool) data_get($inbox->created, 'payload.policy.auto_commit'));
        $this->assertFalse((bool) data_get($inbox->created, 'payload.policy.auto_merge'));
        $this->assertTrue((bool) data_get($inbox->created, 'payload.policy.requires_operator_review'));
        $this->assertNull(data_get($inbox->created, 'payload.policy.runtime_promotion_allowed'));
        $this->assertFalse((bool) data_get($inbox->created, 'payload.policy.requires_tests_passed'));
        $this->assertSame('operator', data_get($inbox->created, 'payload.policy.review_window'));
        $this->assertFalse((bool) data_get($bundles->created, 'raw_payload.policy.auto_commit'));
        $this->assertFalse((bool) data_get($bundles->created, 'raw_payload.policy.auto_merge'));
        $this->assertTrue((bool) data_get($bundles->created, 'raw_payload.policy.requires_operator_review'));
        $this->assertNull(data_get($bundles->created, 'raw_payload.policy.runtime_promotion_allowed'));
    }

    public function test_external_payload_cannot_smuggle_runtime_authority(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000041';

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
                $item->id = '00000000-0000-0000-0000-000000000042';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Payload externo com autoridade indevida',
            'problem' => 'Payload tentou levar comando e runtime authority.',
            'solution' => 'Remover chaves executaveis antes de montar contrato.',
            'worth_it' => 'Fecha bypass por payload arbitrario.',
            'dedupe_key' => 'proposal:external-payload-authority:test',
            'payload' => [
                'projection_health' => ['status' => 'attention_required'],
                'command' => 'php artisan migrate',
                'payload' => ['auto_apply' => true],
                'runtime_promotion_allowed' => true,
                'nested' => [
                    'provider_direct_channel' => true,
                    'memory_write_enabled' => true,
                    'kept_evidence' => 'ledger_projection_gap',
                ],
            ],
        ]);

        $this->assertSame('attention_required', data_get($inbox->created, 'payload.projection_health.status'));
        $this->assertSame('ledger_projection_gap', data_get($inbox->created, 'payload.nested.kept_evidence'));
        $this->assertNull(data_get($inbox->created, 'payload.command'));
        $this->assertNull(data_get($inbox->created, 'payload.payload'));
        $this->assertNull(data_get($inbox->created, 'payload.runtime_promotion_allowed'));
        $this->assertNull(data_get($inbox->created, 'payload.nested.provider_direct_channel'));
        $this->assertNull(data_get($inbox->created, 'payload.nested.memory_write_enabled'));
        $this->assertNull(data_get($bundles->created, 'raw_payload.command'));
        $this->assertNull(data_get($bundles->created, 'raw_payload.nested.provider_direct_channel'));
    }

    public function test_top_level_policy_cannot_smuggle_runtime_or_provider_authority(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000025';

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
                $item->id = '00000000-0000-0000-0000-000000000026';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Policy com autoridade indevida',
            'problem' => 'Top-level policy tentou promover runtime e provider direto.',
            'solution' => 'Aceitar somente overrides seguros de revisao.',
            'worth_it' => 'Mantem Inbox como proposta revisavel.',
            'dedupe_key' => 'proposal:top-level-policy-authority:test',
            'policy' => [
                'auto_commit' => true,
                'auto_merge' => true,
                'requires_operator_review' => false,
                'requires_tests_passed' => false,
                'review_window' => 'operator',
                'runtime_promotion_allowed' => true,
                'provider_direct_channel' => true,
                'memory_write_enabled' => true,
            ],
        ]);

        $this->assertFalse((bool) data_get($inbox->created, 'payload.policy.auto_commit'));
        $this->assertFalse((bool) data_get($inbox->created, 'payload.policy.auto_merge'));
        $this->assertTrue((bool) data_get($inbox->created, 'payload.policy.requires_operator_review'));
        $this->assertFalse((bool) data_get($inbox->created, 'payload.policy.requires_tests_passed'));
        $this->assertSame('operator', data_get($inbox->created, 'payload.policy.review_window'));
        $this->assertNull(data_get($inbox->created, 'payload.policy.runtime_promotion_allowed'));
        $this->assertNull(data_get($inbox->created, 'payload.policy.provider_direct_channel'));
        $this->assertNull(data_get($inbox->created, 'payload.policy.memory_write_enabled'));
        $this->assertNull(data_get($bundles->created, 'raw_payload.policy.runtime_promotion_allowed'));
        $this->assertNull(data_get($bundles->created, 'raw_payload.policy.provider_direct_channel'));
        $this->assertNull(data_get($bundles->created, 'raw_payload.policy.memory_write_enabled'));
    }

    public function test_proposal_filters_dangerous_assisted_action_ids(): void
    {
        $bundles = new class extends ContextBundleService
        {
            public function create(array $data): AiContextBundle
            {
                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000019';

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
                $item->id = '00000000-0000-0000-0000-000000000020';
                $item->available_actions = $data['available_actions'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Acoes perigosas filtradas',
            'problem' => 'Payload tentou expor caminhos de apply automatico.',
            'solution' => 'Preservar somente acoes assistidas seguras.',
            'worth_it' => 'Evita autonomia indevida pelo Inbox.',
            'dedupe_key' => 'proposal:dangerous-action-filter:test',
            'available_actions' => [
                ['id' => 'run_ledger_projection', 'label' => 'Rodar projection', 'style' => 'primary'],
                ['id' => 'auto_apply_policy', 'label' => 'Aplicar policy', 'style' => 'primary'],
                ['id' => 'auto-merge-policy', 'label' => 'Auto merge', 'style' => 'primary'],
                ['id' => 'AUTO_APPLY_POLICY', 'label' => 'Auto apply upper', 'style' => 'primary'],
                ['id' => 'auto/apply/policy', 'label' => 'Auto apply slash', 'style' => 'primary'],
                ['id' => 'autoapply_policy', 'label' => 'Auto apply glued', 'style' => 'primary'],
                ['id' => 'autoapplypolicy', 'label' => 'Auto apply fully glued', 'style' => 'primary'],
                ['id' => 'merge_to_main', 'label' => 'Merge', 'style' => 'primary'],
                ['id' => 'runtime_promotion_allowed', 'label' => 'Promover runtime', 'style' => 'primary'],
                ['id' => 'runtimepromotion_allowed', 'label' => 'Promover runtime colado', 'style' => 'primary'],
                ['id' => 'runtimepromotionallowed', 'label' => 'Promover runtime tudo colado', 'style' => 'primary'],
                ['id' => 'provider_direct_channel', 'label' => 'Provider direto', 'style' => 'primary'],
                ['id' => 'providerdirect_channel', 'label' => 'Provider direto colado', 'style' => 'primary'],
                ['id' => 'providerdirectchannel', 'label' => 'Provider direto tudo colado', 'style' => 'primary'],
                ['id' => 'memory.write.enabled', 'label' => 'Memoria', 'style' => 'primary'],
                ['id' => 'provider:direct:channel', 'label' => 'Provider direto dois', 'style' => 'primary'],
                ['id' => 'review_patch;php_artisan_migrate', 'label' => 'Shell suffix', 'style' => 'primary'],
                ['id' => 'run_ledger_projection && php_artisan_migrate', 'label' => 'Shell chain', 'style' => 'primary'],
                ['id' => 'review.patch', 'label' => 'Dot suffix', 'style' => 'primary'],
            ],
        ]);

        $actionIds = collect(data_get($inbox->created, 'available_actions'))->pluck('id')->all();

        $this->assertContains('run_ledger_projection', $actionIds);
        $this->assertContains('review_patch', $actionIds);
        $this->assertContains('discuss', $actionIds);
        $this->assertContains('discard', $actionIds);
        $this->assertNotContains('auto_apply_policy', $actionIds);
        $this->assertNotContains('auto-merge-policy', $actionIds);
        $this->assertNotContains('AUTO_APPLY_POLICY', $actionIds);
        $this->assertNotContains('auto/apply/policy', $actionIds);
        $this->assertNotContains('autoapply_policy', $actionIds);
        $this->assertNotContains('autoapplypolicy', $actionIds);
        $this->assertNotContains('merge_to_main', $actionIds);
        $this->assertNotContains('runtime_promotion_allowed', $actionIds);
        $this->assertNotContains('runtimepromotion_allowed', $actionIds);
        $this->assertNotContains('runtimepromotionallowed', $actionIds);
        $this->assertNotContains('provider_direct_channel', $actionIds);
        $this->assertNotContains('providerdirect_channel', $actionIds);
        $this->assertNotContains('providerdirectchannel', $actionIds);
        $this->assertNotContains('memory.write.enabled', $actionIds);
        $this->assertNotContains('provider:direct:channel', $actionIds);
        $this->assertNotContains('review_patch;php_artisan_migrate', $actionIds);
        $this->assertNotContains('run_ledger_projection && php_artisan_migrate', $actionIds);
        $this->assertNotContains('review.patch', $actionIds);
    }

    public function test_dangerous_review_signal_recommended_action_is_sanitized(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000021';

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
                $item->id = '00000000-0000-0000-0000-000000000022';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Review signal perigoso',
            'problem' => 'Recommended action tentou acionar auto-apply.',
            'solution' => 'Trocar por revisao humana.',
            'worth_it' => 'Mantem Proposal Inbox proposal-only.',
            'dedupe_key' => 'proposal:dangerous-review-signal-action:test',
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.test.v1',
                'review_signal' => [
                    'status' => 'warning',
                    'severity' => 'high',
                    'recommended_action' => 'AUTOAPPLYPOLICYPATCH',
                ],
            ],
        ]);

        $this->assertSame('review_patch', data_get($inbox->created, 'payload.proposal_contract.review_signal.recommended_action'));
        $this->assertSame('review_patch', data_get($bundles->created, 'raw_payload.proposal_contract.review_signal.recommended_action'));
    }

    public function test_review_signal_recommended_action_rejects_shell_suffixes(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000035';

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
                $item->id = '00000000-0000-0000-0000-000000000036';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Review signal action com shell suffix',
            'problem' => 'Recommended action tentou embutir comando depois de action segura.',
            'solution' => 'Aceitar somente id canonico de action.',
            'worth_it' => 'Impede bypass por sufixo executavel.',
            'dedupe_key' => 'proposal:review-signal-shell-suffix:test',
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.test.v1',
                'review_signal' => [
                    'status' => 'warning',
                    'severity' => 'high',
                    'recommended_action' => 'review_patch;php_artisan_migrate',
                ],
            ],
        ]);

        $this->assertSame('review_patch', data_get($inbox->created, 'payload.proposal_contract.review_signal.recommended_action'));
        $this->assertSame('review_patch', data_get($bundles->created, 'raw_payload.proposal_contract.review_signal.recommended_action'));
    }

    public function test_review_signal_metadata_cannot_smuggle_runtime_payloads(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000027';

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
                $item->id = '00000000-0000-0000-0000-000000000028';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Review signal com payload perigoso',
            'problem' => 'Metadata tentou esconder execucao no review_signal.',
            'solution' => 'Manter somente contrato de review.',
            'worth_it' => 'Evita autonomia indevida no Inbox.',
            'dedupe_key' => 'proposal:review-signal-metadata-safety:test',
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.test.v1',
                'review_signal' => [
                    'status' => 'warning',
                    'severity' => 'medium',
                    'recommended_action' => 'review_patch',
                    'reasons' => ['operator_review_required'],
                    'command' => 'php artisan migrate',
                    'payload' => ['auto_apply' => true],
                    'auto_apply' => true,
                    'provider_direct_channel' => true,
                    'memory_write_enabled' => true,
                ],
            ],
        ]);

        $reviewSignal = data_get($inbox->created, 'payload.proposal_contract.review_signal');

        $this->assertSame([
            'status' => 'warning',
            'severity' => 'medium',
            'review_required' => true,
            'recommended_action' => 'review_patch',
            'reasons' => ['operator_review_required'],
        ], $reviewSignal);
        $this->assertSame($reviewSignal, data_get($bundles->created, 'raw_payload.proposal_contract.review_signal'));
    }

    public function test_review_signal_sources_are_sanitized_to_evidence_refs_only(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000031';

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
                $item->id = '00000000-0000-0000-0000-000000000032';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Review signal sources com payload perigoso',
            'problem' => 'Sources tentaram carregar comando e payload runtime.',
            'solution' => 'Manter sources como referencias frias de evidencia.',
            'worth_it' => 'Evita bypass por evidence refs no Inbox.',
            'dedupe_key' => 'proposal:review-signal-sources-safety:test',
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.test.v1',
                'review_signal' => [
                    'status' => 'warning',
                    'severity' => 'medium',
                    'recommended_action' => 'review_patch',
                    'sources' => [
                        [
                            'type' => 'ledger_event',
                            'event_id' => 'evt_123',
                            'trace_id' => 'trace_456',
                            'command' => 'php artisan migrate',
                            'payload' => ['auto_apply' => true],
                            'auto_apply' => true,
                        ],
                        [
                            'path' => 'docs/ap/AP-144-rivals-review-inbox.md',
                            'envelope_id' => 'env_789',
                            'provider_direct_channel' => true,
                        ],
                        'not-a-source',
                        [],
                    ],
                ],
            ],
        ]);

        $reviewSignal = data_get($inbox->created, 'payload.proposal_contract.review_signal');

        $this->assertSame([
            [
                'type' => 'ledger_event',
                'event_id' => 'evt_123',
                'trace_id' => 'trace_456',
            ],
            [
                'path' => 'docs/ap/AP-144-rivals-review-inbox.md',
                'envelope_id' => 'env_789',
            ],
        ], $reviewSignal['sources']);
        $this->assertSame($reviewSignal, data_get($bundles->created, 'raw_payload.proposal_contract.review_signal'));
    }

    public function test_review_signal_scalar_fields_and_reasons_reject_payload_shapes(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000033';

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
                $item->id = '00000000-0000-0000-0000-000000000034';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Review signal com shapes perigosos',
            'problem' => 'Campos escalares tentaram carregar arrays e comandos.',
            'solution' => 'Aceitar somente strings simples e booleano real.',
            'worth_it' => 'Fecha bypass por metadata de review.',
            'dedupe_key' => 'proposal:review-signal-scalar-shape:test',
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.test.v1',
                'review_signal' => [
                    'status' => ['warning'],
                    'severity' => ['medium'],
                    'reason' => ['operator_review_required'],
                    'review_required' => 'true',
                    'recommended_action' => 'review_patch',
                    'reasons' => [
                        ' operator_review_required ',
                        ['command' => 'php artisan migrate'],
                        '',
                    ],
                ],
            ],
        ]);

        $reviewSignal = data_get($inbox->created, 'payload.proposal_contract.review_signal');

        $this->assertSame([
            'review_required' => true,
            'recommended_action' => 'review_patch',
            'reasons' => ['operator_review_required'],
        ], $reviewSignal);
        $this->assertSame($reviewSignal, data_get($bundles->created, 'raw_payload.proposal_contract.review_signal'));
    }

    public function test_assisted_action_metadata_cannot_smuggle_commands_or_payloads(): void
    {
        $bundles = new class extends ContextBundleService
        {
            public function create(array $data): AiContextBundle
            {
                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000023';

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
                $item->id = '00000000-0000-0000-0000-000000000024';
                $item->available_actions = $data['available_actions'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Acao com metadata perigosa',
            'problem' => 'Action trouxe campos executaveis escondidos.',
            'solution' => 'Manter somente shape visual seguro.',
            'worth_it' => 'Evita runtime paralelo dentro do Inbox.',
            'dedupe_key' => 'proposal:action-metadata-safety:test',
            'available_actions' => [[
                'id' => 'run_ledger_projection',
                'label' => 'Rodar projection',
                'style' => 'primary',
                'requires_confirm' => true,
                'command' => 'php artisan migrate',
                'url' => 'https://provider.example/run',
                'payload' => ['auto_apply' => true],
                'auto_apply' => true,
            ]],
        ]);

        $action = data_get($inbox->created, 'available_actions.0');

        $this->assertSame([
            'id' => 'run_ledger_projection',
            'label' => 'Rodar projection',
            'style' => 'primary',
            'requires_confirm' => true,
        ], $action);
    }

    public function test_review_signal_cannot_disable_required_operator_review(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000035';

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
                $item->id = '00000000-0000-0000-0000-000000000036';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Review signal tentando dispensar operador',
            'problem' => 'Metadata tentou marcar proposta como nao revisavel.',
            'solution' => 'Forcar review_required em todo proposal_contract.',
            'worth_it' => 'Evita bypass de operador por metadata.',
            'dedupe_key' => 'proposal:review-required-forced:test',
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.test.v1',
                'review_signal' => [
                    'status' => 'warning',
                    'severity' => 'medium',
                    'review_required' => false,
                    'recommended_action' => 'review_patch',
                ],
            ],
        ]);

        $reviewSignal = data_get($inbox->created, 'payload.proposal_contract.review_signal');

        $this->assertTrue($reviewSignal['review_required']);
        $this->assertTrue(data_get($bundles->created, 'raw_payload.proposal_contract.review_signal.review_required'));
    }

    public function test_assisted_action_style_is_limited_to_visual_enum(): void
    {
        $bundles = new class extends ContextBundleService
        {
            public function create(array $data): AiContextBundle
            {
                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000037';

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
                $item->id = '00000000-0000-0000-0000-000000000038';
                $item->available_actions = $data['available_actions'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Action style arbitrario',
            'problem' => 'Payload tentou enviar style fora do contrato visual.',
            'solution' => 'Normalizar style para enum seguro.',
            'worth_it' => 'Evita contrato UI virar canal paralelo.',
            'dedupe_key' => 'proposal:action-style-enum:test',
            'available_actions' => [
                ['id' => 'review_patch', 'label' => 'Revisar', 'style' => 'javascript:alert(1)'],
                ['id' => 'discuss', 'label' => 'Discutir', 'style' => 'primary'],
            ],
        ]);

        $this->assertSame('default', data_get($inbox->created, 'available_actions.0.style'));
        $this->assertSame('primary', data_get($inbox->created, 'available_actions.1.style'));
    }

    public function test_destructive_assisted_actions_always_require_confirmation(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000055';

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
                $item->id = '00000000-0000-0000-0000-000000000056';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Action destrutiva sem confirmacao',
            'problem' => 'Action visual destrutiva tentou remover confirmacao.',
            'solution' => 'Forcar confirmacao em comandos destrutivos de inbox.',
            'worth_it' => 'Evita clique acidental em operacao irreversivel.',
            'dedupe_key' => 'proposal:destructive-action-confirmation:test',
            'available_actions' => [[
                'id' => 'discard_patch',
                'label' => 'Descartar patch',
                'style' => 'destructive',
                'requires_confirm' => false,
            ]],
        ]);

        $this->assertSame('discard_patch', data_get($inbox->created, 'available_actions.0.id'));
        $this->assertSame('destructive', data_get($inbox->created, 'available_actions.0.style'));
        $this->assertTrue(data_get($inbox->created, 'available_actions.0.requires_confirm'));
    }

    public function test_assisted_action_label_is_bounded_visual_text(): void
    {
        $bundles = new class extends ContextBundleService
        {
            public function create(array $data): AiContextBundle
            {
                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000039';

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
                $item->id = '00000000-0000-0000-0000-000000000040';
                $item->available_actions = $data['available_actions'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Action label arbitrario',
            'problem' => 'Payload tentou mandar label multiline gigante.',
            'solution' => 'Manter label como texto visual curto.',
            'worth_it' => 'Evita UI/action virar canal paralelo.',
            'dedupe_key' => 'proposal:action-label-bounded:test',
            'available_actions' => [
                [
                    'id' => 'review_patch',
                    'label' => "Revisar\n\ncomando php artisan migrate ".str_repeat('x', 100),
                    'style' => 'primary',
                ],
                [
                    'id' => 'discuss',
                    'label' => ['payload' => true],
                    'style' => 'default',
                ],
            ],
        ]);

        $label = data_get($inbox->created, 'available_actions.0.label');

        $this->assertIsString($label);
        $this->assertLessThanOrEqual(80, strlen($label));
        $this->assertStringNotContainsString("\n", $label);
        $this->assertSame('Revisar proposta', data_get($inbox->created, 'available_actions.1.label'));
    }

    public function test_proposal_alternatives_are_bounded_visual_text(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000041';

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
                $item->id = '00000000-0000-0000-0000-000000000042';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Alternatives com payload perigoso',
            'problem' => 'Alternatives tentou carregar comando e multiline gigante.',
            'solution' => 'Manter alternatives como texto visual frio.',
            'worth_it' => 'Evita payload paralelo no contrato de proposta.',
            'dedupe_key' => 'proposal:alternatives-bounded:test',
            'alternatives' => [
                "Manter auditoria\nmanual ".str_repeat('x', 200),
                ['command' => 'php artisan migrate', 'payload' => ['auto_apply' => true]],
                '',
                true,
                'Gerar handoff tecnico',
            ],
        ]);

        $alternatives = data_get($inbox->created, 'payload.alternatives');

        $this->assertSame($alternatives, data_get($bundles->created, 'raw_payload.alternatives'));
        $this->assertCount(3, $alternatives);
        $this->assertLessThanOrEqual(160, strlen($alternatives[0]));
        $this->assertStringNotContainsString("\n", $alternatives[0]);
        $this->assertSame('1', $alternatives[1]);
        $this->assertSame('Gerar handoff tecnico', $alternatives[2]);
    }

    public function test_proposal_primary_text_fields_are_bounded_single_line_text(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000043';

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
                $item->id = '00000000-0000-0000-0000-000000000044';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => "Titulo\n".str_repeat('x', 180),
            'problem' => "Problema\n".str_repeat('p', 1200),
            'solution' => "Solucao\n".str_repeat('s', 1200),
            'worth_it' => "Vale\n".str_repeat('w', 1200),
            'dedupe_key' => 'proposal:primary-text-bounded:test',
        ]);

        $this->assertLessThanOrEqual(120, strlen(data_get($bundles->created, 'title')));
        $this->assertLessThanOrEqual(1000, strlen(data_get($inbox->created, 'payload.problem')));
        $this->assertLessThanOrEqual(1000, strlen(data_get($inbox->created, 'payload.solution')));
        $this->assertLessThanOrEqual(1000, strlen(data_get($inbox->created, 'payload.worth_it')));
        $this->assertStringNotContainsString("\n", data_get($bundles->created, 'title'));
        $this->assertStringNotContainsString("\n", data_get($inbox->created, 'payload.problem'));
        $this->assertSame(data_get($inbox->created, 'payload.problem'), data_get($bundles->created, 'raw_payload.problem'));
    }

    public function test_proposal_identity_fields_are_bounded_tokens(): void
    {
        $bundles = new class extends ContextBundleService
        {
            public function create(array $data): AiContextBundle
            {
                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000045';

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
                $item->id = '00000000-0000-0000-0000-000000000046';

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Identity insegura',
            'problem' => 'Identity fields tentaram carregar comandos.',
            'solution' => 'Manter identity como tokens frios.',
            'worth_it' => 'Evita roteamento paralelo pelo Inbox.',
            'dedupe_key' => 'proposal:identity-fields-safety:test',
            'category' => "self_improvement\nadmin",
            'source_type' => 'atlas_self_improvement;drop',
            'source_id' => '../runtime//provider',
        ]);

        $this->assertSame('auto_improvement', data_get($inbox->created, 'category'));
        $this->assertNull(data_get($inbox->created, 'source_type'));
        $this->assertNull(data_get($inbox->created, 'source_id'));

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Identity segura',
            'problem' => 'Identity fields validos devem sobreviver.',
            'solution' => 'Preservar tokens validos.',
            'worth_it' => 'Mantem rastreabilidade fria.',
            'dedupe_key' => 'proposal:identity-fields-valid:test',
            'category' => 'self_improvement',
            'source_type' => 'atlas_self_improvement',
            'source_id' => 'finding-provider-cost-rate:2026_05_10',
        ]);

        $this->assertSame('self_improvement', data_get($inbox->created, 'category'));
        $this->assertSame('atlas_self_improvement', data_get($inbox->created, 'source_type'));
        $this->assertSame('finding-provider-cost-rate:2026_05_10', data_get($inbox->created, 'source_id'));
    }

    public function test_proposal_body_free_text_fields_are_bounded_single_line_text(): void
    {
        $bundles = new class extends ContextBundleService
        {
            public function create(array $data): AiContextBundle
            {
                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000047';

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
                $item->id = '00000000-0000-0000-0000-000000000048';

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Body fields gigantes',
            'problem' => 'Problema curto.',
            'solution' => 'Solucao curta.',
            'worth_it' => 'Vale revisar.',
            'dedupe_key' => 'proposal:body-text-bounded:test',
            'finding' => "Finding\n".str_repeat('f', 1200),
            'best_solution_rationale' => "Rationale\n".str_repeat('r', 1200),
        ]);

        $body = data_get($inbox->created, 'body');

        $this->assertStringContainsString('O que encontrei: ', $body);
        $this->assertStringContainsString('Era a melhor solucao: ', $body);
        $this->assertStringNotContainsString("Finding\n", $body);
        $this->assertStringNotContainsString("Rationale\n", $body);
        $this->assertLessThan(2300, strlen($body));
    }

    public function test_review_signal_status_severity_and_reason_are_bounded_contract_fields(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000049';

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
                $item->id = '00000000-0000-0000-0000-000000000050';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Review signal textual inseguro',
            'problem' => 'Review signal tentou carregar strings fora do contrato.',
            'solution' => 'Manter status/severity/reason em shapes frios.',
            'worth_it' => 'Evita metadata virar canal paralelo.',
            'dedupe_key' => 'proposal:review-signal-fields-bounded:test',
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.test.v1',
                'review_signal' => [
                    'status' => "warning\napply_patch",
                    'severity' => 'critical;deploy',
                    'reason' => "Operador precisa revisar\n".str_repeat('r', 1200),
                    'recommended_action' => 'review_patch',
                ],
            ],
        ]);

        $reviewSignal = data_get($inbox->created, 'payload.proposal_contract.review_signal');

        $this->assertArrayNotHasKey('status', $reviewSignal);
        $this->assertArrayNotHasKey('severity', $reviewSignal);
        $this->assertArrayHasKey('reason', $reviewSignal);
        $this->assertLessThanOrEqual(1000, strlen($reviewSignal['reason']));
        $this->assertStringNotContainsString("\n", $reviewSignal['reason']);
        $this->assertSame($reviewSignal, data_get($bundles->created, 'raw_payload.proposal_contract.review_signal'));
    }

    public function test_review_signal_sources_reject_unsafe_ref_values(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000051';

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
                $item->id = '00000000-0000-0000-0000-000000000052';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Review signal source inseguro',
            'problem' => 'Sources tentaram carregar paths e ids perigosos.',
            'solution' => 'Manter evidence refs como tokens frios.',
            'worth_it' => 'Evita ref virar payload operacional.',
            'dedupe_key' => 'proposal:review-source-ref-safety:test',
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.test.v1',
                'review_signal' => [
                    'recommended_action' => 'review_patch',
                    'sources' => [[
                        'type' => "ledger_event\nprovider",
                        'id' => 'finding-safe_123',
                        'path' => '../runtime//secret',
                        'event_id' => 'evt_123 '.str_repeat('x', 190),
                        'envelope_id' => 'env:456',
                        'trace_id' => 'trace/789',
                    ]],
                ],
            ],
        ]);

        $reviewSignal = data_get($inbox->created, 'payload.proposal_contract.review_signal');

        $this->assertSame([
            [
                'id' => 'finding-safe_123',
                'envelope_id' => 'env:456',
                'trace_id' => 'trace/789',
            ],
        ], $reviewSignal['sources']);
        $this->assertSame($reviewSignal, data_get($bundles->created, 'raw_payload.proposal_contract.review_signal'));
    }

    public function test_review_signal_reasons_are_bounded_single_line_text(): void
    {
        $bundles = new class extends ContextBundleService
        {
            /** @var array<string,mixed> */
            public array $created = [];

            public function create(array $data): AiContextBundle
            {
                $this->created = $data;

                $bundle = new AiContextBundle;
                $bundle->id = '00000000-0000-0000-0000-000000000053';

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
                $item->id = '00000000-0000-0000-0000-000000000054';
                $item->payload = $data['payload'];

                return $item;
            }
        };

        (new ProposalInboxEmitter($bundles, $inbox))->emit([
            'title' => 'Review reasons inseguros',
            'problem' => 'Reasons tentaram carregar lista grande e multiline.',
            'solution' => 'Manter reasons como texto visual frio.',
            'worth_it' => 'Evita metadata virar payload paralelo.',
            'dedupe_key' => 'proposal:review-reasons-bounded:test',
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.test.v1',
                'review_signal' => [
                    'recommended_action' => 'review_patch',
                    'reasons' => [
                        "operator_review_required\n".str_repeat('x', 220),
                        ['payload' => true],
                        '',
                        false,
                        ...array_fill(0, 20, 'extra_reason'),
                    ],
                ],
            ],
        ]);

        $reasons = data_get($inbox->created, 'payload.proposal_contract.review_signal.reasons');

        $this->assertCount(12, $reasons);
        $this->assertLessThanOrEqual(160, strlen($reasons[0]));
        $this->assertStringNotContainsString("\n", $reasons[0]);
        $this->assertSame('operator_review_required '.str_repeat('x', 135), $reasons[0]);
        $this->assertSame('extra_reason', $reasons[1]);
        $this->assertSame($reasons, data_get($bundles->created, 'raw_payload.proposal_contract.review_signal.reasons'));
    }
}
