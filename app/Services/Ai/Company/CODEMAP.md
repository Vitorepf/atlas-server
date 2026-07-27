# CODEMAP — app/Services/Ai/Company

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| ReconciledCashEventStore | `App\Services\Ai\Company\Ventures\Reward\ReconciledCashEventStore::record` |
| VentureAssessmentService | `App\Services\Ai\Company\Ventures\Assessment\VentureAssessmentService::run` |
| VentureBusinessRuleService | `App\Services\Ai\Company\Ventures\VentureBusinessRuleService::declare` |
| VentureComprehensionService | `App\Services\Ai\Company\Ventures\Comprehension\VentureComprehensionService::run` |
| VentureCostAttributionLedger | `App\Services\Ai\Company\Ventures\Cost\VentureCostAttributionLedger::record` |
| VentureExecutionBridgeService | `App\Services\Ai\Company\Ventures\VentureExecutionBridgeService::bridge` |
| VentureFoundryException | `App\Services\Ai\Company\Ventures\VentureFoundryException::missingField` |
| VentureGrowthLadderService | `App\Services\Ai\Company\Ventures\VentureGrowthLadderService::stageKey` |
| VentureHealthGate | `App\Services\Ai\Company\Ventures\Health\VentureHealthGate::evaluate` |
| VentureIdeaGenerationService | `App\Services\Ai\Company\Ventures\VentureIdeaGenerationService::generate` |
| VentureIdeationService | `App\Services\Ai\Company\Ventures\VentureIdeationService::register` |
| VentureQuestionCatalog | `App\Services\Ai\Company\Ventures\Assessment\VentureQuestionCatalog::all` |
| VentureReconciledSuccessEvaluator | `App\Services\Ai\Company\Ventures\Success\VentureReconciledSuccessEvaluator::evaluate` |
| VentureRegistryService | `App\Services\Ai\Company\Ventures\VentureRegistryService::promoteIdea` |
| VentureResearchHandoffService | `App\Services\Ai\Company\Ventures\VentureResearchHandoffService::emitForVenture` |
| VentureStrategistService | `App\Services\Ai\Company\Ventures\VentureStrategistService::review` |

Façades: 16.
