# CODEMAP — app/Services/Ai/ToolRuntime

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| ToolCapabilityCatalogService | `App\Services\Ai\ToolRuntime\ToolCapabilityCatalogService::register` |
| ToolDefinitionRegistryService | `App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService::register` |
| ToolHealthService | `App\Services\Ai\ToolRuntime\ToolHealthService::doctor` |
| ToolInvocationService | `App\Services\Ai\ToolRuntime\ToolInvocationService::invoke` |
| ToolPlanningService | `App\Services\Ai\ToolRuntime\ToolPlanningService::plan` |
| ToolPolicyBridgeService | `App\Services\Ai\ToolRuntime\ToolPolicyBridgeService::evaluate` |
| ToolReceiptService | `App\Services\Ai\ToolRuntime\ToolReceiptService::emit` |
| ToolRuntimeControlPlaneService | `App\Services\Ai\ToolRuntime\ToolRuntimeControlPlaneService::snapshot` |
| ToolRuntimeException | `App\Services\Ai\ToolRuntime\ToolRuntimeException::duplicateTool` |
| ToolRuntimeReadinessService | `App\Services\Ai\ToolRuntime\ToolRuntimeReadinessService::report` |
| ToolSeedDefinitions | `App\Services\Ai\ToolRuntime\ToolSeedDefinitions::all` |
| ToolValidationService | `App\Services\Ai\ToolRuntime\ToolValidationService::validate` |

Façades: 12.
