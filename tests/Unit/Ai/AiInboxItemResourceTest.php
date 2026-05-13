<?php

namespace Tests\Unit\Ai;

use App\Http\Resources\AiInboxItemResource;
use App\Models\AiInboxItem;
use Tests\TestCase;

final class AiInboxItemResourceTest extends TestCase
{
    public function test_inbox_item_resource_exposes_notification_safety_without_granting_actions(): void
    {
        $item = new AiInboxItem;
        $item->id = '00000000-0000-0000-0000-000000000777';
        $item->type = 'insight';
        $item->category = 'memory_quality';
        $item->severity = 'warning';
        $item->status = 'unread';
        $item->title = 'Revisar regressao de retrieval';
        $item->summary = 'Snapshot de retrieval precisa de revisao.';
        $item->body = 'Conteudo completo fica atras de API autenticada.';
        $item->context_bundle_id = '00000000-0000-0000-0000-000000000778';
        $item->deep_link = 'atlas://inbox/00000000-0000-0000-0000-000000000777';
        $item->push_policy = ['send' => 'immediate', 'reason' => 'memory_quality'];
        $item->available_actions = [
            ['id' => 'review_retrieval_regression', 'label' => 'Revisar', 'style' => 'primary'],
            ['id' => 'discuss', 'label' => 'Discutir', 'style' => 'default'],
        ];
        $item->payload = [
            'raw_context_marker' => 'this stays in authenticated API payload, not push',
            'proactive_delivery_contract' => [
                'schema_version' => 'atlas.proactive.delivery_contract.v1',
                'contract_hash' => hash('sha256', 'test-proactive-contract'),
                'presence_eclipse_governance' => [
                    'schema_version' => 'atlas.proactive.presence_eclipse.v1',
                    'explicit_opt_out_supported' => true,
                    'manual_eclipse_supported' => true,
                ],
            ],
        ];

        $payload = (new AiInboxItemResource($item))->resolve();

        $this->assertSame('atlas.inbox_item.safety.v1', data_get($payload, 'safety.schema_version'));
        $this->assertTrue(data_get($payload, 'safety.has_proactive_delivery_contract'));
        $this->assertSame('atlas.proactive.delivery_contract.v1', data_get($payload, 'safety.proactive_delivery_contract_schema'));
        $this->assertSame(hash('sha256', 'test-proactive-contract'), data_get($payload, 'safety.proactive_delivery_contract_hash'));
        $this->assertSame('atlas.proactive.presence_eclipse.v1', data_get($payload, 'safety.presence_eclipse_contract_schema'));
        $this->assertTrue(data_get($payload, 'safety.manual_eclipse_supported'));
        $this->assertTrue(data_get($payload, 'safety.proactive_push_opt_out_supported'));
        $this->assertTrue(data_get($payload, 'safety.authenticated_api_required'));
        $this->assertTrue(data_get($payload, 'safety.push_is_pointer_only'));
        $this->assertTrue(data_get($payload, 'safety.push_delivery_requested'));
        $this->assertSame('immediate', data_get($payload, 'safety.push_send_mode'));
        $this->assertTrue(data_get($payload, 'safety.deep_link_only_delivery'));
        $this->assertTrue(data_get($payload, 'safety.context_bundle_api_only'));
        $this->assertFalse(data_get($payload, 'safety.raw_context_exposed_in_push'));
        $this->assertFalse(data_get($payload, 'safety.raw_payload_exposed_in_push'));
        $this->assertFalse(data_get($payload, 'safety.body_exposed_in_push'));
        $this->assertFalse(data_get($payload, 'safety.auto_action_allowed'));
        $this->assertSame(2, data_get($payload, 'safety.available_action_count'));
        $this->assertSame('warning', data_get($payload, 'safety.severity'));
        $this->assertSame('unread', data_get($payload, 'safety.status'));
    }

    public function test_inbox_item_resource_safety_reports_disabled_push(): void
    {
        $item = new AiInboxItem;
        $item->id = '00000000-0000-0000-0000-000000000779';
        $item->type = 'job_result';
        $item->category = 'job';
        $item->severity = 'info';
        $item->status = 'read';
        $item->title = 'Job finalizado';
        $item->summary = 'Sem push.';
        $item->push_policy = ['send' => 'none'];
        $item->available_actions = [];

        $payload = (new AiInboxItemResource($item))->resolve();

        $this->assertSame('atlas.inbox_item.safety.v1', data_get($payload, 'safety.schema_version'));
        $this->assertFalse(data_get($payload, 'safety.has_proactive_delivery_contract'));
        $this->assertNull(data_get($payload, 'safety.proactive_delivery_contract_schema'));
        $this->assertNull(data_get($payload, 'safety.proactive_delivery_contract_hash'));
        $this->assertFalse(data_get($payload, 'safety.push_delivery_requested'));
        $this->assertSame('none', data_get($payload, 'safety.push_send_mode'));
        $this->assertFalse(data_get($payload, 'safety.context_bundle_api_only'));
        $this->assertSame(0, data_get($payload, 'safety.available_action_count'));
        $this->assertFalse(data_get($payload, 'safety.auto_action_allowed'));
    }

    public function test_inbox_item_resource_exposes_human_presentation_for_telemetry_health(): void
    {
        $item = new AiInboxItem;
        $item->id = '00000000-0000-0000-0000-000000000780';
        $item->type = 'insight';
        $item->category = 'atlas_ai_telemetry_health';
        $item->severity = 'critical';
        $item->status = 'unread';
        $item->title = 'Atlas precisa de revisao operacional';
        $item->summary = 'Saude critica: score 42/100.';
        $item->payload = [
            'health' => [
                'status' => 'critical',
                'health_score' => 42,
                'window' => [
                    'since' => '2026-05-12T00:00:00Z',
                    'until' => '2026-05-13T00:00:00Z',
                ],
                'sample' => [
                    'confidence' => 'limited',
                    'traces' => 12,
                ],
                'issues' => [
                    [
                        'key' => 'final_quality_avg',
                        'severity' => 'critical',
                        'value' => 58,
                        'threshold' => 70,
                    ],
                    [
                        'key' => 'unknown_cost_rate',
                        'severity' => 'warning',
                        'value' => 0.33,
                        'threshold' => 0,
                    ],
                ],
                'actions' => [
                    'Open recent low-score traces and compare providers',
                ],
            ],
        ];

        $payload = (new AiInboxItemResource($item))->resolve();

        $this->assertSame('atlas.inbox_item.human_presentation.v1', data_get($payload, 'presentation.schema_version'));
        $this->assertSame('Atlas AI em estado critico - saude 42/100', data_get($payload, 'presentation.headline'));
        $this->assertSame('Saude', data_get($payload, 'presentation.primary_metric.label'));
        $this->assertSame('42/100', data_get($payload, 'presentation.primary_metric.value'));
        $this->assertSame('critical', data_get($payload, 'presentation.primary_metric.tone'));
        $this->assertSame('Status critico; score 42/100; 1 sinal critico; 1 alerta; amostra pequena.', data_get($payload, 'presentation.plain_summary'));
        $this->assertSame('Abrir traces recentes com score baixo e revisar prompt, contexto e provider antes de mudar comportamento.', data_get($payload, 'presentation.operator_next_step'));
        $this->assertTrue(data_get($payload, 'presentation.review_required'));
        $this->assertFalse(data_get($payload, 'presentation.auto_resolution_allowed'));
        $this->assertContains('Sinais criticos', collect(data_get($payload, 'presentation.metrics'))->pluck('label')->all());
        $this->assertContains('Traces analisadas', collect(data_get($payload, 'presentation.metrics'))->pluck('label')->all());
        $this->assertSame('Qualidade media', data_get($payload, 'presentation.sections.0.items.0.label'));
        $this->assertSame('58', data_get($payload, 'presentation.sections.0.items.0.value'));
        $this->assertSame('70', data_get($payload, 'presentation.sections.0.items.0.threshold'));
    }

    public function test_inbox_item_resource_exposes_human_presentation_for_performance_report(): void
    {
        $item = new AiInboxItem;
        $item->id = '00000000-0000-0000-0000-000000000781';
        $item->type = 'insight';
        $item->category = 'atlas_ai_performance';
        $item->severity = 'warning';
        $item->status = 'unread';
        $item->title = 'Atlas: relatorio de performance de 13/05/2026';
        $item->summary = 'Status warning; 20 traces.';
        $item->payload = [
            'report' => [
                'status' => 'warning',
                'report_date' => '2026-05-13',
                'summary' => [
                    'traces' => 20,
                    'quality_avg' => 68.4,
                    'efficiency_avg' => 74.2,
                    'first_pass_success_rate' => 0.55,
                    'app_visible_p95_ms' => 1240,
                    'cost_usd_estimate' => 0.12345,
                    'unknown_cost_rate' => 0.2,
                ],
                'risks' => [
                    [
                        'key' => 'quality_avg',
                        'severity' => 'warning',
                        'summary' => 'Qualidade caiu na janela.',
                    ],
                ],
                'actions' => [
                    'Comparar traces com baixa qualidade por provider',
                ],
            ],
        ];

        $payload = (new AiInboxItemResource($item))->resolve();

        $this->assertSame('Relatorio de performance do Atlas AI', data_get($payload, 'presentation.headline'));
        $this->assertSame('Qualidade media', data_get($payload, 'presentation.primary_metric.label'));
        $this->assertSame('68,4', data_get($payload, 'presentation.primary_metric.value'));
        $this->assertSame('warning', data_get($payload, 'presentation.primary_metric.tone'));
        $this->assertTrue(data_get($payload, 'presentation.review_required'));
        $this->assertFalse(data_get($payload, 'presentation.auto_resolution_allowed'));
        $metricLabels = collect(data_get($payload, 'presentation.metrics'))->pluck('label')->all();
        $this->assertContains('Traces', $metricLabels);
        $this->assertContains('P95 app visivel', $metricLabels);
        $this->assertContains('Custo desconhecido', $metricLabels);
        $this->assertSame('20', data_get($payload, 'presentation.metrics.0.value'));
        $this->assertSame('Qualidade media', data_get($payload, 'presentation.sections.0.items.0.label'));
    }

    public function test_inbox_item_resource_extracts_performance_metrics_from_legacy_compact_payload(): void
    {
        $item = new AiInboxItem;
        $item->id = '00000000-0000-0000-0000-000000000782';
        $item->type = 'insight';
        $item->category = 'atlas_ai_performance';
        $item->severity = 'critical';
        $item->status = 'unread';
        $item->title = 'Atlas: relatorio de performance de 12/05/2026';
        $item->summary = 'Status critical; 16 traces; qualidade 77,9 (+3,7); eficiencia 74,6.';
        $item->body = implode("\n", [
            'Metricas centrais:',
            '- Traces: 16',
            '- Qualidade media: 77,9 | delta dia anterior: +3,7',
            '- Eficiencia media: 74,6 | delta dia anterior: -1,0',
            '- First-pass: 93,8% | remedicao: 0,0%',
            '- P95 app visivel: 4.962ms | P95 provider: 115.934ms',
            '- Custo: sem rate configurado para 100,0% das traces | custo desconhecido: 100,0%',
        ]);
        $item->payload = [
            'report' => [
                'status' => 'critical',
                'report_date' => '2026-05-12',
                'highlights' => [
                    'Traces: 16',
                    'Qualidade: 77,9',
                    'Eficiencia: 74,6',
                    'Custo: sem rate configurado para 100,0% das traces',
                ],
                'next_actions' => [
                    'Configurar rates de estimativa operacional e rodar o rollup de telemetria novamente.',
                ],
                'full_text' => $item->body,
            ],
        ];

        $payload = (new AiInboxItemResource($item))->resolve();

        $this->assertSame('77,9', data_get($payload, 'presentation.primary_metric.value'));
        $this->assertSame('Status critical; 16 traces; qualidade 77,9; eficiencia 74,6; data 2026-05-12.', data_get($payload, 'presentation.plain_summary'));
        $this->assertSame('16', data_get($payload, 'presentation.metrics.0.value'));
        $this->assertSame('93,8%', data_get($payload, 'presentation.metrics.3.value'));
        $this->assertSame('4,96s', data_get($payload, 'presentation.metrics.4.value'));
        $this->assertSame('100%', data_get($payload, 'presentation.metrics.6.value'));
        $this->assertSame('Configurar rates de estimativa operacional e rodar o rollup de telemetria novamente.', data_get($payload, 'presentation.operator_next_step'));
    }
}
