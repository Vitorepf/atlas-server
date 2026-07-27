# CODEMAP — Finance

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| ArbAllocator | `App\Services\Ai\Finance\PolymarketExec\ArbAllocator::allocate` |
| AtlasFinanceDomainContract | `App\Services\Ai\Finance\AtlasFinanceDomainContract::flowDefinitions` |
| AtlasFinanceOrchestrator | `App\Services\Ai\Finance\AtlasFinanceOrchestrator::flowPlan` |
| BasketPlanner | `App\Services\Ai\Finance\PolymarketExec\BasketPlanner::plan` |
| BasketStateMachine | `App\Services\Ai\Finance\PolymarketExec\BasketStateMachine::execute` |
| BinanceLiveMarket | `App\Services\Ai\Finance\SpotExec\BinanceLiveMarket::closedBars` |
| BinanceSpotFeed | `App\Services\Ai\Finance\PolymarketShadow\BinanceSpotFeed::mid` |
| BinanceSpotOrderClient | `App\Services\Ai\Finance\SpotExec\BinanceSpotOrderClient::hasCredentials` |
| CandidateRediscoveryLedger | `App\Services\Ai\Finance\StrategyLoop\Campaign\CandidateRediscoveryLedger::default` |
| ChampionQuarantine | `App\Services\Ai\Finance\StrategyLoop\Campaign\ChampionQuarantine::evaluate` |
| CrossCampaignRediscoveryGate | `App\Services\Ai\Finance\StrategyLoop\Campaign\CrossCampaignRediscoveryGate::evaluate` |
| ExternalPythonTrendBreakoutReplay | `App\Services\Ai\Finance\StrategyLoop\Campaign\ExternalPythonTrendBreakoutReplay::evaluate` |
| FairValueEngine | `App\Services\Ai\Finance\PolymarketShadow\FairValueEngine::seed` |
| FinanceControlPlaneProjection | `App\Services\Ai\Finance\Kernel\FinanceControlPlaneProjection::snapshot` |
| FinanceDomainCanon | `App\Services\Ai\Finance\Kernel\FinanceDomainCanon::liveTradingBlocked` |
| FinanceDomainException | `App\Services\Ai\Finance\Kernel\FinanceDomainException::liveTradingBlocked` |
| FinanceDomainReadinessService | `App\Services\Ai\Finance\Kernel\FinanceDomainReadinessService::report` |
| FinanceDomainSmokeService | `App\Services\Ai\Finance\Kernel\FinanceDomainSmokeService::run` |
| FinanceEnterpriseAnalysisService | `App\Services\Ai\Finance\Kernel\FinanceEnterpriseAnalysisService::packet` |
| FreqtradeSecondEngineAdapter | `App\Services\Ai\Finance\StrategyLoop\Campaign\FreqtradeSecondEngineAdapter::evaluate` |
| FundingExtremeStrategy | `App\Services\Ai\Finance\StrategyLoop\Strategy\FundingExtremeStrategy::run` |
| FundingTape | `App\Services\Ai\Finance\StrategyLoop\FundingTape::default` |
| HonestMetrics | `App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics::mean` |
| IndependentTrendBreakoutReplay | `App\Services\Ai\Finance\StrategyLoop\Campaign\IndependentTrendBreakoutReplay::evaluate` |
| LivePolyExecClient | `App\Services\Ai\Finance\PolymarketExec\LivePolyExecClient::mode` |
| LivePolyOnChainClient | `App\Services\Ai\Finance\PolymarketExec\OnChain\LivePolyOnChainClient::mode` |
| MarketDataCache | `App\Services\Ai\Finance\StrategyLoop\MarketDataCache::default` |
| MarketRegimeAnalyzer | `App\Services\Ai\Finance\StrategyLoop\Campaign\MarketRegimeAnalyzer::summarize` |
| MeanReversionStrategy | `App\Services\Ai\Finance\StrategyLoop\Strategy\MeanReversionStrategy::run` |
| MintSellStateMachine | `App\Services\Ai\Finance\PolymarketExec\MintSellStateMachine::execute` |
| MomentumStrategy | `App\Services\Ai\Finance\StrategyLoop\Strategy\MomentumStrategy::run` |
| NetProfitModel | `App\Services\Ai\Finance\PolymarketShadow\NetProfitModel::fixedCostFor` |
| PaperTradeRuntime | `App\Services\Ai\Finance\SpotExec\PaperTradeRuntime::tick` |
| PolyAccountIdentity | `App\Services\Ai\Finance\PolymarketExec\PolyAccountIdentity::detect` |
| PolyExecClient | `App\Services\Ai\Finance\PolymarketExec\PolyExecClient::mode` |
| PolyExecConfig | `App\Services\Ai\Finance\PolymarketExec\PolyExecConfig::fromConfig` |
| PolyExecGate | `App\Services\Ai\Finance\PolymarketExec\PolyExecGate::checkRuntimeCaps` |
| PolyOnChainClient | `App\Services\Ai\Finance\PolymarketExec\OnChain\PolyOnChainClient::mode` |
| PolymarketArbScanner | `App\Services\Ai\Finance\PolymarketShadow\PolymarketArbScanner::scanOnce` |
| PolymarketImplicationScanner | `App\Services\Ai\Finance\PolymarketShadow\PolymarketImplicationScanner::scanOnce` |
| PolymarketPinnedHttp | `App\Services\Ai\Finance\PolymarketShadow\PolymarketPinnedHttp::getJson` |
| PolymarketShadowFeed | `App\Services\Ai\Finance\PolymarketShadow\PolymarketShadowFeed::windowStart` |
| RegimeAdaptiveStrategy | `App\Services\Ai\Finance\StrategyLoop\Strategy\RegimeAdaptiveStrategy::run` |
| SecondEngineDivergenceGate | `App\Services\Ai\Finance\StrategyLoop\Campaign\SecondEngineDivergenceGate::evaluate` |
| ShadowCalibrationReport | `App\Services\Ai\Finance\PolymarketShadow\ShadowCalibrationReport::build` |
| ShadowDecisionEngine | `App\Services\Ai\Finance\PolymarketShadow\ShadowDecisionEngine::feePerShare` |
| ShadowRunState | `App\Services\Ai\Finance\PolymarketShadow\ShadowRunState::snapshot` |
| ShadowSettlement | `App\Services\Ai\Finance\PolymarketShadow\ShadowSettlement::settleDueWindows` |
| ShortBasketPlanner | `App\Services\Ai\Finance\PolymarketExec\ShortBasketPlanner::plan` |
| SimulatedPolyExecClient | `App\Services\Ai\Finance\PolymarketExec\SimulatedPolyExecClient::mode` |
| SimulatedPolyOnChainClient | `App\Services\Ai\Finance\PolymarketExec\OnChain\SimulatedPolyOnChainClient::mode` |
| SpotExecGate | `App\Services\Ai\Finance\SpotExec\SpotExecGate::fromConfig` |
| StrategyCampaignReporter | `App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCampaignReporter::summarizeLedger` |
| StrategyCampaignStore | `App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCampaignStore::open` |
| StrategyCandidateSignature | `App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCandidateSignature::make` |
| StrategyConfirmationQueue | `App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyConfirmationQueue::default` |
| StrategyFeatureSetProfile | `App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyFeatureSetProfile::describe` |
| StrategyLoopAdversarialAudit | `App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyLoopAdversarialAudit::audit` |
| StrategyLoopOperationalAudit | `App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyLoopOperationalAudit::audit` |
| StrategyLoopPlanCompletionAudit | `App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyLoopPlanCompletionAudit::audit` |
| StrategyLoopScientificReadinessAudit | `App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyLoopScientificReadinessAudit::audit` |
| StrategyParetoSelector | `App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyParetoSelector::defaultObjectives` |
| StrategyRobustnessChecks | `App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyRobustnessChecks::costStress` |
| StrategyRunner | `App\Services\Ai\Finance\StrategyLoop\Strategy\StrategyRunner::run` |
| StrategyScenarioRegistry | `App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyScenarioRegistry::default` |
| StrategyTimeframeProfile | `App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyTimeframeProfile::describe` |
| TradingHonestyGate | `App\Services\Ai\Finance\StrategyLoop\TradingHonestyGate::evaluate` |
| TrendBreakoutStrategy | `App\Services\Ai\Finance\StrategyLoop\Strategy\TrendBreakoutStrategy::run` |
| TrendPullbackStrategy | `App\Services\Ai\Finance\StrategyLoop\Strategy\TrendPullbackStrategy::run` |
| VolumeBreakoutStrategy | `App\Services\Ai\Finance\StrategyLoop\Strategy\VolumeBreakoutStrategy::run` |

Façades: 70.
