<?php

namespace App\Console\Commands;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\CampaignBlueprintService;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiMarketingCampaignCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:campaign
        {action=blueprint : blueprint}
        {--vsl= : VSL asset id}
        {--campaign= : VSL campaign_ref (latest structured)}
        {--payout= : Payout per sale (CPA fixo), required}
        {--currency=USD : Payout currency}
        {--margin=0.30 : Target net margin (0..1)}
        {--refund=0.10 : Expected refund rate (0..1)}
        {--cvr= : Click->sale CVR (0..1) — overrides mined pattern; default = mined pattern or 0.01}
        {--budget= : Daily test budget (default = 3x Max CPA)}
        {--geo= : Target geo (default = VSL target_geo)}
        {--pattern-niche= : Usar padrão vencedor minerado deste nicho (CVR real + keywords provadas)}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Marketing: deterministic Google-Search launch blueprint from a VSL asset.';

    public function handle(CampaignBlueprintService $service): int
    {
        try {
            $vsl = $this->resolveVsl();
            if ($vsl === null) {
                return $this->respondError('VSL asset not found — pass --vsl=<id> or --campaign=<ref>');
            }
            if (trim((string) $vsl->transcript) === '') {
                return $this->respondError("VSL [{$vsl->id}] has no transcript / not extracted yet.");
            }

            $payout = (float) $this->option('payout');
            if ($payout <= 0) {
                return $this->respondError('--payout (CPA por venda) é obrigatório e deve ser > 0');
            }

            $inputs = array_filter([
                'payout' => $payout,
                'currency' => (string) $this->option('currency'),
                'target_margin' => (float) $this->option('margin'),
                'refund_rate' => (float) $this->option('refund'),
                'cvr' => ($c = trim((string) $this->option('cvr'))) !== '' ? (float) $c : null,
                'daily_budget' => $this->option('budget') !== null ? (float) $this->option('budget') : null,
                'geo' => $this->option('geo'),
                'pattern_niche' => $this->option('pattern-niche'),
            ], static fn ($v): bool => $v !== null && $v !== '');

            $blueprint = $service->generate($vsl, $inputs);

            return $this->emit($blueprint);
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage(), $e::class);
        }
    }

    private function resolveVsl(): ?AiMarketingVslAsset
    {
        if ($id = trim((string) $this->option('vsl'))) {
            return AiMarketingVslAsset::query()->where('id', $id)->first();
        }
        if ($ref = trim((string) $this->option('campaign'))) {
            return AiMarketingVslAsset::query()->where('campaign_ref', $ref)->latest('last_ingested_at')->first();
        }

        return null;
    }

    private function emit(\App\Models\AiMarketingCampaignBlueprint $b): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'ok' => true,
                'blueprint' => $b->toArray(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $e = (array) $b->economics;
        $cur = (string) ($e['currency'] ?? 'USD');
        $this->components->twoColumnDetail('blueprint_id', (string) $b->id);
        $this->components->twoColumnDetail('channel / geo', $b->channel.' / '.(string) $b->geo);
        $this->components->twoColumnDetail('Max CPA', ($e['max_cpa'] ?? '?').' '.$cur);
        $this->components->twoColumnDetail('Breakeven CPA', ($e['breakeven_cpa'] ?? '?').' '.$cur);
        $this->components->twoColumnDetail('Target CPC', ($e['target_cpc'] ?? '?').' '.$cur);
        $this->components->twoColumnDetail('Target ROAS', (string) ($e['target_roas'] ?? '?'));
        $this->components->twoColumnDetail('Kill @ 0 vendas', ($e['kill_spend_no_sale'] ?? '?').' '.$cur);
        $this->components->twoColumnDetail('Budget/dia', ($e['daily_budget'] ?? '?').' '.$cur);
        $this->components->twoColumnDetail('1º lance', (string) (($b->bid_plan['first_test_strategy'] ?? '')));
        $this->components->twoColumnDetail('ad groups', (string) count((array) ($b->keywords_plan['ad_groups'] ?? [])));
        $this->components->info('Blueprint determinístico gerado. Use --json pra ver completo.');

        return self::SUCCESS;
    }

    private function respondError(string $message, ?string $type = null): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(array_filter(['ok' => false, 'error' => $message, 'type' => $type])) ?: '{}');
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
