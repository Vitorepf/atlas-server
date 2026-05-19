<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\Gate\VoxV5CertificationService;
use App\Services\Ai\Vox\Gate\VoxV6CertificationService;
use App\Services\Ai\Vox\Interlocutor\VoxInterlocutorPolicy;
use App\Services\Ai\Vox\Routing\VoxAutoModeRouter;
use Tests\TestCase;

/**
 * Atlas Vox V6 · Regression Wall (Onda V6-REGRESSION-WALL-FINAL).
 *
 * Cada teste abaixo prova que UM check da muralha continua catalogado no
 * envelope da `VoxV6CertificationService`. Se alguém remover um check
 * sem trocar por equivalente, esses testes alertam imediatamente — antes
 * do release-check fechar PASS mentindo.
 *
 * Não tocamos microfone, não chamamos provider, não fazemos rede.
 * `build()` da service é puro/read-only.
 */
final class VoxV6RegressionWallTest extends TestCase
{
    private function service(): VoxV6CertificationService
    {
        return new VoxV6CertificationService(
            new VoxInterlocutorPolicy(),
            new VoxAutoModeRouter(),
            new VoxV5CertificationService(
                new VoxInterlocutorPolicy(),
                new VoxAutoModeRouter(),
            ),
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function checksById(): array
    {
        $envelope = $this->service()->build();
        $byId = [];
        foreach (($envelope['checks'] ?? []) as $check) {
            $id = (string) ($check['check'] ?? '');
            if ($id !== '') {
                $byId[$id] = $check;
            }
        }

        return $byId;
    }

    public function test_regression_wall_payload_session_id_root_check_is_catalogued(): void
    {
        $checks = $this->checksById();
        $this->assertArrayHasKey(
            'regression_wall_payload_session_id_root',
            $checks,
            'Check de payload session_id root sumiu — bridge.ts pode regredir para `{ transcript: {...} }` sem alarme.',
        );
        $this->assertContains(
            (string) $checks['regression_wall_payload_session_id_root']['status'],
            [VoxV6CertificationService::STATUS_PASS, VoxV6CertificationService::STATUS_WARN],
        );
    }

    public function test_regression_wall_no_tokens_in_manifests_check_is_catalogued(): void
    {
        $checks = $this->checksById();
        $this->assertArrayHasKey(
            'regression_wall_no_tokens_in_manifests',
            $checks,
            'Varredura de tokens em manifests sumiu — ATLAS_TOKEN pode vazar para git/CI sem alarme.',
        );
    }

    public function test_regression_wall_raw_pcm_invariant_in_rust_check_is_catalogued(): void
    {
        $checks = $this->checksById();
        $this->assertArrayHasKey(
            'regression_wall_raw_pcm_invariant_in_rust',
            $checks,
            'Invariante raw_pcm_persisted=false (Lei 0.9) sumiu da cert.',
        );
    }

    public function test_regression_wall_signing_identity_camel_case_check_is_catalogued(): void
    {
        $checks = $this->checksById();
        $this->assertArrayHasKey(
            'regression_wall_signing_identity_camel_case',
            $checks,
            'Check estrito de signingIdentity (camelCase) sumiu — tauri.conf.json pode migrar para kebab-case silenciosamente.',
        );
    }

    public function test_overlay_humanizes_raw_errors_check_is_catalogued(): void
    {
        $checks = $this->checksById();
        $this->assertArrayHasKey(
            'ux_overlay_humanizes_raw_errors',
            $checks,
            'Check de humanização de erro do overlay sumiu — banner pode voltar a vazar JSON cru ou enum Rust.',
        );
    }

    public function test_v7_unlock_remains_blocked_after_regression_wall_additions(): void
    {
        $envelope = $this->service()->build();
        $this->assertFalse(
            $envelope['v7_unlock_allowed'],
            'Mesmo com todos os novos checks de muralha, V7 NUNCA destrava automaticamente.',
        );
    }

    public function test_regression_wall_checks_are_grouped_under_other_area(): void
    {
        // Defesa final: os 4 checks novos têm prefixo "regression_wall_",
        // que cai em `areaOf()` como `other` — propositalmente, para que
        // o resumo by_area continue limpo (não inflar nenhuma área
        // canônica). Esse teste só prova que pelo menos um check existe
        // e não rompe a contagem.
        $envelope = $this->service()->build();
        $regressionChecks = array_filter(
            $envelope['checks'] ?? [],
            fn ($c) => str_starts_with((string) ($c['check'] ?? ''), 'regression_wall_'),
        );
        $this->assertGreaterThanOrEqual(
            4,
            count($regressionChecks),
            'A muralha tem que ter pelo menos 4 checks `regression_wall_*` registrados.',
        );
    }
}
