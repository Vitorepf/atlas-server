<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\Gate\VoxV5CertificationService;
use App\Services\Ai\Vox\Gate\VoxV6CertificationService;
use App\Services\Ai\Vox\Interlocutor\VoxInterlocutorPolicy;
use App\Services\Ai\Vox\Routing\VoxAutoModeRouter;
use Tests\TestCase;

/**
 * Atlas Vox V6 · certification service — focused unit tests.
 *
 * Não roda comando artisan; instancia o serviço e chama `build()` direto.
 * O serviço é puro (read-only) — roda em milissegundos.
 *
 * Estes testes garantem invariantes que NÃO podem regredir mesmo após
 * dogfood real, e cobrem o contrato V6-F do envelope.
 */
final class VoxV6CertificationServiceTest extends TestCase
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

    public function test_envelope_uses_canonical_schema_and_version(): void
    {
        $envelope = $this->service()->build();

        $this->assertSame('atlas.vox.v6_certification.v1', $envelope['schema']);
        $this->assertSame(VoxV6CertificationService::SCHEMA, $envelope['schema']);
        $this->assertIsString($envelope['version']);
        $this->assertNotSame('', $envelope['version']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T/',
            (string) $envelope['generated_at'],
        );
    }

    public function test_v7_unlock_is_always_blocked(): void
    {
        $envelope = $this->service()->build();
        $this->assertFalse(
            $envelope['v7_unlock_allowed'],
            'v7_unlock_allowed deve permanecer SEMPRE false em V6 — destrava só por nova ADR.',
        );
    }

    public function test_status_is_not_fail_in_a_clean_tree(): void
    {
        $envelope = $this->service()->build();
        $status = (string) $envelope['status'];
        $this->assertContains(
            $status,
            [VoxV6CertificationService::STATUS_PASS, VoxV6CertificationService::STATUS_WARN],
            'Cert V6 não deveria fechar em fail num tree limpo. Falhas: '
            .implode(', ', $envelope['summary']['failures'] ?? []),
        );
        $this->assertTrue(
            $envelope['v6_ready_for_dogfood'],
            'v6_ready_for_dogfood deveria ser true quando status != fail.',
        );
    }

    public function test_summary_by_area_covers_all_five_areas(): void
    {
        $envelope = $this->service()->build();
        $byArea = $envelope['summary']['by_area'] ?? [];

        foreach (['backend', 'desktop', 'macos', 'ux', 'safety'] as $area) {
            $this->assertArrayHasKey($area, $byArea, "área {$area} ausente no summary");
            $counts = $byArea[$area];
            $this->assertIsArray($counts);
            $this->assertArrayHasKey('pass', $counts);
            $this->assertArrayHasKey('warn', $counts);
            $this->assertArrayHasKey('fail', $counts);
        }
    }

    public function test_backend_safety_checks_pass(): void
    {
        $envelope = $this->service()->build();
        $checks = $envelope['checks'] ?? [];
        $checksById = [];
        foreach ($checks as $c) {
            $checksById[$c['check']] = $c;
        }
        // Estes checks são determinísticos a partir do source — NUNCA podem
        // virar warn/fail num tree limpo. Cada um é uma barreira de doutrina.
        $required = [
            'backend_v4_auto_mode_available',
            'backend_v5_interlocutor_available',
            'backend_no_raw_audio_persisted',
            'backend_no_paid_api_dependency',
            'backend_no_voice_realtime_touched',
            'backend_no_mobile_touched',
            'safety_no_terminal_auto_execute',
            'safety_r4_blocks',
            'safety_receipt_required',
        ];
        foreach ($required as $id) {
            $this->assertArrayHasKey($id, $checksById, "check {$id} ausente");
            $this->assertSame(
                VoxV6CertificationService::STATUS_PASS,
                $checksById[$id]['status'],
                "check {$id} não passou: ".($checksById[$id]['message'] ?? ''),
            );
        }
    }

    public function test_v6_ready_for_dogfood_is_false_when_any_check_fails(): void
    {
        // Sentinel: invocar build() devolve uma estrutura coerente. Se algum
        // dia algum check vier 'fail', `v6_ready_for_dogfood` precisa cair pra
        // false. Não simulamos falha aqui (todos os checks são puros e
        // determinísticos no source-tree), mas validamos a lógica de agregação.
        $envelope = $this->service()->build();
        if ($envelope['status'] === VoxV6CertificationService::STATUS_FAIL) {
            $this->assertFalse($envelope['v6_ready_for_dogfood']);
        } else {
            $this->assertTrue($envelope['v6_ready_for_dogfood']);
        }
    }

    public function test_checks_have_required_fields(): void
    {
        $envelope = $this->service()->build();
        foreach ($envelope['checks'] as $check) {
            foreach (['check', 'status', 'message', 'details'] as $field) {
                $this->assertArrayHasKey($field, $check, "check missing {$field}");
            }
            $this->assertContains(
                $check['status'],
                [
                    VoxV6CertificationService::STATUS_PASS,
                    VoxV6CertificationService::STATUS_WARN,
                    VoxV6CertificationService::STATUS_FAIL,
                ],
                'check status fora do enum canônico',
            );
        }
    }

    public function test_warn_status_provides_next_actions(): void
    {
        $envelope = $this->service()->build();
        if ($envelope['status'] === VoxV6CertificationService::STATUS_WARN) {
            $this->assertNotEmpty(
                $envelope['next_actions'],
                'status=warn precisa explicar next_actions concretas.',
            );
            // Cada warn precisa aparecer em next_actions.
            $warnings = $envelope['summary']['warnings'] ?? [];
            foreach ($warnings as $w) {
                $found = false;
                foreach ($envelope['next_actions'] as $action) {
                    if (str_contains((string) $action, $w)) {
                        $found = true;
                        break;
                    }
                }
                $this->assertTrue(
                    $found,
                    "warning {$w} não foi explicado em next_actions.",
                );
            }
        } else {
            // Em pass, next_actions descreve o handbook de dogfood.
            $this->assertNotEmpty(
                $envelope['next_actions'],
                'next_actions sempre deve ter pelo menos uma orientação.',
            );
        }
    }

    public function test_envelope_is_serializable_to_json(): void
    {
        $envelope = $this->service()->build();
        $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertIsString($json);
        $this->assertGreaterThan(500, strlen($json));
        $decoded = json_decode($json, true);
        $this->assertSame($envelope['schema'], $decoded['schema']);
        $this->assertSame($envelope['v7_unlock_allowed'], $decoded['v7_unlock_allowed']);
    }
}
