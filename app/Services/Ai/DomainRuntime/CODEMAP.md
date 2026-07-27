# CODEMAP — app/Services/Ai/DomainRuntime

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| DomainCapabilityCatalogService | `App\Services\Ai\DomainRuntime\DomainCapabilityCatalogService::register` |
| DomainHandoffService | `App\Services\Ai\DomainRuntime\DomainHandoffService::emit` |
| DomainManifestRegistryService | `App\Services\Ai\DomainRuntime\DomainManifestRegistryService::register` |
| DomainMaturityAssessmentService | `App\Services\Ai\DomainRuntime\DomainMaturityAssessmentService::assess` |
| DomainRuntimeControlPlaneService | `App\Services\Ai\DomainRuntime\DomainRuntimeControlPlaneService::snapshot` |
| DomainRuntimeException | `App\Services\Ai\DomainRuntime\DomainRuntimeException::duplicateDomain` |
| DomainRuntimeReadinessService | `App\Services\Ai\DomainRuntime\DomainRuntimeReadinessService::report` |
| DomainRuntimeRecordService | `App\Services\Ai\DomainRuntime\DomainRuntimeRecordService::open` |
| DomainRuntimeSelectionService | `App\Services\Ai\DomainRuntime\DomainRuntimeSelectionService::select` |
| DomainSeedManifests | `App\Services\Ai\DomainRuntime\DomainSeedManifests::all` |

Façades: 10.
