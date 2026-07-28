<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Learning;

use App\Models\OperatorLearningSignal;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOperatorIntelligenceTables;
use Tests\Concerns\CreatesStudyVocabularyTable;
use Tests\TestCase;

/**
 * A rede do placar.
 *
 * Um painel de estudo e o lugar mais facil de mentir sem querer: basta somar recall com
 * transferencia para o numero ficar maior, ou esconder o registro corrompido para a
 * cobertura parecer melhor. Os dois seriam invisiveis para quem olha so o total.
 *
 * Por isso os testes centrais aqui sao sobre o que o placar NAO faz.
 */
final class AtlasStudyStatusCommandTest extends TestCase
{
    use CreatesOperatorIntelligenceTables;
    use CreatesStudyVocabularyTable;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_05_07_140000_create_worked_examples_table.php'))->up();
        (require database_path('migrations/2026_05_07_120000_create_dreyfus_overlays_table.php'))->up();
        $this->createOperatorIntelligenceTables();
        $this->createStudyVocabularyTable();
    }

    protected function tearDown(): void
    {
        $this->dropStudyVocabularyTable();
        $this->dropOperatorIntelligenceTables();
        Schema::dropIfExists('dreyfus_overlays');
        Schema::dropIfExists('worked_examples');
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function painel(): array
    {
        Artisan::call('atlas:study:status', ['--domain' => 'poker', '--json' => true]);

        return (array) json_decode(trim(Artisan::output()), true);
    }

    private function sinal(string $claim, array $meta = []): void
    {
        OperatorLearningSignal::query()->create([
            'id' => (string) Str::uuid(),
            'operator_id' => 'vitor',
            'taxonomy_item_id' => 'OP-071',
            'signal_kind' => 'operator_decision',
            'source_type' => 'operator_declared',
            'normalized_claim' => $claim,
            'evidence_refs' => ['spot:x', 'decision:y'],
            'privacy_class' => 'normal',
            'risk_level' => 'low',
            'confidence' => 0.9,
            'scope_type' => 'domain',
            'scope_id' => 'poker',
            'metadata' => array_merge(['operator_text_declared' => true, 'confidence_kind' => 'principled'], $meta),
        ]);
    }

    private function overlay(int $nivel, array $refs): void
    {
        DB::table('dreyfus_overlays')->insert([
            'knowledge_node_id' => (string) Str::uuid(),
            'domain' => 'poker',
            'current_level' => $nivel,
            'confidence' => 0.6,
            'evidence_refs' => json_encode($refs),
            'last_updated_via' => 'teste',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_taxa_de_dominio_conta_so_transferencia_e_nunca_recall(): void
    {
        $this->overlay(3, [
            'transfer:correct:worked_example:1',
            'recall:correct:worked_example:1',
            'recall:correct:worked_example:1',
            'recall:correct:worked_example:1',
            'transfer:wrong:worked_example:1',
        ]);

        $placar = $this->painel()['placar_de_aprendizado'];

        $this->assertSame(1, $placar['transferencias']);
        $this->assertSame(3, $placar['recall_acertos']);
        $this->assertSame(1, $placar['erros']);
        // 1 transferencia / (1 + 1 erro) = 0.5. Somando os 3 recall daria 0.80 — numero
        // maior, mais bonito, e a mesma mentira que o laco de revisao existe para impedir.
        $this->assertSame(0.5, $placar['taxa_de_dominio']);
    }

    public function test_registro_perdido_por_redacao_aparece_mesmo_sendo_um_so(): void
    {
        $this->sinal('[redacted:sensitive:20518c4a8069]', ['redacted_at_rest' => true]);
        $this->sinal('principio intacto');

        $d = $this->painel()['decisoes_registradas'];

        // Esconder o corrompido faria a cobertura parecer melhor do que e. O operador
        // precisa saber que registrou e perdeu — e uma lacuna diferente de nao registrar.
        $this->assertSame(2, $d['total']);
        $this->assertSame(1, $d['perdidos_por_redacao']);
    }

    public function test_o_proximo_passo_nao_manda_rodar_comando_que_produz_zero(): void
    {
        $this->sinal('[redacted:sensitive:abc123]', ['redacted_at_rest' => true]);

        $saida = $this->painel();

        // Ha 1 decisao DECLARADA, entao o conselho ingenuo seria "rode study:example".
        // Ele produziria zero, porque a unica linha esta redigida. Conselho que nao
        // confere com o dado e o mesmo defeito de um numero que nao mede nada.
        $this->assertStringNotContainsString('atlas:study:example', $saida['proximo_passo']);
        $this->assertStringContainsString('redigidas', $saida['proximo_passo']);
    }

    public function test_sem_transferencia_o_painel_nomeia_o_gargalo_de_verdade(): void
    {
        $this->sinal('principio intacto');
        Artisan::call('atlas:study:example', ['--domain' => 'poker', '--json' => true]);
        Artisan::call('atlas:study:review', ['--domain' => 'poker', '--json' => true]);

        $saida = $this->painel();

        // O gargalo real com um conceito so nao e "estude mais", e "registre um SEGUNDO
        // spot no mesmo termo" — sem dois, o sistema so consegue testar recall.
        $this->assertStringContainsString('SEGUNDA decisao no mesmo conceito', $saida['proximo_passo']);
    }

    public function test_taxa_e_nula_quando_nao_ha_tentativa_em_vez_de_zero(): void
    {
        $this->sinal('principio intacto');

        // Zero seria uma AFIRMACAO ("voce domina 0%"); nulo e a verdade ("ninguem mediu
        // ainda"). Ausencia de dado nao pode virar nota baixa.
        $this->assertNull($this->painel()['placar_de_aprendizado']['taxa_de_dominio']);
    }
}
