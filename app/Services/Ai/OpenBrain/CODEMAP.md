# CODEMAP — OpenBrain

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AtlasAobgLatencyLedger | `App\Services\Ai\OpenBrain\AtlasAobgLatencyLedger::recordPack` |
| ContextExpansionRenderSupport | `App\Services\Ai\OpenBrain\Support\ContextExpansionRenderSupport::providerSafeRef` |
| FileContextBudgetSupport | `App\Services\Ai\OpenBrain\Support\FileContextBudgetSupport::enforceTotalCeiling` |
| GraphPathFilterSupport | `App\Services\Ai\OpenBrain\Support\GraphPathFilterSupport::sanitizeGraphLabel` |
| RecallExceptionDetector | `App\Services\Ai\OpenBrain\RecallTrigger\RecallExceptionDetector::detect` |
| RecallTriggerClassifier | `App\Services\Ai\OpenBrain\RecallTrigger\RecallTriggerClassifier::classify` |

Façades: 6.
