<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\Signal\AtlasTableCensusService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A tese central do plano — "construído e não provado" — vira série aqui.
 * Uma métrica dessas erra em uma direção perigosa: contar como fresca a tabela
 * cuja recência ela não sabe ler. Ausência de dado nunca é verde.
 */
final class AtlasTableCensusServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('census_vazia', function (Blueprint $t): void {
            $t->id();
            $t->timestamps();
        });
        Schema::create('census_fresca', function (Blueprint $t): void {
            $t->id();
            $t->timestamps();
        });
        Schema::create('census_parada', function (Blueprint $t): void {
            $t->id();
            $t->timestamps();
        });
        // Sem created_at: recência não é conhecível, e isso é um estado próprio.
        Schema::create('census_sem_relogio', function (Blueprint $t): void {
            $t->id();
        });
        // created_at como epoch inteiro (a forma que a tabela `jobs` usa): um
        // parse frouxo leria 1784258584 como ANO e chamaria a tabela de fresca.
        Schema::create('census_epoch', function (Blueprint $t): void {
            $t->id();
            $t->integer('created_at');
        });

        DB::table('census_fresca')->insert(['created_at' => now()->subDay(), 'updated_at' => now()]);
        DB::table('census_parada')->insert(['created_at' => now()->subDays(90), 'updated_at' => now()]);
        DB::table('census_sem_relogio')->insert(['id' => 1]);
        DB::table('census_epoch')->insert(['created_at' => now()->subDays(90)->getTimestamp()]);
    }

    private function census(int $staleDays = 14): array
    {
        return app(AtlasTableCensusService::class)->census($staleDays);
    }

    public function test_it_separates_empty_from_written_tables(): void
    {
        $report = $this->census();

        self::assertContains('census_vazia', $report['empty_tables']);
        self::assertNotContains('census_fresca', $report['empty_tables']);
        self::assertNotContains('census_parada', $report['empty_tables']);
        self::assertSame(
            $report['total_tables'],
            $report['empty_count'] + $report['non_empty_count'],
            'o total tem de fechar: toda tabela cai em exatamente um balde'
        );
        self::assertSame(
            round($report['empty_count'] / $report['total_tables'], 4),
            $report['empty_ratio']
        );
    }

    public function test_a_table_that_stopped_being_written_is_reported_with_its_last_write(): void
    {
        $stale = array_column($this->census()['stale_tables'], 'table');

        self::assertContains('census_parada', $stale);
        self::assertNotContains('census_fresca', $stale, 'escrita de ontem não é parada');
        self::assertNotContains('census_vazia', $stale, 'vazia não é "parada": nunca começou');
    }

    public function test_unknown_recency_is_its_own_bucket_and_never_counts_as_fresh(): void
    {
        $report = $this->census();

        self::assertContains('census_sem_relogio', $report['unknown_recency_tables']);
        self::assertNotContains('census_sem_relogio', array_column($report['stale_tables'], 'table'));
    }

    public function test_an_epoch_timestamp_is_read_as_a_date_not_as_a_year(): void
    {
        // Sem isto, 1784258584 vira "ano 1784258584" — futuro distante — e a
        // tabela mais parada do banco aparece como a mais fresca.
        self::assertContains('census_epoch', array_column($this->census()['stale_tables'], 'table'));
    }

    public function test_the_window_is_the_operator_question_not_a_constant(): void
    {
        $stale365 = array_column($this->census(365)['stale_tables'], 'table');

        self::assertNotContains('census_parada', $stale365, '90 dias não é parada numa janela de 365');
    }
}
