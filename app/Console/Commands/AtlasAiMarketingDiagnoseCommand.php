<?php

namespace App\Console\Commands;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\BayesianKillScaleDecider;
use App\Services\Ai\MarketingDomain\Content\AbandonPointSimulator;
use App\Services\Ai\MarketingDomain\Content\AudiencePanelVerdict;
use App\Services\Ai\MarketingDomain\Content\ConversionLeverageDiagnostic;
use App\Services\Ai\MarketingDomain\Content\HtmlCopyExtractor;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * atlas:ai:marketing:diagnose — the 1→25 read for a page, in one command. Surfaces the highest-leverage
 * BOTTLENECK (ConversionLeverageDiagnostic: mechanism/proof/offer/watch-through/friction/believability),
 * the audience verdict (how many personas lost + the shared fix), WHERE the tab closes
 * (AbandonPointSimulator), and — when campaign stats are passed — the few-shot KILL/SCALE decision. The
 * operator's window into the whole conversion brain; provider-free.
 */
class AtlasAiMarketingDiagnoseCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:marketing:diagnose
        {--page= : the landing/VSL page copy (file path or inline text)}
        {--niche= : niche (weight loss / finance / relationship …) for the persona panel}
        {--mechanism= : the named mechanism, if any}
        {--clicks= : campaign clicks (enables the kill/scale decision)}
        {--conversions= : campaign sales}
        {--spend= : campaign spend}
        {--payout= : affiliate payout per sale (for the economics)}
        {--json : machine output}';

    protected $description = 'Diagnóstico 1→25 de uma página: gargalo #1 + veredito de audiência + onde a aba fecha + kill/scale.';

    public function handle(
        ConversionLeverageDiagnostic $diagnostic,
        AudiencePanelVerdict $audience,
        AbandonPointSimulator $abandon,
        BayesianKillScaleDecider $decider,
    ): int {
        $raw = (string) ($this->option('page') ?? '');
        if ($raw === '') {
            $this->error('Passe --page (arquivo ou texto da página).');

            return self::FAILURE;
        }
        $copy = HtmlCopyExtractor::plainText(is_file($raw) ? (string) file_get_contents($raw) : $raw);
        $niche = (string) ($this->option('niche') ?? '');

        $asset = new AiMarketingVslAsset(['niche' => $niche, 'mechanism_name' => (string) ($this->option('mechanism') ?? '')]);
        $leverage = $diagnostic->diagnose($asset, $copy);
        $verdict = $audience->assess($copy, $niche);
        $abandonPoint = $abandon->simulate($copy, $niche);

        $decision = null;
        if ($this->option('clicks') !== null) {
            $decision = $decider->decide(
                ['clicks' => (int) $this->option('clicks'), 'conversions' => (int) $this->option('conversions'), 'spend' => (float) $this->option('spend')],
                ['payout' => (float) ($this->option('payout') ?? 100)],
            );
        }

        $out = ['leverage' => $leverage, 'audience' => $verdict, 'abandon' => $abandonPoint, 'decision' => $decision];

        if ($this->option('json')) {
            $this->line($this->encode($out));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=white;options=bold>DIAGNÓSTICO 1→25</>');
        $this->line('  '.($leverage['bottleneck'] === null ? '<fg=green>✓ Sem gargalo de alta-alavancagem.</>' : '<fg=red;options=bold>GARGALO #1: '.$leverage['bottleneck']['key'].'</> — '.$leverage['bottleneck']['fix']));
        $this->line('');
        $this->line('  <fg=gray>Levers:</>');
        foreach ($leverage['levers'] as $l) {
            $icon = $l['status'] === 'strong' ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line('    '.$icon.' '.str_pad($l['key'], 16).' <fg=gray>'.$l['finding'].'</>');
        }
        $this->line('');
        $this->line('  <fg=white;options=bold>AUDIÊNCIA:</> '.$verdict['summary']);
        $this->line('  <fg=white;options=bold>ABANDONO:</> '.$abandonPoint['summary']);
        if ($decision !== null) {
            $color = ['KILL' => 'red', 'SCALE' => 'green', 'KEEP' => 'yellow', 'HOLD' => 'gray'][$decision['decision']] ?? 'white';
            $this->line('  <fg=white;options=bold>KILL/SCALE:</> <fg='.$color.';options=bold>'.$decision['decision'].'</> — '.$decision['reason']);
        }
        $this->line('');

        return self::SUCCESS;
    }
}
