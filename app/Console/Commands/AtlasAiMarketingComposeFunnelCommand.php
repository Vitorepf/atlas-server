<?php

namespace App\Console\Commands;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\FunnelCongruenceAuditor;
use App\Services\Ai\MarketingDomain\Content\StructuredFunnelComposer;
use Illuminate\Console\Command;

/**
 * atlas:ai:marketing:compose-funnel — generates a structurally-sound ad→bridge→page→checkout funnel from
 * a VSL asset (audit→generate leap), then self-verifies it against the FunnelCongruenceAuditor. The
 * structure is guaranteed sound (continuity, congruence, watch-through, single CTA, no fabricated proof);
 * the persuasive prose polish is the amplifier's job — this is the scaffold.
 */
class AtlasAiMarketingComposeFunnelCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:compose-funnel
        {asset_id : AiMarketingVslAsset id}
        {--json : machine output}';

    protected $description = 'Gera um funil estruturalmente são (ad→bridge→página→checkout) a partir de um VSL asset e auto-verifica.';

    public function handle(StructuredFunnelComposer $composer, FunnelCongruenceAuditor $auditor): int
    {
        $asset = AiMarketingVslAsset::find((int) $this->argument('asset_id'));
        if ($asset === null) {
            $this->error('Asset não encontrado: '.$this->argument('asset_id'));

            return self::FAILURE;
        }

        $funnel = $composer->compose($asset);
        $audit = $auditor->audit($funnel);

        if ($this->option('json')) {
            $this->line((string) json_encode(['funnel' => $funnel, 'audit' => $audit], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=white;options=bold>FUNIL GERADO (scaffold estrutural) — asset #'.$asset->id.'</>');
        foreach ($funnel as $stage => $copy) {
            $this->line('');
            $this->line('  <fg=cyan;options=bold>['.strtoupper($stage).']</>');
            $this->line('  <fg=gray>'.wordwrap($copy, 100, "\n  ").'</>');
        }
        $this->line('');
        $v = $audit['verdict'] === 'sound' ? '<fg=green;options=bold>sound</>' : '<fg=red;options=bold>'.$audit['verdict'].'</>';
        $this->line('  Auto-verificação estrutural: '.$v.' (hops '.implode('/', array_map(fn ($h) => $h['congruence'].'%', $audit['hops'])).')');
        $this->line('  <fg=gray>Estrutura garantida; polish persuasivo = amplifier. Prova real dos claims = produtor.</>');
        $this->line('');

        return self::SUCCESS;
    }
}
