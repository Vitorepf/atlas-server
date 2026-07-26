# CODEMAP — SelfConstruction (D10 partial)

| Capability | Canonical owner |
|---|---|
| Task queue orchestrator | `App\Services\Ai\SelfConstruction\ControlPlane\TaskQueue\AgentControlPlaneTaskQueueOrchestrator` |
| Historical FQCN | `App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator` (**class_alias** stub) |
| Task serving / land | `AtlasTaskServingService` |
| Control plane packets | `ControlPlane\AgentControlPlane*` |

ASDD D10: rehome complete with alias window. Prefer new namespace in new code.
