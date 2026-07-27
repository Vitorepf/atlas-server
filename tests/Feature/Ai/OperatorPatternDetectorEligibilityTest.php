<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\OperatorLearningSignal;
use App\Models\OperatorPatternDetection;
use App\Services\Ai\OperatorIntelligence\OperatorPatternDetector;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A rede que faltou em 0095ffa15 (o beco sem saída) e em 0f52d5b32 (o eco).
 *
 * Duas leis do detector, medidas na tabela viva antes de existirem:
 *
 *   • uma detecção gravada e nunca proposta não pode travar os runs seguintes —
 *     era o que mantinha `operator_skill_proposals` em zero;
 *   • texto que ninguém declarou como do operador não é sinal dele — foi como o
 *     preâmbulo que o Atlas dá a si mesmo virou "regra do operador".
 */
final class OperatorPatternDetectorEligibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A suíte evita RefreshDatabase (migrations Postgres-only); montamos só
        // as duas tabelas deste caminho.
        foreach ([
            '2026_06_08_130000_create_operator_learning_signals_table.php' => 'operator_learning_signals',
            '2026_06_08_140000_create_operator_pattern_detections_table.php' => 'operator_pattern_detections',
        ] as $migration => $table) {
            if (! Schema::hasTable($table)) {
                (require database_path('migrations/'.$migration))->up();
            }
        }
    }

    private function seedSignals(bool $declared, int $count = 3): void
    {
        for ($i = 0; $i < $count; $i++) {
            OperatorLearningSignal::query()->create([
                'operator_id' => 'op-test',
                'taxonomy_item_id' => 'OP-140',
                'signal_kind' => 'operator_boundary',
                'source_type' => 'chat_explicit_operator_signal',
                'normalized_claim' => 'sempre rode os testes antes de commitar',
                'privacy_class' => 'normal',
                'risk_level' => 'low',
                'confidence' => 0.92,
                'scope_type' => 'global',
                'metadata' => array_filter([
                    'runtime_capture' => 'ai_gateway.enqueue_interaction',
                    'operator_text_declared' => $declared ?: null,
                ]),
            ]);
        }
    }

    public function test_a_detection_persisted_without_a_proposal_does_not_block_later_runs(): void
    {
        $this->seedSignals(declared: true);
        $detector = app(OperatorPatternDetector::class);

        $first = $detector->detect('op-test');
        self::assertCount(1, $first, 'o primeiro run detecta a recorrência');

        // Exatamente o estado que travava tudo: a linha existe, ninguém propôs.
        self::assertSame(OperatorPatternDetection::STATUS_DETECTED, $first[0]->status);

        $second = $detector->detect('op-test');
        self::assertCount(1, $second, 'a linha gravada continua elegível — status manda, não idade');
        self::assertSame($first[0]->id, $second[0]->id);

        // Já proposta, aí sim sai da fila — e não volta.
        $second[0]->forceFill(['status' => OperatorPatternDetection::STATUS_PROPOSED])->save();
        self::assertCount(0, $detector->detect('op-test'), 'proposta nunca é re-proposta');
    }

    public function test_text_the_surface_never_attributed_to_the_operator_is_not_mined(): void
    {
        $this->seedSignals(declared: false);

        self::assertCount(0, app(OperatorPatternDetector::class)->detect('op-test'));
    }
}
