<?php

namespace App\Services\Ai\Finance\Kernel;

class FinanceDomainCanon
{
    public const DOMAIN_ID = 'finance';

    public const DOMAIN_NAME = 'Atlas Finance / Investment Company Runtime';

    public const SCHEMA_RUNTIME = 'atlas.ai.finance.runtime.v1';

    public const SCHEMA_CONTROL_PLANE = 'atlas.ai.finance.control_plane.v1';

    public const SCHEMA_READINESS = 'atlas.ai.finance.readiness.v1';

    public const CAPABILITIES = [
        'finance.research_desk',
        'finance.valuation',
        'finance.portfolio_review',
        'finance.risk_review',
        'finance.compliance',
        'finance.reporting',
        'finance.paper_trading_simulation',
    ];

    /**
     * Hard, non-overridable forbidden actions. These exist because the operator
     * has NOT issued an explicit live-trade mandate. Bridges and runtime services
     * MUST refuse silently or explicitly when these are requested.
     */
    public const FORBIDDEN_ACTIONS = [
        'execute_live_trade',
        'submit_broker_order',
        'transfer_funds',
        'withdraw_funds',
        'wire_transfer',
        'automatic_rebalance',
        'leverage_increase_without_mandate',
        'short_sell_without_mandate',
        'open_margin_position',
        'send_money_to_third_party',
    ];

    /**
     * Live-trading kill-switch keywords scanned in any incoming prompt /
     * request. If any of these phrases appears, the request MUST be blocked
     * regardless of policy bridge state.
     */
    public const LIVE_TRADE_KEYWORDS = [
        'execute trade', 'execute order', 'enviar ordem', 'colocar ordem',
        'submit order', 'place order', 'broker order',
        'comprar acoes ja', 'comprar agora', 'vender agora', 'sell now', 'buy now',
        'transfer funds', 'transferir fundos', 'transferir dinheiro',
        'wire transfer', 'enviar pix', 'wire money',
        'rebalance live', 'rebalancear ao vivo', 'auto rebalance',
        'go long live', 'go short live',
    ];

    public const QUALITY_GATES = [
        'sources_attributed',
        'assumptions_listed',
        'risk_disclosed',
        'compliance_reviewed',
        'evidence_attached',
        'no_live_execution',
    ];

    public const EVIDENCE_SCHEMA = [
        'source_ref',
        'data_artifact',
        'doc',
        'receipt',
        'artifact',
        'blocker',
        'certification',
    ];

    public const TOOLS_ALLOWED = [
        'docs.search',
        'browser.readonly',
        'api.readonly',
        'filesystem.read',
        'artifact.write_local',
        'evidence.attach',
        'policy.evaluate',
    ];

    public const HANDOFF_RULES = [
        'allowed' => ['finance', 'research', 'strategy', 'operations'],
        'forbidden' => [],
    ];

    public const DELIVERY_TYPES = [
        'research_note',
        'valuation_model',
        'portfolio_view',
        'risk_report',
        'compliance_review',
        'paper_trade_simulation_report',
        'investment_brief',
    ];

    public const METRICS = [
        'source_diversity',
        'risk_disclosure_rate',
        'paper_trade_pnl_estimate',
        'compliance_flags_raised',
        'live_trade_blocks',
    ];

    /**
     * Returns the kill-switch state. ALWAYS true unless an explicit operator
     * mandate flips the config. There is no programmatic path to override this
     * at runtime.
     */
    public static function liveTradingBlocked(): bool
    {
        $config = function_exists('config') ? (bool) config('atlas.finance.live_trading_allowed', false) : false;

        return $config === false;
    }

    /**
     * @return array<int,string>
     */
    public static function detectLiveTradeIntent(string $text): array
    {
        $normalized = mb_strtolower($text);
        $hits = [];
        foreach (self::LIVE_TRADE_KEYWORDS as $needle) {
            if ($needle !== '' && str_contains($normalized, $needle)) {
                $hits[] = $needle;
            }
        }

        return array_values(array_unique($hits));
    }

    public static function isForbiddenAction(string $action): bool
    {
        return in_array($action, self::FORBIDDEN_ACTIONS, true);
    }
}
