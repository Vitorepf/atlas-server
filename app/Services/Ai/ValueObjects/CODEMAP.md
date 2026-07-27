# CODEMAP — ValueObjects

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiContextPack | `App\Services\Ai\ValueObjects\AiContextPack::idRemap` |
| AiPromptExecutionPlan | `App\Services\Ai\ValueObjects\AiPromptExecutionPlan::fromTask` |
| AiTaskRequest | `App\Services\Ai\ValueObjects\AiTaskRequest::fromInput` |
| AiThreadResolution | `App\Services\Ai\ValueObjects\AiThreadResolution::toArray` |
| ContextIdRemap | `App\Services\Ai\ValueObjects\ContextIdRemap::empty` |
| OperationalDecision | `App\Services\Ai\ValueObjects\OperationalDecision::fromArray` |

Façades: 6.
