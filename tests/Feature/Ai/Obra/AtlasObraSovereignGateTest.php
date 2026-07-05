<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Obra;

use App\Services\Ai\EngineeringKernel\Adapters\AtlasObraGateAdapter;
use App\Services\Ai\Obra\AtlasObraCertificationService;
use Tests\TestCase;

/**
 * OBRA #5 S1 — o juiz de entrega de OBRA sob o piso soberano (ACs 1.1-1.4).
 * Default observe: veredito soberano vira raio-X no envelope sem alterar o local.
 * Enforce: REFUSE do floor só APERTA (certified true -> false), nunca afrouxa; throw = fail-closed.
 */
final class AtlasObraSovereignGateTest extends TestCase
{
    /** @return array<string,mixed> input de obra legítima (integrated check rodou e passou) */
    private function greenInput(): array
    {
        return [
            'obra_id' => 'obra-teste',
            'branch' => 'atlas/obra/teste',
            'halted' => false,
            'nodes' => [
                ['id' => 'n1', 'seq' => 1, 'status' => 'certified', 'files_changed' => ['app/A.php']],
            ],
            'integrated' => ['ran' => true, 'passed' => true, 'exit' => 0, 'command' => 'php artisan test --filter=X'],
            'integrated_supplied' => true,
        ];
    }

    public function test_observe_default_records_sovereign_verdict_without_changing_local_one(): void
    {
        config()->set('atlas.engineering_kernel.obra_certifier_gate_mode', 'observe');
        $envelope = app(AtlasObraCertificationService::class)->certify($this->greenInput());

        $this->assertTrue($envelope['certified'], 'observe nunca altera o veredito local');
        $this->assertSame('certified', $envelope['status']);
        $this->assertSame('observe', $envelope['sovereign_verdict']['mode']);
        $this->assertIsArray($envelope['sovereign_verdict']['blockers']);
    }

    public function test_enforce_tightens_locally_certified_envelope_when_floor_refuses(): void
    {
        // A evidência da obra ainda não treda judges/context — em enforce o floor recusa
        // e o veredito local É apertado (only-adds). Este teste TRAVA essa semântica.
        config()->set('atlas.engineering_kernel.obra_certifier_gate_mode', 'enforce');
        $envelope = app(AtlasObraCertificationService::class)->certify($this->greenInput());

        $this->assertFalse($envelope['certified']);
        $this->assertSame('needs_review', $envelope['status']);
        $this->assertStringStartsWith('sovereign_floor:', (string) $envelope['reason']);
    }

    public function test_enforce_never_loosens_a_locally_failed_envelope(): void
    {
        config()->set('atlas.engineering_kernel.obra_certifier_gate_mode', 'enforce');
        $input = $this->greenInput();
        $input['halted'] = true;
        $input['failed_node'] = 'n1';

        $envelope = app(AtlasObraCertificationService::class)->certify($input);

        $this->assertFalse($envelope['certified'], 'halted continua não-certified sob qualquer modo');
    }

    public function test_fake_green_is_blocked_by_floor_in_enforce(): void
    {
        // AC-1.1: envelope que alega certified com integrated check NÃO rodado — o certifier
        // local já recusa por fail-closed próprio; o floor recusa TAMBÉM via adapter direto
        // (claimed pass com tests_run=0 é o invariante nº1).
        $verdict = (new AtlasObraGateAdapter)->certifyObraDelivery([
            'certified' => true,
            'receipt_hash' => 'abc',
            'integrated_test_result' => ['supplied' => true, 'ran' => false, 'passed' => false],
            'nodes' => [],
        ]);

        $this->assertFalse($verdict->promoted());
        $this->assertContains('false_claim_blocked', $verdict->blockers);
    }

    public function test_gate_error_is_fail_closed_in_enforce_and_harmless_in_observe(): void
    {
        // O container devolve o que estiver bound — o double não precisa (nem pode, final)
        // estender o adapter; basta responder certifyObraDelivery().
        $throwing = new class
        {
            public function certifyObraDelivery(array $envelope): never
            {
                throw new \RuntimeException('gate indisponível');
            }
        };
        $this->app->instance(AtlasObraGateAdapter::class, $throwing);

        config()->set('atlas.engineering_kernel.obra_certifier_gate_mode', 'enforce');
        $enforced = app(AtlasObraCertificationService::class)->certify($this->greenInput());
        $this->assertFalse($enforced['certified'], 'throw em enforce = fail-closed');
        $this->assertSame('sovereign_gate_error', $enforced['reason']);

        config()->set('atlas.engineering_kernel.obra_certifier_gate_mode', 'observe');
        $observed = app(AtlasObraCertificationService::class)->certify($this->greenInput());
        $this->assertTrue($observed['certified'], 'throw em observe nunca derruba a obra');
        $this->assertArrayHasKey('error', $observed['sovereign_verdict']);
    }

    public function test_off_mode_leaves_envelope_without_sovereign_verdict(): void
    {
        config()->set('atlas.engineering_kernel.obra_certifier_gate_mode', 'off');
        $envelope = app(AtlasObraCertificationService::class)->certify($this->greenInput());

        $this->assertArrayNotHasKey('sovereign_verdict', $envelope);
        $this->assertTrue($envelope['certified']);
    }
}
