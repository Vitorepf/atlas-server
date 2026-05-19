<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\Gate\VoxV5CertificationService;
use App\Services\Ai\Vox\Interlocutor\VoxInterlocutorPolicy;
use App\Services\Ai\Vox\Routing\VoxAutoModeRouter;
use App\Services\Ai\Vox\VoxSchema;
use Tests\TestCase;

/**
 * Atlas Vox V5 · certification service — focused unit tests.
 *
 * Não roda comando artisan; instancia o serviço e roda `build()` direto. Os
 * checks são puros (read-only), então isto roda em milissegundos.
 */
final class VoxV5CertificationServiceTest extends TestCase
{
    private function service(): VoxV5CertificationService
    {
        return new VoxV5CertificationService(
            new VoxInterlocutorPolicy(),
            new VoxAutoModeRouter(),
        );
    }

    public function test_envelope_uses_canonical_schema_and_version(): void
    {
        $envelope = $this->service()->build();

        $this->assertSame('atlas.vox.v5_certification.v1', $envelope['schema']);
        $this->assertSame(VoxV5CertificationService::SCHEMA, $envelope['schema']);
        $this->assertIsString($envelope['version']);
        $this->assertNotSame('', $envelope['version']);
        $this->assertIsString($envelope['generated_at']);
    }

    public function test_status_is_pass_in_a_clean_tree(): void
    {
        $envelope = $this->service()->build();

        $this->assertSame(
            VoxV5CertificationService::STATUS_PASS,
            $envelope['status'],
            'Cert V5 deveria estar PASS; falhas: '
            .implode(', ', $envelope['summary']['failures'] ?? [])
            .' · warnings: '.implode(', ', $envelope['summary']['warnings'] ?? []),
        );
    }

    public function test_envelope_carries_every_required_check_id(): void
    {
        $envelope = $this->service()->build();
        $ids = array_map(static fn ($c) => $c['check'] ?? null, $envelope['checks']);

        $required = [
            'policy_cases_passed',
            'blocking_cases_passed',
            'intervention_schema_valid',
            'pt_br_copy_check',
            'no_voice_realtime_touch',
            'no_mobile_touch',
            'no_paid_api_dependency',
            'no_terminal_auto_execute',
            'v4_auto_mode_still_available',
            'frontend_intervention_rendering_available',
            'tests_declared',
        ];
        foreach ($required as $id) {
            $this->assertContains($id, $ids, "Check `{$id}` ausente do envelope V5");
        }
    }

    public function test_summary_totals_match_checks_count(): void
    {
        $envelope = $this->service()->build();
        $checks = $envelope['checks'];
        $totals = $envelope['summary']['totals'];
        $sum = ($totals['pass'] ?? 0) + ($totals['warn'] ?? 0) + ($totals['fail'] ?? 0);

        $this->assertSame(count($checks), $sum, 'Totais não batem com a contagem de checks');
        $this->assertSame(count($checks), $envelope['summary']['checks_count'] ?? null);
    }

    public function test_each_check_message_is_in_portuguese_only(): void
    {
        $envelope = $this->service()->build();

        foreach ($envelope['checks'] as $check) {
            $message = (string) ($check['message'] ?? '');
            // Mensagens visíveis ao operador (não chave de campo) devem ficar
            // em PT-BR. Não testamos `details` porque carregam IDs canônicos.
            $this->assertDoesNotMatchRegularExpression(
                '/\b(?:please|sorry|warning|are you sure|confirm)\b/i',
                $message,
                "Mensagem do check `{$check['check']}` vazou inglês: {$message}",
            );
        }
    }

    public function test_v4_auto_mode_check_actually_invokes_the_router(): void
    {
        $envelope = $this->service()->build();
        $check = $this->checkById($envelope, 'v4_auto_mode_still_available');

        $this->assertSame(VoxV5CertificationService::STATUS_PASS, $check['status']);
        $this->assertSame(
            VoxSchema::AUTO_MODE_DECISION,
            $check['details']['schema'] ?? null,
            'V4 schema canônico deveria aparecer no detail do check',
        );
        $this->assertSame(
            VoxSchema::MODE_INTENT_COMPILE,
            $check['details']['selected_mode'] ?? null,
        );
    }

    public function test_blocking_cases_check_lists_canonical_destructive_ids(): void
    {
        $envelope = $this->service()->build();
        $check = $this->checkById($envelope, 'blocking_cases_passed');

        $this->assertSame(VoxV5CertificationService::STATUS_PASS, $check['status']);
        $ids = $check['details']['blocking_case_ids'] ?? [];
        $this->assertContains('rm_rf_blocks', $ids);
        $this->assertContains('drop_database_blocks', $ids);
        $this->assertContains('apaga_tudo_blocks', $ids);
        $this->assertContains('curl_pipe_shell_blocks', $ids);
        $this->assertContains('git_reset_hard_r4_blocks', $ids);
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function checkById(array $envelope, string $id): array
    {
        foreach ($envelope['checks'] as $check) {
            if (($check['check'] ?? null) === $id) {
                return $check;
            }
        }
        $this->fail("Check `{$id}` não encontrado no envelope");
    }
}
