# CODEMAP — MemoryGovernance

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AtlasMemoryGovernanceService | `App\Services\Ai\MemoryGovernance\AtlasMemoryGovernanceService::applyFeedbackGovernance` |
| AtlasMemoryPrivacyService | `App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService::normalizeForStorage` |
| AtlasMemorySourcePrivacyPolicy | `App\Services\Ai\MemoryGovernance\AtlasMemorySourcePrivacyPolicy::project` |
| MemoryHealthCompositePolicy | `App\Services\Ai\MemoryGovernance\MemoryHealthCompositePolicy::compose` |
| MemoryQualityStatusPolicy | `App\Services\Ai\MemoryGovernance\MemoryQualityStatusPolicy::classify` |

Façades: 5.
