# CODEMAP — Surface

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiSurfaceHandoffService | `App\Services\Ai\Surface\AiSurfaceHandoffService::record` |
| AtlasFinalResponseSanitizer | `App\Services\Ai\Surface\AtlasFinalResponseSanitizer::sanitize` |
| BaseSurfaceAdapter | `App\Services\Ai\Surface\Adapters\BaseSurfaceAdapter::surfaceId` |
| ConstelacaoPositionsService | `App\Services\Ai\Surface\ConstelacaoPositionsService::positions` |
| DomainCatalogSurfaceSelectionService | `App\Services\Ai\Surface\DomainCatalogSurfaceSelectionService::select` |
| SurfaceAdapterRegistry | `App\Services\Ai\Surface\SurfaceAdapterRegistry::all` |

Façades: 6.
