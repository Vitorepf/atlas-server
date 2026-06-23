<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * ConversionPipelineValidator — turns the conversion-pipeline KNOWLEDGE into an actionable
 * readiness diagnostic. Sending the REAL SALE back to Google is the silent ceiling on scale; this
 * validates a tracking stack against the loop (auto-tagging → GCLID → Data Manager API → Enhanced
 * Conversions → postback macros → email auth), scores readiness 0-100, and emits the ordered setup
 * steps. It is a DATA-QUALITY metric, never a veto — Atlas reports, it does not refuse.
 */
class ConversionPipelineValidator
{
    /** Postback macros that must be present for the tracker to attribute the sale to the click. */
    public const REQUIRED_MACROS = ['{tid}', '{click_id}', '{fbclid}'];

    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * @param  array<string,mixed>  $stack  has_auto_tagging, captures_gclid, uses_data_manager_api,
     *                                       enhanced_conversions, postback_macros[], has_email_auth,
     *                                       affiliate_network, tracker, traffic_channel
     * @return array<string,mixed>
     */
    public function validate(array $stack): array
    {
        $postbackOk = $this->validatePostback((array) ($stack['postback_macros'] ?? []));

        $checklist = [
            'auto_tagging' => (bool) ($stack['has_auto_tagging'] ?? false),
            'gclid_capture' => (bool) ($stack['captures_gclid'] ?? false),
            'data_manager_api' => (bool) ($stack['uses_data_manager_api'] ?? false),
            'enhanced_conversions' => (bool) ($stack['enhanced_conversions'] ?? false),
            'postback_macros' => $postbackOk['complete'],
            'email_auth_spf_dkim_dmarc' => (bool) ($stack['has_email_auth'] ?? false),
        ];

        $present = count(array_filter($checklist));
        $total = count($checklist);
        $readiness = (int) round($present / $total * 100);

        $missing = array_keys(array_filter($checklist, static fn (bool $v): bool => ! $v));

        return [
            'stack' => [
                'affiliate_network' => $stack['affiliate_network'] ?? null,
                'tracker' => $stack['tracker'] ?? null,
                'traffic_channel' => $stack['traffic_channel'] ?? 'google',
            ],
            'checklist' => $checklist,
            'readiness' => $readiness,
            'blind_risk' => $readiness < 100,
            'missing' => $missing,
            'postback' => $postbackOk,
            'ordered_steps' => $this->orderedSteps($missing),
            'why_it_matters' => $this->playbook->conversionPipeline()['principle'],
            'note' => $readiness === 100
                ? 'Loop completo — a venda real volta pro Smart Bidding; otimização por lucro destravada.'
                : 'Loop incompleto — o Smart Bidding otimiza no escuro até fechar os itens faltantes (não é bloqueio, é qualidade de dado).',
        ];
    }

    /**
     * Macro-presence diagnostic for a postback URL/config.
     *
     * @param  array<int,string>  $macros
     * @return array<string,mixed>
     */
    public function validatePostback(array $macros): array
    {
        $have = array_map('strtolower', array_map('strval', $macros));
        $missing = [];
        foreach (self::REQUIRED_MACROS as $req) {
            if (! in_array(strtolower($req), $have, true)) {
                $missing[] = $req;
            }
        }

        return [
            'complete' => $missing === [],
            'required' => self::REQUIRED_MACROS,
            'missing' => $missing,
        ];
    }

    /**
     * Per-stack setup spec: the loop diagram, the postback template and the macro mapping.
     *
     * @return array<string,mixed>
     */
    public function planByStack(string $network, string $channel): array
    {
        $cp = $this->playbook->conversionPipeline();

        return [
            'network' => $network,
            'channel' => $channel,
            'flow' => ['click', 'tracker (GCLID/click_id)', 'sale', 'postback', 'Google (Data Manager API / Enhanced Conversions)'],
            'steps' => [
                $cp['oci_gclid'],
                $cp['enhanced_conversions'],
                $cp['data_manager_api']['action'],
                $cp['postback'],
            ],
            'postback_template' => 'https://{tracker_domain}/postback?cid={click_id}&tid={tid}&payout={commission}&txid={transaction_id}',
            'macro_mapping' => [
                '{click_id}' => 'id do clique gerado no hop/tracker',
                '{tid}' => 'tracking id do link (campanha/criativo)',
                '{fbclid}' => 'click id da plataforma (cross-channel)',
                '{commission}' => 'valor financeiro da venda (otimizar por receita)',
            ],
            'data_manager_api_status' => $cp['data_manager_api']['status'],
        ];
    }

    /**
     * @param  array<int,string>  $missing
     * @return array<int,string>
     */
    private function orderedSteps(array $missing): array
    {
        $order = [
            'auto_tagging' => '1. Ligar auto-tagging no Google Ads (anexa o GCLID na URL).',
            'gclid_capture' => '2. Capturar + armazenar o GCLID com o lead no tracker.',
            'postback_macros' => '3. Configurar o postback com {tid}/{click_id}/{fbclid} (atribuição exata).',
            'data_manager_api' => '4. Subir a venda real via Data Manager API (em vigor desde 2026-06-15).',
            'enhanced_conversions' => '5. Enhanced Conversions for leads (GCLID + PII hash SHA-256 = +10%).',
            'email_auth_spf_dkim_dmarc' => '6. SPF+DKIM+DMARC pro backend de email (2,7× inbox).',
        ];

        $steps = [];
        foreach ($order as $key => $text) {
            if (in_array($key, $missing, true)) {
                $steps[] = $text;
            }
        }

        return $steps;
    }
}
