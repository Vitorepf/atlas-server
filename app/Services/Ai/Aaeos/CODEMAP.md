# CODEMAP — app/Services/Ai/Aaeos

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AaeosAdmissionVerdict | `App\Services\Ai\Aaeos\Control\AaeosAdmissionVerdict::isValid` |
| AaeosCycleOutcomeRecorder | `App\Services\Ai\Aaeos\Control\AaeosCycleOutcomeRecorder::record` |
| AaeosCycleRuntime | `App\Services\Ai\Aaeos\Control\AaeosCycleRuntime::runCycle` |
| AaeosExecutorMode | `App\Services\Ai\Aaeos\Control\AaeosExecutorMode::isValid` |
| AaeosOrgStateProjector | `App\Services\Ai\Aaeos\Control\AaeosOrgStateProjector::project` |
| AaeosScorecardProjector | `App\Services\Ai\Aaeos\Control\AaeosScorecardProjector::project` |
| AaeosSpineGate | `App\Services\Ai\Aaeos\Spine\AaeosSpineGate::evaluate` |
| AaeosWorldSnapshotBuilder | `App\Services\Ai\Aaeos\Control\AaeosWorldSnapshotBuilder::build` |

Façades: 8.
