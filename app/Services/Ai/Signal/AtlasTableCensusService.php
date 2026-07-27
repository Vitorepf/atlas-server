<?php

declare(strict_types=1);

namespace App\Services\Ai\Signal;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Quantas tabelas o Atlas construiu e quantas ele nunca provou.
 *
 * A tese "construído e não provado" foi medida UMA vez, à mão: 286 de 463
 * tabelas vazias (62%). Um número medido uma vez é anedota — não dá para dizer
 * se está melhorando, e um órgão que passou a escrever é indistinguível de um
 * que sempre escreveu. Aqui vira série.
 *
 * Só mede. Não conserta, não apaga, não escreve em nada que audita.
 *
 * Vazia é fato; "sem escrita desde N dias" é uma pergunta que só faz sentido em
 * tabela com `created_at` — o resto entra como recência DESCONHECIDA, nunca
 * como fresca. Ausência de dado não vira verde (a raiz de 32 achados na
 * auditoria do atlas-native).
 */
final class AtlasTableCensusService
{
    public const SCHEMA = 'atlas.signal.table_census.v1';

    public const DEFAULT_STALE_DAYS = 14;

    /**
     * @return array<string,mixed>
     */
    public function census(int $staleDays = self::DEFAULT_STALE_DAYS): array
    {
        $staleDays = max(1, $staleDays);
        $cutoff = Carbon::now()->subDays($staleDays);

        $empty = [];
        $stale = [];
        $unknownRecency = [];
        $nonEmpty = 0;

        foreach ($this->tableNames() as $table) {
            // Sem try/catch: uma tabela listada e não-contável é um defeito do
            // banco, e o censo tem de gritar. Engolir a exceção encolheria em
            // silêncio o denominador da própria tese que ele mede — e um balde
            // de "ilegíveis" que nunca aconteceu é alegação que não se prova.
            $rows = (int) DB::table($table)->count();

            if ($rows === 0) {
                $empty[] = $table;

                continue;
            }
            $nonEmpty++;

            if (! $this->hasTimestamp($table)) {
                $unknownRecency[] = $table;

                continue;
            }

            $last = $this->lastWrite($table);
            if ($last === null) {
                $unknownRecency[] = $table;

                continue;
            }
            if ($last->lessThan($cutoff)) {
                $stale[] = ['table' => $table, 'rows' => $rows, 'last_write' => $last->toIso8601String()];
            }
        }

        sort($empty);
        sort($unknownRecency);
        usort($stale, static fn (array $a, array $b): int => strcmp((string) $a['last_write'], (string) $b['last_write']));

        $total = count($empty) + $nonEmpty;

        return [
            'schema_version' => self::SCHEMA,
            'generated_at' => Carbon::now()->toIso8601String(),
            'stale_days' => $staleDays,
            'stale_cutoff' => $cutoff->toIso8601String(),
            'total_tables' => $total,
            'empty_count' => count($empty),
            'non_empty_count' => $nonEmpty,
            'empty_ratio' => $total > 0 ? round(count($empty) / $total, 4) : 0.0,
            'stale_count' => count($stale),
            'unknown_recency_count' => count($unknownRecency),
            'empty_tables' => $empty,
            'stale_tables' => $stale,
            'unknown_recency_tables' => $unknownRecency,
        ];
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        $names = [];
        foreach (Schema::getTables() as $table) {
            $name = is_array($table) ? (string) ($table['name'] ?? '') : (string) $table;
            if ($name !== '') {
                $names[] = $name;
            }
        }
        sort($names);

        return array_values(array_unique($names));
    }

    private function hasTimestamp(string $table): bool
    {
        try {
            return Schema::hasColumn($table, 'created_at');
        } catch (Throwable) {
            return false;
        }
    }

    private function lastWrite(string $table): ?Carbon
    {
        try {
            $max = DB::table($table)->max('created_at');
        } catch (Throwable) {
            return null;
        }
        if ($max === null || $max === '') {
            return null;
        }

        try {
            // Colunas `created_at` inteiras existem (jobs guarda epoch): um parse
            // frouxo leria 1784258584 como ano, e a tabela apareceria como fresca.
            return is_numeric($max) ? Carbon::createFromTimestamp((int) $max) : Carbon::parse((string) $max);
        } catch (Throwable) {
            return null;
        }
    }
}
