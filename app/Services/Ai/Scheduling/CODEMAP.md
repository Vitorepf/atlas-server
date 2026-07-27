# CODEMAP — Scheduling

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AtlasCliSchedulerService | `App\Services\Ai\Scheduling\AtlasCliSchedulerService::addTask` |
| AtlasSchedulerInput | `App\Services\Ai\Scheduling\AtlasSchedulerInput::dueTaskLimit` |
| AtlasSchedulerInstallService | `App\Services\Ai\Scheduling\AtlasSchedulerInstallService::inspect` |
| LongRunningWorkReadModel | `App\Services\Ai\Scheduling\LongRunningWorkReadModel::report` |
| ScheduleParser | `App\Services\Ai\Scheduling\ScheduleParser::parse` |
| ScheduledJobStopConditionGate | `App\Services\Ai\Scheduling\Governance\ScheduledJobStopConditionGate::evaluate` |

Façades: 6.
