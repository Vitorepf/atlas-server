<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityGapTaskChainCompiler;
use App\Services\Ai\Signal\AtlasSignalInvariantGapAdapter;
use App\Services\Ai\Signal\AtlasSignalInvariantScanner;
use Illuminate\Console\Command;

/**
 * As invariantes de sinal, com veredito por classe — e o motivo escrito nas que
 * não dão para derivar.
 *
 * `--as-gaps` entrega os achados ao caminho `capability-gap` do cérebro. Só
 * COMPILA: não semeia, não enfileira, não dispara `atlas:brain:*`.
 */
class AtlasSignalInvariantsCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:signal:invariants
        {--json : saída canônica}
        {--as-gaps : compila os achados em cadeia de task}
        {--strict : exit 1 se qualquer classe derivável tiver achado}';

    protected $description = 'Invariantes de sinal: tabela sem dono, cadência órfã, campo sem produtor, produtor sem relógio.';

    public function handle(AtlasSignalInvariantScanner $scanner): int
    {
        $report = $scanner->scan();

        if ((bool) $this->option('as-gaps')) {
            $adapted = app(AtlasSignalInvariantGapAdapter::class)->toGaps($report['findings']);
            $compiled = app(AtlasExternalBrainCapabilityGapTaskChainCompiler::class)->compile($adapted);
            $report['gap_chain'] = [
                'findings_in' => $report['findings_total'],
                'chain_nodes' => count($compiled['chain']),
                'chain_value_score' => $compiled['chain_value_score'],
                'skipped' => $adapted['skipped'],
                'chain' => $compiled['chain'],
            ];
        }

        if ((bool) $this->option('json')) {
            $this->jsonLine($report);
        } else {
            foreach ($report['counts'] as $class => $count) {
                $derivable = $report['derivable'][$class];
                $this->line(sprintf(
                    '  %-32s %5s  %s',
                    $class,
                    $derivable ? (string) $count : '—',
                    $derivable ? '' : 'NÃO DERIVÁVEL: '.($report['reasons'][$class] ?? ''),
                ));
            }
            $this->info(sprintf('%d achado(s) no total.', $report['findings_total']));
            if (isset($report['gap_chain'])) {
                $this->info(sprintf(
                    '→ %d nó(s) de task · chain_value_score=%s',
                    $report['gap_chain']['chain_nodes'],
                    $report['gap_chain']['chain_value_score'],
                ));
            }
        }

        // O exit code fala só do que foi MEDIDO. Uma classe não derivável não
        // pode quebrar o build: seria transformar "não sei" em "está errado".
        if ((bool) $this->option('strict') && $report['findings_total'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
