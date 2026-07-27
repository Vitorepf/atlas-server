# CODEMAP — app/Services/Tools

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AtlasToolApprovalService | `App\Services\Tools\AtlasToolApprovalService::approve` |
| AtlasToolAuthorityMatrixService | `App\Services\Tools\AtlasToolAuthorityMatrixService::matrix` |
| AtlasToolAuthorityPolicyService | `App\Services\Tools\AtlasToolAuthorityPolicyService::catalog` |
| AtlasToolEvidenceQueryService | `App\Services\Tools\AtlasToolEvidenceQueryService::recent` |
| AtlasToolEvidenceStore | `App\Services\Tools\AtlasToolEvidenceStore::recordExternalToolResult` |
| AtlasToolExecutor | `App\Services\Tools\AtlasToolExecutor::execute` |
| AtlasToolFindingWaiverService | `App\Services\Tools\AtlasToolFindingWaiverService::waive` |
| AtlasToolGateService | `App\Services\Tools\AtlasToolGateService::evaluate` |
| AtlasToolPolicyEngine | `App\Services\Tools\AtlasToolPolicyEngine::decide` |
| AtlasToolRegistryService | `App\Services\Tools\AtlasToolRegistryService::syncSeedDefinitions` |
| AtlasToolReleaseGateService | `App\Services\Tools\AtlasToolReleaseGateService::evaluate` |
| AtlasToolResultNormalizer | `App\Services\Tools\AtlasToolResultNormalizer::normalize` |

Façades: 12.
