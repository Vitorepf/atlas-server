# CODEMAP — Router

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiIntentRouter | `App\Services\Ai\Router\AiIntentRouter::route` |
| AtlasAiFlowStatusReadModel | `App\Services\Ai\Router\AtlasAiFlowStatusReadModel::forTrace` |
| AtlasAiHyperflowCertificationService | `App\Services\Ai\Router\AtlasAiHyperflowCertificationService::certify` |
| AtlasAiRouterDecision | `App\Services\Ai\Router\AtlasAiRouterDecision::toArray` |
| AtlasAiRouterRuntimeBootstrapService | `App\Services\Ai\Router\AtlasAiRouterRuntimeBootstrapService::bootstrap` |
| AtlasAiRouterRuntimeReadinessService | `App\Services\Ai\Router\AtlasAiRouterRuntimeReadinessService::inspect` |
| AtlasAiRouterService | `App\Services\Ai\Router\AtlasAiRouterService::decide` |
| AtlasAiSpecialistFlowExecutionService | `App\Services\Ai\Router\AtlasAiSpecialistFlowExecutionService::apply` |
| AtlasAiSpecialistFlowRuntimeService | `App\Services\Ai\Router\AtlasAiSpecialistFlowRuntimeService::apply` |
| AtlasSemanticFlowArbiterService | `App\Services\Ai\Router\AtlasSemanticFlowArbiterService::arbitrate` |

Façades: 10.
