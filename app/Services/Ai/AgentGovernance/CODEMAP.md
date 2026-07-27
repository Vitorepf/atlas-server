# CODEMAP — AgentGovernance

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AtlasAgentDesiredStateStore | `App\Services\Ai\AgentGovernance\AtlasAgentDesiredStateStore::desired` |
| AtlasAgentEventLedger | `App\Services\Ai\AgentGovernance\AtlasAgentEventLedger::append` |
| AtlasAgentReconciler | `App\Services\Ai\AgentGovernance\AtlasAgentReconciler::reconcile` |
| AtlasAgentRegistry | `App\Services\Ai\AgentGovernance\AtlasAgentRegistry::snapshot` |
| AtlasFleetCatalog | `App\Services\Ai\AgentGovernance\AtlasFleetCatalog::normalizeKey` |
| AtlasFleetMasterSwitch | `App\Services\Ai\AgentGovernance\AtlasFleetMasterSwitch::enabled` |
| FleetDriver | `App\Services\Ai\AgentGovernance\FleetDriver::isAlive` |
| SystemFleetDriver | `App\Services\Ai\AgentGovernance\SystemFleetDriver::isAlive` |

Façades: 8.
