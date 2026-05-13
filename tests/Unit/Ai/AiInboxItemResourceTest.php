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
            ],
        ];

        $payload = (new AiInboxItemResource($item))->resolve();

        $this->assertSame('atlas.inbox_item.safety.v1', data_get($payload, 'safety.schema_version'));
        $this->assertTrue(data_get($payload, 'safety.has_proactive_delivery_contract'));
        $this->assertSame('atlas.proactive.delivery_contract.v1', data_get($payload, 'safety.proactive_delivery_contract_schema'));
        $this->assertSame(hash('sha256', 'test-proactive-contract'), data_get($payload, 'safety.proactive_delivery_contract_hash'));
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
}
