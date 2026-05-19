<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Vox;

use App\Services\Ai\Vox\Dogfood\VoxDogfoodService;
use App\Services\Ai\Vox\Dogfood\VoxDogfoodSummaryService;
use App\Services\Ai\Vox\Gate\VoxV7UnlockGateService;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use Tests\TestCase;

/**
 * Garante o canon do resumo humano V6-H:
 *   - frases PT-BR sempre presentes.
 *   - `ready_for_daily_use=false` quando não houve uso real.
 *   - V7 segue bloqueada com lista de razões.
 */
final class VoxDogfoodSummaryServiceTest extends TestCase
{
    private function service(): VoxDogfoodSummaryService
    {
        $metrics = $this->app->make(VoxMetricsService::class);
        $dogfood = $this->app->make(VoxDogfoodService::class);

        return new VoxDogfoodSummaryService(
            $metrics,
            $dogfood,
            new VoxV7UnlockGateService($metrics, $dogfood),
        );
    }

    public function test_envelope_uses_canonical_schema(): void
    {
        $env = $this->service()->build();

        $this->assertSame(VoxDogfoodSummaryService::SCHEMA, $env['schema']);
        $this->assertSame('atlas.vox.dogfood_summary.v1', $env['schema']);
        $this->assertNotSame('', $env['version']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T/',
            (string) $env['generated_at'],
        );
    }

    public function test_sentences_always_present(): void
    {
        $env = $this->service()->build();
        $this->assertNotEmpty($env['sentences_pt_br'], 'pelo menos uma frase PT-BR é obrigatória');
        // Frase canon: "Você usou o Atlas Vox X vezes."
        $this->assertStringContainsString(
            'Você usou o Atlas Vox',
            (string) $env['sentences_pt_br'][0],
        );
        // V7 sempre presente no fim.
        $hasV7Line = false;
        foreach ($env['sentences_pt_br'] as $line) {
            if (str_contains((string) $line, 'V7')) {
                $hasV7Line = true;
                break;
            }
        }
        $this->assertTrue($hasV7Line, 'esperava uma frase sobre V7 no resumo');
    }

    public function test_ready_for_daily_use_false_without_usage(): void
    {
        $env = $this->service()->build();
        $this->assertFalse(
            $env['ready_for_daily_use'],
            'sem uso real registrado, ready_for_daily_use precisa ser false.',
        );
    }

    public function test_v7_blockers_present(): void
    {
        $env = $this->service()->build();
        $this->assertNotSame('', $env['v7_status_pt_br']);
        $this->assertIsArray($env['v7_blockers_pt_br']);
        $this->assertNotEmpty($env['v7_blockers_pt_br']);
    }

    public function test_numbers_block_has_required_fields(): void
    {
        $env = $this->service()->build();
        foreach (['total_sessions', 'success_rate', 'regret_rate', 'raw_audio_persisted_count', 'destructive_action_without_receipt'] as $field) {
            $this->assertArrayHasKey($field, $env['numbers'], "numbers.{$field} ausente");
        }
        $this->assertSame(0, $env['numbers']['raw_audio_persisted_count']);
        $this->assertSame(0, $env['numbers']['destructive_action_without_receipt']);
    }

    public function test_envelope_serialises_to_json(): void
    {
        $env = $this->service()->build();
        $json = json_encode($env, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertIsString($json);
        $this->assertGreaterThan(200, strlen($json));
    }
}
