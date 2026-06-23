<?php

namespace App\Console\Commands;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\QualifiedKeywordPatternEngine;
use Illuminate\Console\Command;

/**
 * atlas:ai:marketing:keywords — runs the Search Keyword OS over a VSL asset: harvests the owned-root
 * artifacts (mechanism/trick/slogan/celebrity/power-phrase/category) and emits the qualified keyword
 * tiers (by exposure-exclusivity) + negatives, applying the memory-recall-arbitrage law. The product
 * name is never bid; the highest-converting traffic is post-VSL-exposure re-finders.
 */
class AtlasAiMarketingKeywordsCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:keywords
        {--vsl= : VSL asset id (defaults to latest)}
        {--json : machine output}';

    protected $description = 'Gera as keywords QUALIFICADAS de rede de pesquisa (memory-recall arbitrage) a partir de um asset de VSL.';

    public function handle(QualifiedKeywordPatternEngine $engine): int
    {
        $asset = $this->option('vsl')
            ? AiMarketingVslAsset::find($this->option('vsl'))
            : AiMarketingVslAsset::orderByDesc('updated_at')->first();
        if (! $asset) {
            $this->error('No VSL asset found.');

            return self::FAILURE;
        }

        $r = $engine->build($asset);

        if ($this->option('json')) {
            $this->line((string) json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $a = $r['artifacts'];
        $this->line('');
        $this->line('  <fg=white;options=bold>SEARCH KEYWORD OS — keywords qualificadas</>');
        $this->line('  <fg=gray>asset:</> '.($asset->title ?: $asset->id));
        $this->line('  <fg=gray>raízes próprias:</> mech="'.$a['mechanism'].'" · trick="'.$a['trick'].'" · slogan="'.$a['slogan'].'"');
        $this->line('  <fg=gray>celebridades:</> '.implode(', ', $a['celebrities']).'   <fg=red>proibido (produto):</> '.$a['product_name']);
        $this->line('');

        $qualColor = ['hyper' => 'green', 'high' => 'cyan', 'medium' => 'yellow'];
        foreach ($r['tiers'] as $t) {
            $c = $qualColor[$t['qualification']] ?? 'gray';
            $this->line('  <fg='.$c.';options=bold>'.strtoupper($t['family']).'</> <fg='.$c.'>['.$t['qualification'].' · '.$t['match_type'].']</> '
                .'<fg=gray>('.count($t['keywords']).' kw)</>');
            foreach (array_slice($t['keywords'], 0, 10) as $kw) {
                $this->line('    <fg=white>•</> '.$kw);
            }
            if (count($t['keywords']) > 10) {
                $this->line('    <fg=gray>… +'.(count($t['keywords']) - 10).'</>');
            }
            $this->line('      <fg=gray>'.$t['why'].'</>');
            $this->line('');
        }

        $this->line('  <fg=red;options=bold>NEGATIVAS (queima dinheiro):</> '.implode(', ', array_slice($r['negatives'], 0, 24)));
        $this->line('  <fg=gray>total qualificadas:</> <fg=green;options=bold>'.count($r['flat']).'</> keywords');
        $this->line('');

        return self::SUCCESS;
    }
}
