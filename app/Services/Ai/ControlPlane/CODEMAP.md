# CODEMAP — app/Services/Ai/ControlPlane

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiInteractionSteeringService | `App\Services\Ai\ControlPlane\AiInteractionSteeringService::steer` |
| AtlasAiControlPlaneService | `App\Services\Ai\ControlPlane\AtlasAiControlPlaneService::report` |
| AtlasControlPlaneBlockerService | `App\Services\Ai\ControlPlane\AtlasControlPlaneBlockerService::snapshot` |
| AtlasControlPlaneMissionService | `App\Services\Ai\ControlPlane\AtlasControlPlaneMissionService::summary` |
| AtlasControlPlaneNextActionService | `App\Services\Ai\ControlPlane\AtlasControlPlaneNextActionService::actions` |
| AtlasControlPlaneReadinessService | `App\Services\Ai\ControlPlane\AtlasControlPlaneReadinessService::report` |
| AtlasControlPlaneSnapshotService | `App\Services\Ai\ControlPlane\AtlasControlPlaneSnapshotService::snapshot` |
| AtlasControlPlaneStatus | `App\Services\Ai\ControlPlane\AtlasControlPlaneStatus::reduce` |

Façades: 8.
