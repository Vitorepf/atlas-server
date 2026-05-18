<?php

namespace App\Services\Ai\Finance\Kernel;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionCanonicalHash;

class FinanceComplianceService
{
    /**
     * Compliance scan that flags forbidden actions / live-trade intents inside
     * an incoming request. Always returns a structured review report; never
     * proceeds to execute anything.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function review(AiMission $mission, string $requestedAction, string $rationale = '', array $context = []): array
    {
        $combined = $requestedAction.' '.$rationale.' '.$mission->raw_prompt;
        $liveTradeHits = FinanceDomainCanon::detectLiveTradeIntent($combined);

        $flags = [];
        if (FinanceDomainCanon::isForbiddenAction($requestedAction)) {
            $flags[] = [
                'kind' => 'forbidden_action',
                'severity' => 'critical',
                'detail' => "requested_action [{$requestedAction}] is on the hard-block list",
            ];
        }
        foreach ($liveTradeHits as $needle) {
            $flags[] = [
                'kind' => 'live_trade_intent',
                'severity' => 'critical',
                'detail' => "kill-switch keyword detected: [{$needle}]",
            ];
        }
        if (FinanceDomainCanon::liveTradingBlocked()) {
            $flags[] = [
                'kind' => 'live_trading_default_blocked',
                'severity' => 'informational',
                'detail' => 'Live trading is blocked by default; only paper simulation, review and reporting are permitted.',
            ];
        }
        if (! isset($context['operator_consent']) || $context['operator_consent'] !== true) {
            $flags[] = [
                'kind' => 'missing_operator_consent',
                'severity' => 'info',
                'detail' => 'No explicit operator consent passed in context; required before any sensitive action.',
            ];
        }

        $critical = collect($flags)->where('severity', 'critical')->count();
        $decision = $critical > 0 ? 'BLOCK' : 'REVIEW';

        $report = [
            'schema' => 'atlas.ai.finance.compliance_review.v1',
            'kind' => 'compliance_review',
            'mission_id' => $mission->id,
            'requested_action' => $requestedAction,
            'flags' => $flags,
            'critical_flag_count' => $critical,
            'decision' => $decision,
            'live_trading_default_blocked' => FinanceDomainCanon::liveTradingBlocked(),
        ];
        $report['receipt_hash'] = MissionCanonicalHash::sha256($report);

        return $report;
    }

    /**
     * Hard guard used by orchestrators before any "execution-like" path. ALWAYS
     * throws on detected live-trade intent or forbidden action. There is no
     * caller-provided escape hatch.
     */
    public function assertNotLiveTrade(string $requestedAction, string $rationale = ''): void
    {
        if (FinanceDomainCanon::isForbiddenAction($requestedAction)) {
            throw FinanceDomainException::forbiddenAction($requestedAction);
        }
        $hits = FinanceDomainCanon::detectLiveTradeIntent($requestedAction.' '.$rationale);
        if ($hits !== []) {
            throw FinanceDomainException::liveTradingBlocked('live-trade keywords detected: '.implode(',', $hits));
        }
    }
}
