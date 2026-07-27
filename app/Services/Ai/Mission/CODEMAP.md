# CODEMAP — app/Services/Ai/Mission

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiGatewayMissionBridge | `App\Services\Ai\Mission\AiGatewayMissionBridge::enabled` |
| MissionCanonicalHash | `App\Services\Ai\Mission\MissionCanonicalHash::sha256` |
| MissionCertificationService | `App\Services\Ai\Mission\MissionCertificationService::certify` |
| MissionControlPlaneService | `App\Services\Ai\Mission\MissionControlPlaneService::snapshot` |
| MissionDetectionService | `App\Services\Ai\Mission\MissionDetectionService::detect` |
| MissionEvidenceService | `App\Services\Ai\Mission\MissionEvidenceService::attach` |
| MissionFactoryService | `App\Services\Ai\Mission\MissionFactoryService::create` |
| MissionFollowThroughService | `App\Services\Ai\Mission\MissionFollowThroughService::runNext` |
| MissionLifecycleException | `App\Services\Ai\Mission\MissionLifecycleException::invalidTransition` |
| MissionLifecycleService | `App\Services\Ai\Mission\MissionLifecycleService::transition` |
| MissionModeResult | `App\Services\Ai\Mission\MissionModeResult::skipped` |
| MissionModeService | `App\Services\Ai\Mission\MissionModeService::processIntent` |
| MissionReadinessService | `App\Services\Ai\Mission\MissionReadinessService::requiredKernelRuntimeTables` |
| ObjectiveDecomposerService | `App\Services\Ai\Mission\ObjectiveDecomposerService::decompose` |
| WorkOrderFactoryService | `App\Services\Ai\Mission\WorkOrderFactoryService::plan` |

Façades: 15.
