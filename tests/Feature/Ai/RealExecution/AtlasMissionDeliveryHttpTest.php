<?php

namespace Tests\Feature\Ai\RealExecution;

use App\Jobs\DeliverAtlasMissionJob;
use App\Models\AtlasMissionDelivery;
use App\Services\Ai\RealExecution\AtlasMissionService;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * G3 — o fio HTTP/Job da missão: POST /ai/missions → job em background →
 * registro durável p/ polling. A cadeia de entrega (certificação + branch,
 * nunca main) é a EXISTENTE do AtlasMissionService — aqui prova-se o transporte.
 */
class AtlasMissionDeliveryHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        (require database_path('migrations/2026_06_11_200000_create_atlas_mission_deliveries_table.php'))->up();
    }

    protected function tearDown(): void
    {
        (require database_path('migrations/2026_06_11_200000_create_atlas_mission_deliveries_table.php'))->down();

        parent::tearDown();
    }

    /**
     * @return array<string,string>
     */
    private function authHeaders(): array
    {
        return ['X-Atlas-Token' => 'test-token-with-enough-length-123'];
    }

    public function test_endpoint_disabled_by_default_returns_503(): void
    {
        config(['atlas.mission.http_delivery_enabled' => false]);

        $response = $this->postJson('/ai/missions', ['request' => 'build a tiny demo improvement'], $this->authHeaders());

        $response->assertStatus(503);
        $this->assertSame(0, AtlasMissionDelivery::query()->count());
    }

    public function test_post_enqueues_job_and_returns_202_with_poll_url(): void
    {
        config(['atlas.mission.http_delivery_enabled' => true]);
        Queue::fake();

        $response = $this->postJson('/ai/missions', [
            'request' => 'add a guard clause to the demo service',
            'operator_id' => 'vitor',
        ], $this->authHeaders());

        $response->assertStatus(202);
        $id = $response->json('id');
        $this->assertNotEmpty($id);
        $this->assertSame('queued', $response->json('status'));
        $this->assertSame('/ai/missions/'.$id, $response->json('poll'));

        Queue::assertPushed(DeliverAtlasMissionJob::class, fn (DeliverAtlasMissionJob $job): bool => $job->deliveryId === $id);

        $row = AtlasMissionDelivery::query()->findOrFail($id);
        $this->assertSame(AtlasMissionDelivery::STATUS_QUEUED, $row->status);
        $this->assertSame('vitor', $row->operator_id);
    }

    public function test_job_runs_mission_chain_and_persists_delivered_envelope_for_polling(): void
    {
        config(['atlas.mission.http_delivery_enabled' => true]);

        $delivery = AtlasMissionDelivery::query()->create([
            'request' => 'improve the demo snippet',
            'status' => AtlasMissionDelivery::STATUS_QUEUED,
            'requested_via' => 'http',
        ]);

        $envelope = [
            'schema_version' => 'atlas.ai.mission.v1',
            'mission_id' => 'mission-demo-1',
            'delivered' => true,
            'branch' => 'atlas/materialize/mission-demo-1',
            'main_untouched' => true,
            'never_merged' => true,
        ];
        $service = $this->createMock(AtlasMissionService::class);
        $service->expects($this->once())->method('run')
            ->with('improve the demo snippet')
            ->willReturn($envelope);
        $this->app->instance(AtlasMissionService::class, $service);

        (new DeliverAtlasMissionJob((string) $delivery->id))->handle($this->app->make(AtlasMissionService::class));

        $delivery->refresh();
        $this->assertSame(AtlasMissionDelivery::STATUS_DELIVERED, $delivery->status);
        $this->assertSame('mission-demo-1', $delivery->mission_id);
        $this->assertSame('atlas/materialize/mission-demo-1', $delivery->branch);
        $this->assertTrue((bool) data_get($delivery->result, 'never_merged'));

        // Polling devolve o envelope completo.
        $poll = $this->getJson('/ai/missions/'.$delivery->id, $this->authHeaders());
        $poll->assertOk();
        $this->assertSame('delivered', $poll->json('status'));
        $this->assertSame('atlas/materialize/mission-demo-1', $poll->json('branch'));
        $this->assertTrue((bool) $poll->json('result.main_untouched'));
    }

    public function test_job_records_blocked_and_failed_outcomes(): void
    {
        $blocked = AtlasMissionDelivery::query()->create([
            'request' => 'blocked mission',
            'status' => AtlasMissionDelivery::STATUS_QUEUED,
            'requested_via' => 'http',
        ]);
        $service = $this->createMock(AtlasMissionService::class);
        $service->method('run')->willReturn(['delivered' => false, 'mission_id' => 'm-b', 'stage' => 'delivery', 'reason' => 'not_certified']);
        (new DeliverAtlasMissionJob((string) $blocked->id))->handle($service);
        $this->assertSame(AtlasMissionDelivery::STATUS_BLOCKED, $blocked->refresh()->status);

        $failing = AtlasMissionDelivery::query()->create([
            'request' => 'failing mission',
            'status' => AtlasMissionDelivery::STATUS_QUEUED,
            'requested_via' => 'http',
        ]);
        $service = $this->createMock(AtlasMissionService::class);
        $service->method('run')->willThrowException(new \RuntimeException('provider exploded'));
        (new DeliverAtlasMissionJob((string) $failing->id))->handle($service);
        $failing->refresh();
        $this->assertSame(AtlasMissionDelivery::STATUS_FAILED, $failing->status);
        $this->assertStringContainsString('provider exploded', (string) $failing->error);
    }
}
