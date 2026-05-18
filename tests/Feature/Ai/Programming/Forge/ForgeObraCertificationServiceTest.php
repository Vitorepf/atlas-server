<?php

namespace Tests\Feature\Ai\Programming\Forge;

use App\Models\AiForgeMilestone;
use App\Models\AiForgeWorkPacket;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use App\Services\Ai\Programming\Forge\ForgeIntakeCanon;
use App\Services\Ai\Programming\Forge\ForgeIntakeService;
use App\Services\Ai\Programming\Forge\Qa\ForgeObraCertificationService;
use App\Services\Ai\Programming\Forge\Qa\ForgeQaGateRunner;
use Tests\Concerns\CreatesForgeIntakeTables;
use Tests\TestCase;

class ForgeObraCertificationServiceTest extends TestCase
{
    use CreatesForgeIntakeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createForgeIntakeTables();
    }

    protected function tearDown(): void
    {
        $this->dropForgeIntakeTables();
        parent::tearDown();
    }

    public function test_heavy_obra_without_sdd_spec_fails_certification_with_explicit_blocker(): void
    {
        $intake = $this->intakeService()->intakeFromPrompt(
            'Implementar refator multi-modulo do provider router com sdd e fallback governado',
            [
                'workspace_slug' => 'atlas-server',
                'risk_band' => ForgeIntakeCanon::RISK_BAND_HIGH,
                'recommended_forge_mode' => EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
            ],
        );

        $cert = app(ForgeObraCertificationService::class)->certify($intake);

        $this->assertSame(ForgeIntakeCanon::OBRA_CERTIFICATION_SCHEMA_VERSION, $cert['schema_version']);
        $this->assertTrue($cert['is_heavy_obra'], 'high risk + sdd_intake mode must be classified as heavy Obra');
        $this->assertSame(ForgeObraCertificationService::STATUS_FAILED, $cert['status']);

        $specCheck = $this->checkBy($cert, 'spec_complete');
        $this->assertSame(ForgeQaGateRunner::STATUS_FAILED, $specCheck['status']);
        $this->assertNotEmpty($specCheck['reasons']);

        $blockerGates = array_column($cert['blockers'], 'gate_id');
        $this->assertContains('spec_complete', $blockerGates);
        $this->assertContains('certification_ready', $blockerGates);

        $this->assertNotEmpty($cert['remediation'], 'failed gates must emit at least one remediation candidate');
    }

    public function test_heavy_obra_with_partial_sdd_fails_with_missing_section_blocker(): void
    {
        $intake = $this->intakeService()->intakeFromPrompt(
            'Migrar billing engine multi-tenant com governance multi-stage',
            [
                'workspace_slug' => 'atlas-server',
                'risk_band' => ForgeIntakeCanon::RISK_BAND_CRITICAL,
                'recommended_forge_mode' => EscalationPacket::RECOMMENDED_FORGE_MODE_OBRA_INTAKE,
                'sdd_spec' => [
                    'problem_statement' => 'Billing engine não suporta multi-tenant.',
                    'scope' => 'Refatorar engine para suportar tenants.',
                    'non_goals' => ['Migrar legacy reports'],
                    'constraints' => ['Sem breaking changes na API pública'],
                    'architecture_notes' => ['Adapter pattern por tenant'],
                    'acceptance_criteria' => ['Tenants isolados em queries'],
                    // verification_plan omitido propositalmente
                    'risks' => ['Data leakage entre tenants'],
                    'required_evidence' => ['plan', 'evidence_pack', 'certification'],
                ],
            ],
        );

        $cert = app(ForgeObraCertificationService::class)->certify($intake);

        $this->assertSame(ForgeObraCertificationService::STATUS_FAILED, $cert['status']);

        $specCheck = $this->checkBy($cert, 'spec_complete');
        $this->assertSame(ForgeQaGateRunner::STATUS_FAILED, $specCheck['status']);
        $verifyCheck = $this->checkBy($cert, 'verification_plan_defined');
        $this->assertSame(ForgeQaGateRunner::STATUS_FAILED, $verifyCheck['status']);
        $this->assertContains('sdd_spec_verification_plan_empty', $verifyCheck['reasons']);
    }

    public function test_heavy_obra_with_complete_sdd_and_tests_passes_certification(): void
    {
        $intake = $this->intakeService()->intakeFromPrompt(
            'Implementar refator multi-modulo do provider router com sdd; criar suite tests; atualizar docs canonical.',
            [
                'workspace_slug' => 'atlas-server',
                'risk_band' => ForgeIntakeCanon::RISK_BAND_HIGH,
                'recommended_forge_mode' => EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
                'sdd_spec' => $this->canonicalSpec(),
            ],
        );

        // Garantir que cada packet tem testes declarados.
        AiForgeWorkPacket::query()
            ->where('intake_id', $intake->id)
            ->each(function (AiForgeWorkPacket $packet): void {
                $packet->suggested_tests = ['tests/Feature/Provider/RouterTest.php::test_route_fallback'];
                $packet->save();
            });

        $cert = app(ForgeObraCertificationService::class)->certify($intake);

        $this->assertSame(ForgeObraCertificationService::STATUS_PASSED, $cert['status']);
        $this->assertSame([], $cert['blockers']);
        $this->assertSame([], $cert['remediation']);
        foreach ($cert['checks'] as $check) {
            $this->assertSame(ForgeQaGateRunner::STATUS_PASSED, $check['status'], "gate {$check['check_id']} should pass for complete SDD");
        }
        $this->assertSame(64, strlen($cert['certification_hash']));
        $this->assertContains('ai_forge_intakes:'.$intake->id, $cert['evidence_refs']);
    }

    public function test_certification_without_required_evidence_fails(): void
    {
        $spec = $this->canonicalSpec();
        $spec['required_evidence'] = []; // evidência exigida do SDD vazia

        $intake = $this->intakeService()->intakeFromPrompt(
            'Implementar Obra critica de migracao com gates completos',
            [
                'workspace_slug' => 'atlas-server',
                'risk_band' => ForgeIntakeCanon::RISK_BAND_HIGH,
                'recommended_forge_mode' => EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
                'sdd_spec' => $spec,
            ],
        );

        // O service preenche `required_evidence` e `definition_of_done` com
        // defaults canônicos quando o caller manda vazio — para provar que o
        // gate detecta evidência insuficiente, esvaziamos pós-creation.
        $intake->required_evidence = [];
        $intake->definition_of_done = ['ship'];
        $intake->save();

        $cert = app(ForgeObraCertificationService::class)->certify($intake->fresh());
        $this->assertSame(ForgeObraCertificationService::STATUS_FAILED, $cert['status']);

        $evidenceCheck = $this->checkBy($cert, 'evidence_ready');
        $this->assertSame(ForgeQaGateRunner::STATUS_FAILED, $evidenceCheck['status']);
        $this->assertNotEmpty($evidenceCheck['reasons']);
    }

    public function test_blocked_intake_yields_blocked_certification_with_intake_blocker(): void
    {
        $intake = $this->intakeService()->intakeFromPrompt('aleatorio random palavra nonsense');
        $this->assertSame(ForgeIntakeCanon::STATUS_BLOCKED, $intake->status);

        $cert = app(ForgeObraCertificationService::class)->certify($intake);

        $this->assertSame(ForgeObraCertificationService::STATUS_BLOCKED, $cert['status']);
        $blockerGates = array_column($cert['blockers'], 'gate_id');
        $this->assertContains('intake_status', $blockerGates);
    }

    public function test_failed_gate_emits_repair_blocker_candidate_with_remediation(): void
    {
        $intake = $this->intakeService()->intakeFromPrompt(
            'Refatorar pipeline de deploy com sdd-intake e governance',
            [
                'workspace_slug' => 'atlas-server',
                'risk_band' => ForgeIntakeCanon::RISK_BAND_HIGH,
                'recommended_forge_mode' => EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
            ],
        );

        $cert = app(ForgeObraCertificationService::class)->certify($intake);

        $this->assertSame(ForgeObraCertificationService::STATUS_FAILED, $cert['status']);
        $this->assertNotEmpty($cert['blockers']);
        foreach ($cert['blockers'] as $blocker) {
            $this->assertArrayHasKey('gate_id', $blocker);
            $this->assertArrayHasKey('kind', $blocker);
            $this->assertArrayHasKey('severity', $blocker);
            $this->assertArrayHasKey('reasons', $blocker);
            $this->assertArrayHasKey('remediation', $blocker);
            $this->assertArrayHasKey('next_action', $blocker);
        }
        foreach ($cert['remediation'] as $candidate) {
            $this->assertArrayHasKey('action', $candidate);
            $this->assertArrayHasKey('description', $candidate);
            $this->assertNotEmpty($candidate['action']);
        }
    }

    public function test_milestone_blocker_propagates_into_certification(): void
    {
        $intake = $this->intakeService()->intakeFromPrompt(
            'Implementar refator multi-modulo com sdd e fallback governado',
            [
                'workspace_slug' => 'atlas-server',
                'risk_band' => ForgeIntakeCanon::RISK_BAND_HIGH,
                'recommended_forge_mode' => EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
                'sdd_spec' => $this->canonicalSpec(),
            ],
        );
        AiForgeWorkPacket::query()
            ->where('intake_id', $intake->id)
            ->each(function (AiForgeWorkPacket $packet): void {
                $packet->suggested_tests = ['tests/Feature/Some/RegressionTest.php::test_ok'];
                $packet->save();
            });
        AiForgeMilestone::query()
            ->where('intake_id', $intake->id)
            ->where('milestone_id', ForgeIntakeCanon::MILESTONE_VERIFICATION)
            ->update(['blocker_reason' => 'manual_review_required']);

        $cert = app(ForgeObraCertificationService::class)->certify($intake);

        // Milestone blocker triggers certification_ready gate to fail; the
        // overall cert status is `failed` (not `passed`/`warn`) and surfaces
        // the milestone-level blocker for the operator/repair candidate.
        $this->assertSame(ForgeObraCertificationService::STATUS_FAILED, $cert['status']);
        $blockerGates = array_column($cert['blockers'], 'gate_id');
        $this->assertContains('milestone:'.ForgeIntakeCanon::MILESTONE_VERIFICATION, $blockerGates);
    }

    public function test_certification_hash_is_stable_for_equivalent_states(): void
    {
        // Cria duas intakes idênticas em conteúdo (uuid muda, mas o cert
        // hash exclui generated_at; ainda assim intake_id é diferente, então
        // os cert_hashes não vão coincidir entre intakes distintas. Para
        // provar estabilidade, recertificamos a MESMA intake duas vezes —
        // mesmo intake_hash + mesmo estado QA deve gerar o mesmo cert_hash.
        $intake = $this->intakeService()->intakeFromPrompt(
            'Implementar nova feature de auth com testes e governance',
            [
                'workspace_slug' => 'atlas-server',
                'risk_band' => ForgeIntakeCanon::RISK_BAND_HIGH,
                'recommended_forge_mode' => EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
                'sdd_spec' => $this->canonicalSpec(),
            ],
        );
        AiForgeWorkPacket::query()
            ->where('intake_id', $intake->id)
            ->each(function (AiForgeWorkPacket $packet): void {
                $packet->suggested_tests = ['tests/Feature/Auth/SomethingTest.php::test_ok'];
                $packet->save();
            });

        $cert1 = app(ForgeObraCertificationService::class)->certify($intake->fresh(['workPackets', 'milestones']));
        $cert2 = app(ForgeObraCertificationService::class)->certify($intake->fresh(['workPackets', 'milestones']));

        // certification_hash deve ser estável entre recertificações da mesma
        // intake porque generated_at é excluído do payload canônico antes do
        // hash. Não asseguramos que os timestamps difiram porque os testes
        // rodam dentro da mesma segunda — o ponto é a estabilidade do hash.
        $this->assertSame($cert1['certification_hash'], $cert2['certification_hash']);

        $json = json_encode($cert1, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json, 'certification payload must serialize to stable JSON');
        $decoded = json_decode((string) $json, true);
        $this->assertSame(ForgeIntakeCanon::OBRA_CERTIFICATION_SCHEMA_VERSION, $decoded['schema_version']);
        $this->assertCount(6, $decoded['checks'], '6 canonical QA checks must be present in serialized cert');
    }

    public function test_light_obra_without_sdd_warns_but_can_still_certify(): void
    {
        // Light Obra: direct intake, low risk, obra_intake mode. The SDD
        // gate emits warn (advisory) instead of failed.
        $intake = $this->intakeService()->intakeFromPrompt(
            'Adicionar pequeno helper utility para formatacao de datas em report.',
            [
                'workspace_slug' => 'atlas-server',
                'risk_band' => ForgeIntakeCanon::RISK_BAND_LOW,
                'recommended_forge_mode' => EscalationPacket::RECOMMENDED_FORGE_MODE_OBRA_INTAKE,
            ],
        );

        AiForgeWorkPacket::query()
            ->where('intake_id', $intake->id)
            ->each(function (AiForgeWorkPacket $packet): void {
                $packet->suggested_tests = ['tests/Unit/Helpers/DateFormatTest.php::test_format'];
                $packet->save();
            });

        $cert = app(ForgeObraCertificationService::class)->certify($intake);

        $this->assertFalse($cert['is_heavy_obra']);
        $this->assertSame(ForgeObraCertificationService::STATUS_WARN, $cert['status']);
        $specCheck = $this->checkBy($cert, 'spec_complete');
        $this->assertSame(ForgeQaGateRunner::STATUS_WARN, $specCheck['status']);
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalSpec(): array
    {
        return [
            'problem_statement' => 'Provider router não suporta fallback governado entre Claude/Codex/Gemini.',
            'scope' => 'Reescrever provider router com governance + fallback + receipts.',
            'non_goals' => ['Mudar provider invocation drivers reais'],
            'constraints' => ['Sem breaking changes em DTOs públicos'],
            'architecture_notes' => ['Adapter por provider', 'Fallback policy classifica falhas em 5 buckets'],
            'acceptance_criteria' => [
                'Toda escolha de provider emite route_decision.v1',
                'Fallback nunca é silencioso (sempre persistido em receipt)',
            ],
            'verification_plan' => [
                'tests/Feature/Provider/RouterTest.php',
                'php artisan atlas:forge:continuum-certify --json --strict',
            ],
            'risks' => ['Hot path em produção; rollback plan precisa ser explícito'],
            'required_evidence' => ['plan', 'context_pack', 'work_packet_receipts', 'verification_receipt', 'evidence_pack', 'certification'],
        ];
    }

    private function intakeService(): ForgeIntakeService
    {
        return app(ForgeIntakeService::class);
    }

    /**
     * @param  array<string,mixed>  $cert
     * @return array<string,mixed>
     */
    private function checkBy(array $cert, string $checkId): array
    {
        foreach ($cert['checks'] as $check) {
            if ($check['check_id'] === $checkId) {
                return $check;
            }
        }
        $this->fail("check {$checkId} not found in cert payload");
    }
}
