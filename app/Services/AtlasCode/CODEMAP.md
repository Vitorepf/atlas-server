# CODEMAP — app/Services/AtlasCode

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AtlasCodeAskService | `App\Services\AtlasCode\AtlasCodeAskService::answer` |
| AtlasCodeGraphService | `App\Services\AtlasCode\AtlasCodeGraphService::parseLogLine` |
| AtlasCodeHealService | `App\Services\AtlasCode\AtlasCodeHealService::policy` |
| AtlasCodeMirrorService | `App\Services\AtlasCode\AtlasCodeMirrorService::scanForSecrets` |
| AtlasCodeObservedSessionService | `App\Services\AtlasCode\AtlasCodeObservedSessionService::listForObra` |
| AtlasCodePreflightService | `App\Services\AtlasCode\AtlasCodePreflightService::check` |
| AtlasCodeProvenanceService | `App\Services\AtlasCode\AtlasCodeProvenanceService::agentForAuthor` |
| AtlasCodeProviderGovernanceService | `App\Services\AtlasCode\AtlasCodeProviderGovernanceService::snapshot` |
| AtlasCodeProviderOperatingRoomService | `App\Services\AtlasCode\AtlasCodeProviderOperatingRoomService::snapshotForObra` |
| AtlasCodeReviewService | `App\Services\AtlasCode\AtlasCodeReviewService::reviewKey` |
| AtlasCodeVerificationCommandRunner | `App\Services\AtlasCode\AtlasCodeVerificationCommandRunner::run` |
| AtlasCodeViolationService | `App\Services\AtlasCode\AtlasCodeViolationService::scan` |
| AtlasCodeWeekService | `App\Services\AtlasCode\AtlasCodeWeekService::capture` |
| AtlasCodeWhyService | `App\Services\AtlasCode\AtlasCodeWhyService::capture` |
| AtlasCodeWorkPacketService | `App\Services\AtlasCode\AtlasCodeWorkPacketService::listForObra` |
| AtlasCodeWorkspaceProfileService | `App\Services\AtlasCode\AtlasCodeWorkspaceProfileService::listProfiles` |
| AtlasCodeWorkspaceScanner | `App\Services\AtlasCode\AtlasCodeWorkspaceScanner::workspaceRoot` |
| DevToForgePromotionService | `App\Services\AtlasCode\DevToForgePromotionService::previewForThread` |
| GitWorkspaceInspector | `App\Services\AtlasCode\GitWorkspaceInspector::captureSnapshot` |
| WorkspaceFolderIntelligenceService | `App\Services\AtlasCode\WorkspaceFolderIntelligenceService::inspect` |
| WorkspaceIntelligenceAssemblyService | `App\Services\AtlasCode\WorkspaceIntelligenceAssemblyService::queueAssembly` |
| WorkspaceIntelligenceStatusReader | `App\Services\AtlasCode\WorkspaceIntelligenceStatusReader::status` |

Façades: 22.
