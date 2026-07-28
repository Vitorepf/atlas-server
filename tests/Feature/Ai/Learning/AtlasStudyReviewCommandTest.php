<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Learning;

use App\Models\OperatorLearningSignal;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesOperatorIntelligenceTables;
use Tests\TestCase;

/**
 * A rede da unica medicao que separa aprendizado de repeticao.
 *
 * Um sistema de estudo que promove por acerto e facil de enganar: bastaria reler a propria
 * anotacao para "evoluir", e o numero subiria enquanto o operador continuasse sem saber
 * jogar. Por isso o teste central aqui nao e "acertou logo promoveu" — e o oposto:
 * ACERTAR EM RECALL NAO PODE PROMOVER. Um teste que so verificasse a promocao passaria
 * verde sobre exatamente o defeito que este comando existe para impedir.
 */
final class AtlasStudyReviewCommandTest extends TestCase
{
    use CreatesOperatorIntelligenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_05_07_140000_create_worked_examples_table.php'))->up();
        (require database_path('migrations/2026_05_07_120000_create_dreyfus_overlays_table.php'))->up();
        $this->createOperatorIntelligenceTables();
    }

    protected function tearDown(): void
    {
        $this->dropOperatorIntelligenceTables();
        Schema::dropIfExists('dreyfus_overlays');
        Schema::dropIfExists('worked_examples');
        parent::tearDown();
    }

    private function sinal(string $spot, string $principio, string $termo = 'C-bet'): string
    {
        $id = (string) Str::uuid();
        OperatorLearningSignal::query()->create([
            'id' => $id,
            'operator_id' => 'vitor',
            'taxonomy_item_id' => 'OP-071',
            'signal_kind' => 'operator_decision',
            'source_type' => 'operator_declared',
            'normalized_claim' => $principio,
            'evidence_refs' => ['spot:'.$spot, 'decision:aposta 33%'],
            'privacy_class' => 'normal',
            'risk_level' => 'low',
            'confidence' => 0.9,
            'scope_type' => 'domain',
            'scope_id' => 'poker',
            'metadata' => [
                'operator_text_declared' => true,
                'confidence_kind' => 'principled',
                'vocabulary_term' => $termo,
            ],
        ]);

        return $id;
    }

    /** @return array<string,mixed> */
    private function chamar(array $opcoes): array
    {
        Artisan::call('atlas:study:review', array_merge(['--json' => true], $opcoes));

        return (array) json_decode(trim(Artisan::output()), true);
    }

    private function gerarCartas(): void
    {
        Artisan::call('atlas:study:example', ['--domain' => 'poker', '--json' => true]);
    }

    public function test_com_um_sinal_so_a_entrega_e_recall_e_avisa_que_nao_promove(): void
    {
        $this->sinal('abri no CO, flop A72 rainbow', 'board seco favorece minha range');
        $this->gerarCartas();

        $saida = $this->chamar(['--domain' => 'poker']);

        // A ausencia de material de transferencia e DECLARADA, nao disfarcada de progresso.
        $this->assertSame('recall', $saida['modo']);
        $this->assertNotNull($saida['aviso']);
    }

    public function test_dois_sinais_no_mesmo_termo_produzem_transferencia_com_spot_do_outro(): void
    {
        $this->sinal('abri no CO, flop A72 rainbow', 'board seco favorece minha range');
        $this->gerarCartas();
        $this->sinal('abri no BTN, flop K83 rainbow', 'mesmo principio, outro board');

        $saida = $this->chamar(['--domain' => 'poker', '--force' => true]);

        // O spot novo sai do PROPRIO registro do operador — nada e inventado.
        $this->assertSame('transfer', $saida['modo']);
        $this->assertSame('abri no BTN, flop K83 rainbow', $saida['spot']);
    }

    public function test_o_principio_nao_aparece_na_carta_entregue_a_partir_do_estagio_3(): void
    {
        $this->sinal('spot qualquer', 'ESTE E O PRINCIPIO QUE ELE TEM DE PRODUZIR');
        $this->gerarCartas();

        $carta = DB::table('worked_examples')->firstOrFail();
        DB::table('dreyfus_overlays')->insert([
            'knowledge_node_id' => $carta->knowledge_node_id,
            'domain' => 'poker',
            'current_level' => 3,
            'confidence' => 0.6,
            'evidence_refs' => json_encode([]),
            'last_updated_via' => 'teste',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $saida = $this->chamar(['--domain' => 'poker', '--force' => true]);

        $visivel = json_encode($saida['passos_visiveis'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('ESTE E O PRINCIPIO', (string) $visivel, 'no estagio 3 o principio ja saiu da carta');
        $this->assertContains(3, $saida['passos_escondidos']);
    }

    public function test_acertar_em_recall_NAO_promove(): void
    {
        $this->sinal('spot unico', 'principio qualquer');
        $this->gerarCartas();
        $id = (int) DB::table('worked_examples')->value('id');

        $saida = $this->chamar(['--grade' => (string) $id, '--mode' => 'recall', '--correct' => true]);

        // A regra inteira do comando esta nesta assercao. Reconhecer a propria anotacao e
        // reconhecimento, nao conhecimento — promover aqui tornaria o placar falsificavel
        // por repeticao.
        $this->assertSame(1, $saida['estagio_antes']);
        $this->assertSame(1, $saida['estagio_depois']);
        $this->assertFalse($saida['promoveu']);
    }

    public function test_acertar_em_transferencia_promove(): void
    {
        $this->sinal('spot A', 'principio qualquer');
        $this->gerarCartas();
        $id = (int) DB::table('worked_examples')->value('id');

        $saida = $this->chamar(['--grade' => (string) $id, '--mode' => 'transfer', '--correct' => true]);

        $this->assertSame(2, $saida['estagio_depois']);
        $this->assertTrue($saida['promoveu']);
        $this->assertNotNull($saida['proxima_revisao'], 'a repeticao espacada tem de ganhar relogio no primeiro julgamento');
    }

    public function test_errar_desce_o_estagio_e_devolve_a_ajuda(): void
    {
        $this->sinal('spot A', 'principio qualquer');
        $this->gerarCartas();
        $id = (int) DB::table('worked_examples')->value('id');

        $this->chamar(['--grade' => (string) $id, '--mode' => 'transfer', '--correct' => true]);
        $this->chamar(['--grade' => (string) $id, '--mode' => 'transfer', '--correct' => true]);
        $saida = $this->chamar(['--grade' => (string) $id, '--mode' => 'transfer', '--wrong' => true]);

        $this->assertSame(3, $saida['estagio_antes']);
        $this->assertSame(2, $saida['estagio_depois']);
    }

    public function test_julgar_sem_declarar_o_modo_e_recusado(): void
    {
        $this->sinal('spot A', 'principio qualquer');
        $this->gerarCartas();
        $id = (int) DB::table('worked_examples')->value('id');

        $saida = $this->chamar(['--grade' => (string) $id, '--correct' => true]);

        // Sem o modo, acertar a propria anotacao e acertar um spot novo viram o mesmo
        // numero — e o placar perde exatamente a informacao que o torna verdadeiro.
        $this->assertFalse($saida['ok']);
        $this->assertSame('mode_required', $saida['reason']);
    }
}
