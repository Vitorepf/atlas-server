# CODEMAP — TerminalDev

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AapSchema | `App\Services\Ai\TerminalDev\Protocol\AapSchema::request` |
| AtlasTerminalSessionRuntime | `App\Services\Ai\TerminalDev\Session\AtlasTerminalSessionRuntime::start` |
| AtlasTerminalSessionStore | `App\Services\Ai\TerminalDev\Session\AtlasTerminalSessionStore::root` |
| TerminalDesktopBridgeContract | `App\Services\Ai\TerminalDev\Desktop\TerminalDesktopBridgeContract::describe` |
| TerminalHermesBridge | `App\Services\Ai\TerminalDev\Providers\TerminalHermesBridge::isEnabled` |
| TerminalHookRunner | `App\Services\Ai\TerminalDev\Hooks\TerminalHookRunner::run` |
| TerminalMcpClient | `App\Services\Ai\TerminalDev\Mcp\TerminalMcpClient::servers` |
| TerminalPluginCatalog | `App\Services\Ai\TerminalDev\Plugins\TerminalPluginCatalog::list` |
| TerminalSessionOps | `App\Services\Ai\TerminalDev\Session\TerminalSessionOps::exportMarkdown` |
| TerminalSubagentRunner | `App\Services\Ai\TerminalDev\Subagents\TerminalSubagentRunner::spawn` |
| TerminalSuperiorityService | `App\Services\Ai\TerminalDev\Superiority\TerminalSuperiorityService::evidenceReceipt` |
| TerminalToolHost | `App\Services\Ai\TerminalDev\Tools\TerminalToolHost::available` |

Façades: 12.
