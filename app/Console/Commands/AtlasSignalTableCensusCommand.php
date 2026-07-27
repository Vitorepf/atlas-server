<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\Signal\AtlasTableCensusService;
use Illuminate\Console\Command;

/**
 * Série da tese "construído e não provado": quantas tabelas existem, quantas
 * nunca receberam uma linha, e quantas pararam de receber.
 *
 * Read-only.
 */
class AtlasSignalTableCensusCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:signal:table-census
        {--stale-days=14 : dias sem escrita para uma tabela contar como parada}
        {--json : saída canônica (pretty) para leitura}
        {--jsonl : UMA linha por execução, para append em série}';

    protected $description = 'Censo de tabelas: total, vazias, não-vazias e sem escrita desde N dias (só mede).';

    public function handle(AtlasTableCensusService $census): int
    {
        $report = $census->census((int) $this->option('stale-days'));

        // O `--json` do resto dos comandos atlas:* é pretty por contrato compartilhado
        // (EmitsCanonicalJson), e pretty não empilha: um append diário viraria um
        // arquivo que nenhum leitor de série consegue abrir. Daí o modo de uma linha.
        if ((bool) $this->option('jsonl')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ((bool) $this->option('json')) {
            $this->jsonLine($report);

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d tabelas · %d vazias (%.1f%%) · %d com linhas · %d sem escrita há %d+ dias · %d sem recência conhecida',
            $report['total_tables'],
            $report['empty_count'],
            $report['empty_ratio'] * 100,
            $report['non_empty_count'],
            $report['stale_count'],
            $report['stale_days'],
            $report['unknown_recency_count'],
        ));

        foreach (array_slice($report['stale_tables'], 0, 15) as $row) {
            $this->line(sprintf('  %-56s %7d linhas  última=%s', $row['table'], $row['rows'], $row['last_write']));
        }

        return self::SUCCESS;
    }
}
