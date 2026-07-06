<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Tests\TestCase;

/**
 * OBRA #7 W1 — regressão do flip observe→enforce do admission gate v2.
 * A prova do flip (auditoria da janela observe: 174 receipts, 114 blockers todos true-positive,
 * zero falso-positivo) está no commit; aqui fica o que NÃO pode regredir:
 *   1. o default do modo é 'enforce' (voltar a observe silenciosamente = produtor sem freio);
 *   2. o envelope do next carrega as delivery_rules — sem a regra visível o worker não tem como
 *      cumprir e enforce viraria jam de fila (112/174 entregas de classe-nova chegavam 0-ref).
 * Os comportamentos dos gates em si têm suíte própria (AtlasTaskAdmissionGateV2Test, 14 testes).
 */
final class AtlasTaskAdmissionV2EnforceFlipTest extends TestCase
{
    public function test_admission_v2_default_mode_is_enforce(): void
    {
        $this->assertSame('enforce', config('atlas_task_governance.admission_v2_mode'));
    }

    public function test_served_task_projection_carries_delivery_rules(): void
    {
        $service = $this->app->make(AtlasTaskServingService::class);
        $m = new \ReflectionMethod($service, 'projectTask');
        $projection = $m->invoke($service, [
            'task_packet_id' => 'tp-x',
            'lease_id' => 'l-x',
            'queue_entry' => ['task_packet' => [
                'task_packet_id' => 'tp-x',
                'objective' => 'obj',
                'normalized_scope' => ['allowed_files' => ['app/X.php'], 'forbidden_files' => [], 'scope_in' => []],
            ]],
        ]);

        $this->assertArrayHasKey('delivery_rules', $projection);
        $this->assertArrayHasKey('no_duplicate_logic', $projection['delivery_rules']);
        $this->assertArrayHasKey('wired_or_tagged', $projection['delivery_rules']);
        $this->assertStringContainsString('@unwired-until', $projection['delivery_rules']['wired_or_tagged']);
    }
}
