<?php

namespace App\Console\Commands;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\MultivariateBattlePlan;
use Illuminate\Console\Command;

/**
 * atlas:ai:marketing:battle — generates an orthogonal split-test plan from a VSL asset. Operator
 * picks the axis arrays (or accepts defaults), and the CLI writes one HTML per variant + a JSON
 * matrix with scores/grades/axes. The recommended variant is highlighted. This is the discovery
 * surface: which AXIS moves the needle, not which page won by accident.
 */
class AtlasAiMarketingBattleCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:battle
        {--asset= : VSL asset id (defaults to latest)}
        {--angles= : comma-separated angle keys (default: hidden_cause,common_enemy,contrarian_truth)}
        {--hooks= : comma-separated hook keys (default: hook_callout_specific,hook_warning)}
        {--awarenesses= : comma-separated awareness keys (default: problem_aware,solution_aware)}
        {--niche= : niche key for niche-scoped learned weights}
        {--page-kind=bridge : bridge|vsl_page|rsa|email}
        {--until=strong : target grade}
        {--iterations=3 : max amplifier iterations}
        {--out= : output directory (defaults to storage/app/marketing/battle/<asset>)}';

    protected $description = 'Gera N variantes ortogonais pra split-test (matriz angle × hook × awareness).';

    public function handle(MultivariateBattlePlan $plan): int
    {
        $asset = $this->option('asset')
            ? AiMarketingVslAsset::find($this->option('asset'))
            : AiMarketingVslAsset::orderByDesc('updated_at')->first();
        if (! $asset) {
            $this->error('No VSL asset found.');

            return self::FAILURE;
        }

        $opts = [
            'angles' => $this->csv($this->option('angles'), ['hidden_cause', 'common_enemy', 'contrarian_truth']),
            'hooks' => $this->csv($this->option('hooks'), ['hook_callout_specific', 'hook_warning']),
            'awarenesses' => $this->csv($this->option('awarenesses'), ['problem_aware', 'solution_aware']),
            'until' => (string) $this->option('until'),
            'max_iterations' => (int) $this->option('iterations'),
            'niche' => (string) ($this->option('niche') ?: ''),
            'page_kind' => (string) ($this->option('page-kind') ?: 'bridge'),
        ];

        $out = $plan->plan($asset, $opts);

        $dir = (string) ($this->option('out') ?: storage_path('app/marketing/battle/'.$asset->id));
        @mkdir($dir, 0775, true);

        $matrix = [];
        foreach ($out['variants'] as $v) {
            $file = $dir.'/'.$v['variant_id'].'.html';
            file_put_contents($file, $v['html']);
            $matrix[] = ['variant_id' => $v['variant_id'], 'axes' => $v['axes'],
                'overall_score' => $v['overall_score'], 'grade' => $v['grade'],
                'hollowness' => $v['hollowness'], 'flagged' => $v['flagged'],
                'injected' => $v['injected'], 'file' => $file];
        }
        file_put_contents($dir.'/matrix.json', json_encode(['axes' => $out['axes'], 'variants' => $matrix,
            'recommended' => $out['recommended']['variant_id'] ?? null], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->line('');
        $this->line('  <fg=white;options=bold>BATTLE PLAN — '.$out['n_variants'].' variantes ortogonais</>');
        $this->line('  Asset: '.($asset->title ?: '(sem título)').'  (#'.$asset->id.')');
        $this->line('  Axes: angles='.count($opts['angles']).' × hooks='.count($opts['hooks']).' × awarenesses='.count($opts['awarenesses']));
        $this->line('');

        $rec = $out['recommended']['variant_id'] ?? null;
        foreach ($out['variants'] as $v) {
            $marker = $v['variant_id'] === $rec ? '<fg=green;options=bold>★</>' : ' ';
            $flag = $v['flagged'] ? '<fg=red;options=bold>⚠</>' : '';
            $this->line(sprintf('  %s %s <fg=cyan>%-3s</> | %-18s · %-22s · %-15s | score=<fg=yellow>%3d</> (%s) hollow=%d %s',
                $marker, $flag, $v['variant_id'], $v['axes']['angle'], $v['axes']['hook'], $v['axes']['awareness'],
                $v['overall_score'], $v['grade'], $v['hollowness'], ''));
        }
        $this->line('');
        $this->line('  <fg=green>★ recomendada:</> '.($rec ?? '(nenhuma passou o gate)'));
        $this->line('  HTMLs salvos em: <fg=white>'.$dir.'</>');
        $this->line('  Matriz JSON: <fg=white>'.$dir.'/matrix.json</>');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * @param  array<int,string>  $default
     * @return array<int,string>
     */
    private function csv(mixed $raw, array $default): array
    {
        $raw = (string) ($raw ?? '');
        if ($raw === '') {
            return $default;
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
