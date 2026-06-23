<?php

namespace App\Console\Commands;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\AggressionAmplifier;
use Illuminate\Console\Command;

/**
 * atlas:ai:marketing:amplify — runs the closed-loop AggressionAmplifier on a bridge JSON for a given
 * VSL asset and writes back the amplified bridge plus a before/after report. This is the human
 * interface of the verifier-driven loop: measure → inject what's missing → re-measure.
 */
class AtlasAiMarketingAmplifyCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:amplify
        {bridge : path to bridge JSON file}
        {--asset= : VSL asset id (defaults to latest)}
        {--until=killer : target grade (flat|weak|decent|strong|killer)}
        {--iterations=3 : max amplifier iterations}
        {--out= : path to write the amplified bridge JSON (defaults to <bridge>.amplified.json)}';

    protected $description = 'Amplifica uma bridge (mede → injeta o que falta → re-mede) até atingir o grade alvo.';

    public function handle(AggressionAmplifier $amplifier): int
    {
        $path = (string) $this->argument('bridge');
        if (! is_file($path)) {
            $this->error("Bridge JSON not found: {$path}");

            return self::FAILURE;
        }
        $bridge = json_decode((string) file_get_contents($path), true);
        if (! is_array($bridge)) {
            $this->error('Invalid bridge JSON.');

            return self::FAILURE;
        }

        $asset = $this->option('asset')
            ? AiMarketingVslAsset::find($this->option('asset'))
            : AiMarketingVslAsset::orderByDesc('updated_at')->first();
        if (! $asset) {
            $this->error('No VSL asset found.');

            return self::FAILURE;
        }

        $out = $amplifier->amplify($bridge, $asset, [
            'until' => (string) $this->option('until'),
            'max_iterations' => (int) $this->option('iterations'),
        ]);

        $outPath = (string) ($this->option('out') ?: preg_replace('/\.json$/', '.amplified.json', $path));
        file_put_contents($outPath, json_encode($out['bridge'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->line('');
        $this->line('  <fg=white;options=bold>AMPLIFIER — relatório</>');
        $this->line('  Asset: '.$asset->title.'  (#'.$asset->id.')');
        $this->line('  Iterações: '.$out['iterations'].'  |  Injetados: '.count($out['injected']));
        $this->line('');
        $this->line('  Overall: <fg=red>'.$out['before']['overall_score'].'</> ('.$out['before']['grade'].')  →  '
            .'<fg=green;options=bold>'.$out['after']['overall_score'].'</> ('.$out['after']['grade'].')'
            .'  <fg=yellow>+'.($out['after']['overall_score'] - $out['before']['overall_score']).'</>');
        $this->line('');
        $this->line('  <fg=gray>Por dimensão:</>');
        foreach ($out['before']['by_library'] as $k => $b) {
            $a = $out['after']['by_library'][$k]['score'];
            $delta = $a - $b['score'];
            $arrow = $delta > 0 ? '<fg=green>+'.$delta.'</>' : ($delta < 0 ? '<fg=red>'.$delta.'</>' : '<fg=gray>0</>');
            $this->line(sprintf('    %-26s %3d → %3d  %s', $k, $b['score'], $a, $arrow));
        }
        if (! empty($out['injected'])) {
            $this->line('');
            $this->line('  <fg=cyan>Padrões injetados:</> '.implode(', ', $out['injected']));
        }
        $this->line('');
        $this->line('  Bridge amplificada: <fg=white>'.$outPath.'</>');
        $this->line('');

        return self::SUCCESS;
    }
}
