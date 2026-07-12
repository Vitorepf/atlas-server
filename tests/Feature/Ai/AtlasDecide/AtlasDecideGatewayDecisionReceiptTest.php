<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class Maxk04StubAdml extends AtlasDecideMetaLearningService
{
    /** @param array<string,mixed>|null $route */
    public function __construct(private readonly ?array $route = null) {}

    public function activeRouteFor(string $taskCategory, string $role, ?string $framework = null): ?array
    {
        return $this->route;
    }
}

final class AtlasDecideGatewayDecisionReceiptTest extends TestCase
{
    private string $kernelLog;

    private string $admissionLog;

    private string $consultLog;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        $u = uniqid('', true);
        $this->kernelLog = sys_get_temp_dir()."/atlas_maxk04_kernel_{$u}.jsonl";
        $this->admissionLog = sys_get_temp_dir()."/atlas_maxk04_admission_{$u}.jsonl";
        $this->consultLog = sys_get_temp_dir()."/atlas_maxk04_consult_{$u}.jsonl";
    }

    protected function tearDown(): void
    {
        @unlink($this->kernelLog);
        @unlink($this->admissionLog);
        @unlink($this->consultLog);
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_gateway_consult_emits_decision_receipt_v2_and_preserves_jsonl_envelope(): void
    {
        $service = $this->service([
            'provider' => 'claude_code',
            'model' => 'opus-4.7',
            'routing_basis' => 'cost_outcome',
            'evidence_refs' => ['live_outcome:route-window-1'],
        ]);

        $consultation = $service->consult([
            'task_category' => 'code_generation',
            'role' => 'primary',
            'framework' => 'laravel',
            'privacy_class' => 'public',
            'actor' => 'ai_gateway',
            'session_id' => 'maxk04-session',
        ]);

        $this->assertSame(AtlasDecideGatewayConsultationService::VERDICT_FOLLOW_LEARNED, $consultation['verdict']);
        $this->assertSame('cost_outcome', $consultation['routing_basis']);
        $this->assertIsString($consultation['decision_id'] ?? null);
        $this->assertSame($consultation['decision_id'], data_get($consultation, 'decision_receipt.receipt_id'));
        $this->assertSame('atlas.decide.v2', data_get($consultation, 'decision_receipt.schema_version'));
        $this->assertSame('cost_outcome', data_get($consultation, 'decision_receipt.metadata.routing_basis'));
        $this->assertContains('live_outcome:route-window-1', data_get($consultation, 'decision_receipt.metadata.evidence_refs'));

        $jsonl = $service->listConsultations()[0] ?? [];
        $this->assertSame(AtlasDecideGatewayConsultationService::ENVELOPE_SCHEMA, $jsonl['schema_version'] ?? null);
        $this->assertArrayHasKey('active_route', $jsonl);
        $this->assertArrayHasKey('kernel_decision', $jsonl);
        $this->assertArrayHasKey('admission_decision', $jsonl);

        $exit = Artisan::call('atlas:ai:decision-receipt-report', [
            '--envelope' => data_get($consultation, 'decision_receipt.envelope_id'),
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, data_get($report, 'decision_receipt_replay.decision_event_count'));
        $this->assertSame('cost_outcome', data_get($report, 'decision_receipt_replay.events.0.routing_basis'));
        $this->assertContains('kernel_decision:allow', data_get($report, 'decision_receipt_replay.events.0.evidence_refs'));
        $this->assertContains('live_outcome:route-window-1', data_get($report, 'decision_receipt_replay.events.0.evidence_refs'));
    }

    /** @param array<string,mixed>|null $route */
    private function service(?array $route): AtlasDecideGatewayConsultationService
    {
        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);

        $service = new AtlasDecideGatewayConsultationService(
            new Maxk04StubAdml($route),
            $kernel,
            $admission,
        );
        $service->setLogPathForTesting($this->consultLog);

        return $service;
    }
}
