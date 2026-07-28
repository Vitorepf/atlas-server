<?php

declare(strict_types=1);

namespace App\Services\Ai\Signal;

use Illuminate\Support\Facades\DB;

/**
 * Quanto falta para dois identificadores colidirem no corte do Postgres.
 *
 * O limite de 63 caracteres não avisa: ele corta e segue. Uma tabela já foi
 * criada assim e passou meses invisível. Índices cortados são mais brandos — o
 * Postgres criou e usa —, mas dois nomes DECLARADOS que cortem na mesma string
 * de 63 caracteres colidem no CREATE, e aí a migration falha de vez.
 *
 * Medido em 28/07/2026: 145 índices e 22 constraints cortados, ZERO colisões, e
 * o par mais próximo compartilha 59 caracteres — **4 de folga**. Uma tabela com
 * nome quatro caracteres maior, ou uma coluna a mais no índice, e a folga acaba.
 *
 * Só mede. Não renomeia nada: renomear 167 índices é obra, e a maioria é
 * inofensiva.
 */
final class AtlasIdentifierLimitAudit
{
    public const SCHEMA = 'atlas.signal.identifier_limit_audit.v1';

    public const PG_LIMIT = 63;

    /**
     * Veredito puro sobre uma lista de nomes DECLARADOS.
     *
     * @param  list<string>  $declaredNames
     * @return array{truncated:list<string>, collisions:list<array{prefix:string,names:list<string>}>, closest_pair:list<string>, shared_prefix_len:int, margin:int}
     */
    public function audit(array $declaredNames): array
    {
        $names = array_values(array_unique(array_filter(array_map('strval', $declaredNames), static fn (string $n): bool => $n !== '')));
        sort($names);

        $truncated = array_values(array_filter($names, static fn (string $n): bool => strlen($n) > self::PG_LIMIT));

        // Colisão real: dois nomes DISTINTOS que cortam na mesma string. Só pode
        // acontecer entre nomes longos — cortar um nome curto devolve ele mesmo.
        $byPrefix = [];
        foreach ($truncated as $name) {
            $byPrefix[substr($name, 0, self::PG_LIMIT)][] = $name;
        }
        $collisions = [];
        foreach ($byPrefix as $prefix => $group) {
            $group = array_values(array_unique($group));
            if (count($group) > 1) {
                $collisions[] = ['prefix' => $prefix, 'names' => $group];
            }
        }

        // A folga: o prefixo comum mais longo entre dois nomes vizinhos. Ordenados,
        // o par mais parecido é sempre adjacente — não precisa comparar todos com
        // todos, e o número não muda por isso.
        $sharedLen = 0;
        $closest = [];
        for ($i = 1; $i < count($names); $i++) {
            $a = $names[$i - 1];
            $b = $names[$i];
            $n = 0;
            $min = min(strlen($a), strlen($b));
            while ($n < $min && $a[$n] === $b[$n]) {
                $n++;
            }
            if ($n > $sharedLen) {
                $sharedLen = $n;
                $closest = [$a, $b];
            }
        }

        return [
            'truncated' => $truncated,
            'collisions' => $collisions,
            'closest_pair' => $closest,
            'shared_prefix_len' => $sharedLen,
            'margin' => max(0, self::PG_LIMIT - $sharedLen),
        ];
    }

    /**
     * Os nomes DECLARADOS reconstruídos do próprio Postgres: tabela + colunas +
     * sufixo, do jeito que o Laravel gera quando a migration não nomeia o índice.
     * Um índice nomeado à mão na migration não é reconstruível — e aparece como
     * tal, em vez de virar um palpite.
     *
     * @return array{declared:list<string>, reconstructed:int, explicit_name:int, truncated_on_disk:int, available:bool}
     */
    public function fromDatabase(): array
    {
        // O catálogo é do Postgres, e só ele responde isto. Noutro driver a
        // resposta honesta é "não sei", nunca uma lista vazia disfarçada de
        // "está tudo certo" — que é exatamente como o corte silencioso passou.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return ['declared' => [], 'reconstructed' => 0, 'explicit_name' => 0, 'truncated_on_disk' => 0, 'available' => false];
        }

        $rows = DB::select("
            select i.relname as real_name, t.relname as table_name,
                   ix.indisunique as uniq, ix.indisprimary as pri,
                   array_to_string(array_agg(a.attname order by k.ord), '_') as cols
            from pg_index ix
            join pg_class i on i.oid = ix.indexrelid
            join pg_class t on t.oid = ix.indrelid
            join pg_namespace n on n.oid = t.relnamespace
            join lateral unnest(ix.indkey) with ordinality as k(attnum, ord) on true
            join pg_attribute a on a.attrelid = t.oid and a.attnum = k.attnum
            where n.nspname = 'public'
            group by 1,2,3,4
        ");

        $declared = [];
        $reconstructed = 0;
        $explicit = 0;
        $truncatedOnDisk = 0;

        foreach ($rows as $row) {
            $real = (string) $row->real_name;
            if (strlen($real) >= self::PG_LIMIT) {
                $truncatedOnDisk++;
            }
            $suffix = $row->pri ? 'primary' : ($row->uniq ? 'unique' : 'index');
            $candidate = $row->table_name.'_'.$row->cols.'_'.$suffix;

            if (str_starts_with($candidate, $real)) {
                $declared[] = $candidate;
                $reconstructed++;

                continue;
            }
            // Nome explícito na migration: o que está no disco É o declarado
            // (ou o corte dele, e aí não há como saber o original daqui).
            $declared[] = $real;
            $explicit++;
        }

        return [
            'declared' => $declared,
            'reconstructed' => $reconstructed,
            'explicit_name' => $explicit,
            'truncated_on_disk' => $truncatedOnDisk,
            'available' => true,
        ];
    }
}
