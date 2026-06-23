<?php

namespace App\Console\Commands;

use App\Services\Ai\MarketingDomain\Content\AntiGoodhartGuard;
use App\Services\Ai\MarketingDomain\Content\ConversionAuditor;
use App\Services\Ai\MarketingDomain\Content\LearnedWeightLedger;
use Illuminate\Console\Command;

/**
 * atlas:ai:marketing:import-outcomes — batch import of outcomes from a CSV/JSON file. The operator
 * exports from ClickBank / Voluum / RedTrack (or pastes the daily snapshot), points this command at
 * the file, and the ledger gets fed in one pass. Covers the manual-CLI gap without needing the
 * Atlas to be HTTP-exposed for live webhooks. Each row needs: page_html_path, niche, cvr (+ optional
 * page_kind, clicks, conversions, rpv, asset_id).
 */
class AtlasAiMarketingImportOutcomesCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:import-outcomes
        {file : path to a .csv or .json with the outcomes (1 row per page-snapshot)}
        {--dry-run : parse and audit but do not write to the ledger}';

    protected $description = 'Importa em lote outcomes de campanhas (CSV/JSON exportado do dashboard) para o ledger.';

    public function handle(ConversionAuditor $auditor, AntiGoodhartGuard $guard, LearnedWeightLedger $ledger): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $rows = $this->parse($file);
        if ($rows === []) {
            $this->error('No valid rows parsed.');

            return self::FAILURE;
        }

        $written = 0;
        $skipped = 0;
        $errors = [];
        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        foreach ($rows as $i => $r) {
            $bar->advance();
            $pagePath = (string) ($r['page_html_path'] ?? '');
            $niche = (string) ($r['niche'] ?? '');
            $cvr = $r['cvr'] ?? null;

            if (! is_file($pagePath) || $niche === '' || $cvr === null) {
                $skipped++;
                $errors[] = 'row '.$i.': missing page_html_path/niche/cvr';

                continue;
            }
            $html = (string) file_get_contents($pagePath);
            $copy = \App\Services\Ai\MarketingDomain\Content\HtmlCopyExtractor::plainText($html);

            $audit = $auditor->audit($copy, $html);
            $hollow = $guard->inspect($copy);

            if ($this->option('dry-run')) {
                $written++;

                continue;
            }

            $ledger->record($audit, $hollow['hollowness'], [
                'niche' => $niche,
                'page_kind' => (string) ($r['page_kind'] ?? 'bridge'),
                'conversion_rate' => (float) $cvr,
                'clicks' => isset($r['clicks']) ? (int) $r['clicks'] : null,
                'conversions' => isset($r['conversions']) ? (int) $r['conversions'] : null,
                'revenue_per_visitor' => isset($r['rpv']) ? (float) $r['rpv'] : null,
                'vsl_asset_id' => $r['asset_id'] ?? null,
            ]);
            $written++;
        }

        $bar->finish();
        $this->newLine(2);

        $this->line('  <fg=green>'.$written.'</> registrado(s)'
            .($this->option('dry-run') ? ' (dry-run)' : '')
            .', <fg=yellow>'.$skipped.'</> pulado(s).');
        foreach (array_slice($errors, 0, 5) as $e) {
            $this->line('  <fg=red>·</> '.$e);
        }
        if (count($errors) > 5) {
            $this->line('  <fg=gray>(… +'.(count($errors) - 5).' erros)</>');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parse(string $file): array
    {
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'json') {
            $data = json_decode((string) file_get_contents($file), true);

            return is_array($data) ? array_values($data) : [];
        }
        if ($ext === 'csv') {
            $h = fopen($file, 'r');
            if (! $h) {
                return [];
            }
            $headers = fgetcsv($h);
            $rows = [];
            while (($line = fgetcsv($h)) !== false) {
                if ($headers !== false && count($line) === count($headers)) {
                    $rows[] = array_combine($headers, $line);
                }
            }
            fclose($h);

            return $rows;
        }

        return [];
    }
}
