# CODEMAP — app/Services/Ai/Runtime

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiToolPermissionEngine | `App\Services\Ai\Runtime\AiToolPermissionEngine::authorize` |
| AiToolProcessRunner | `App\Services\Ai\Runtime\AiToolProcessRunner::runProcess` |
| AiToolRuntime | `App\Services\Ai\Runtime\AiToolRuntime::availableTools` |
| AtlasTestCommandResolver | `App\Services\Ai\Runtime\AtlasTestCommandResolver::preferred` |
| TestCommandInput | `App\Services\Ai\Runtime\TestCommandInput::memoryLimit` |
| ToolActionRuntimeReadModel | `App\Services\Ai\Runtime\ToolActionRuntimeReadModel::report` |
| ToolInvocation | `App\Services\Ai\Runtime\ToolInvocation::make` |
| ToolResult | `App\Services\Ai\Runtime\ToolResult::failure` |
| WorkspaceProfile | `App\Services\Ai\Runtime\WorkspaceProfile::toArray` |
| WorkspaceProfiler | `App\Services\Ai\Runtime\WorkspaceProfiler::profile` |

Façades: 10.
