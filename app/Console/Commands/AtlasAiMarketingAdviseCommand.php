<?php

namespace App\Console\Commands;

use App\Services\Ai\MarketingDomain\Decision\MarketingActionPlaybookResolver;
use App\Services\Ai\MarketingDomain\Decision\MarketingDecisionAdvisor;
use App\Services\Ai\MarketingDomain\Decision\MarketingDecisionLedger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Stage-1 decision engine — the highest-leverage surface. Feed it the funnel snapshot + payout
 * and it returns the ONE action (from the canonical 9) to take, the specific lever/skill, the
 * VSL block to edit, and the numeric rule — deterministically. --record writes the decision to
 * the ledger (the MOAT); --recall shows how that lever has performed for this niche before.
 */
class AtlasAiMarketingAdviseCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:advise
        {--payout= : Payout per sale (CPA por venda) — habilita a matemática de Max CPA/decision-spend}
        {--margin=0.30 : Target net margin (0..1)}
        {--refund=0.10 : Expected refund rate (0..1)}
        {--cvr= : Click->sale CVR (0..1)}
        {--ad-ctr= : CTR do anúncio (0..1)}
        {--bridge-to-vsl= : taxa bridge->VSL (0..1)}
        {--watch-through= : taxa de chegada ao pitch da VSL (0..1)}
        {--checkout-rate= : taxa pitch->checkout (0..1)}
        {--spend= : gasto acumulado}
        {--sales= : vendas acumuladas}
        {--ctr-declining : sinalizar fadiga de criativo (CTR em queda)}
        {--niche= : nicho (contexto do ledger + recall)}
        {--campaign= : campaign_ref (contexto do ledger)}
        {--record : gravar a decisão primária no ledger (o MOAT)}
        {--recall : mostrar win-rate das decisões anteriores do nicho}
        {--playbook : anexar o blueprint determinístico da skill da ação (decisão→execução)}
        {--awareness= : consciência do tráfego (afina o playbook de copy/oferta)}
        {--json : saída JSON}';

    protected $description = 'Atlas Marketing: motor de decisão Stage-1 — sintoma do funil → ação + alavanca (determinístico).';

    public function handle(
        MarketingDecisionAdvisor $advisor,
        MarketingDecisionLedger $ledger,
        MarketingActionPlaybookResolver $playbooks,
    ): int {
        try {
            $inputs = array_filter([
                'payout' => $this->floatOpt('payout'),
                'margin' => $this->floatOpt('margin'),
                'refund' => $this->floatOpt('refund'),
                'cvr' => $this->floatOpt('cvr'),
                'niche' => $this->strOpt('niche'),
            ], static fn ($v): bool => $v !== null);

            $funnel = array_filter([
                'ad_ctr' => $this->floatOpt('ad-ctr'),
                'bridge_to_vsl' => $this->floatOpt('bridge-to-vsl'),
                'vsl_watch_through' => $this->floatOpt('watch-through'),
                'checkout_rate' => $this->floatOpt('checkout-rate'),
                'spend' => $this->floatOpt('spend'),
                'sales' => $this->floatOpt('sales'),
            ], static fn ($v): bool => $v !== null);
            if ((bool) $this->option('ctr-declining')) {
                $funnel['ctr_declining'] = true;
            }

            // Grounded advisor: real-data floors/CVR + ledger win-rate folded into the call.
            $diagnosis = $advisor->advise($funnel, $inputs);

            // Decision → execution: attach the deterministic skill playbook for the chosen action.
            if ((bool) $this->option('playbook') && ! empty($diagnosis['primary']['action'])) {
                $resolved = $playbooks->resolve((string) $diagnosis['primary']['action'], $this->strOpt('awareness'));
                if ($resolved !== null) {
                    $diagnosis['primary']['playbook'] = $resolved;
                }
            }

            $recorded = null;
            if ((bool) $this->option('record') && ! empty($diagnosis['primary'])) {
                $recorded = $ledger->record($diagnosis['primary'], [
                    'campaign_ref' => $this->strOpt('campaign'),
                    'niche' => $this->strOpt('niche'),
                    'offer_state' => ['funnel' => $funnel, 'inputs' => $inputs, 'grounding' => $diagnosis['grounding'] ?? []],
                ])->id;
            }

            $recall = (bool) $this->option('recall') && $this->strOpt('niche') !== null
                ? $ledger->recall(['niche' => $this->strOpt('niche')])
                : null;

            return $this->emit($diagnosis, $recorded, $recall);
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage(), $e::class);
        }
    }

    /**
     * @param  array<string,mixed>  $diagnosis
     * @param  array<string,mixed>|null  $recall
     */
    private function emit(array $diagnosis, ?string $recorded, ?array $recall): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(array_filter([
                'ok' => true,
                'diagnosis' => $diagnosis,
                'recorded_decision_id' => $recorded,
                'recall' => $recall,
            ], static fn ($v): bool => $v !== null), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $p = (array) ($diagnosis['primary'] ?? []);
        $this->components->twoColumnDetail('data sufficient', $diagnosis['data_sufficient'] ? 'sim' : 'NÃO (HOLD)');
        $this->components->twoColumnDetail('estágio/sintoma', ($p['stage'] ?? '?').' — '.($p['symptom'] ?? ''));
        $this->components->twoColumnDetail('AÇÃO', (string) ($p['action'] ?? '?'));
        $this->components->twoColumnDetail('alavanca/skill', (string) ($p['lever'] ?? '?'));
        if (! empty($p['vsl_block'])) {
            $this->components->twoColumnDetail('bloco da VSL', (string) $p['vsl_block']);
        }
        $this->components->twoColumnDetail('regra numérica', (string) ($p['numeric_rule'] ?? ''));
        $this->components->twoColumnDetail('efeito previsto', (string) ($p['predicted_effect'] ?? ''));
        $grounding = (array) ($diagnosis['grounding'] ?? []);
        if (! empty($grounding)) {
            $this->components->twoColumnDetail('CVR ancorado em', (string) ($grounding['cvr_source'] ?? '?').($grounding['pattern_found'] ? ' (pisos do padrão real)' : ''));
        }
        if (! empty($p['history']['note'])) {
            $this->components->twoColumnDetail('histórico (ledger)', (string) $p['history']['note']);
        }
        if (! empty($p['playbook'])) {
            $this->components->twoColumnDetail('playbook (execução)', (string) ($p['playbook']['skill'] ?? '').' — --json p/ o blueprint completo');
        }
        if ($recorded !== null) {
            $this->components->info("Decisão gravada no ledger: {$recorded}");
        }
        if ($recall !== null) {
            $this->components->info("Recall: {$recall['count']} decisões anteriores neste nicho. Use --json p/ ver win-rates.");
        }

        return self::SUCCESS;
    }

    private function floatOpt(string $name): ?float
    {
        $v = $this->option($name);

        return ($v !== null && $v !== '' && is_numeric($v)) ? (float) $v : null;
    }

    private function strOpt(string $name): ?string
    {
        $v = trim((string) $this->option($name));

        return $v === '' ? null : $v;
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
