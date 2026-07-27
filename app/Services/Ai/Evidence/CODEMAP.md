# CODEMAP — Evidence

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| ArtifactRegistryService | `App\Services\Ai\Evidence\ArtifactRegistryService::register` |
| AuditEventService | `App\Services\Ai\Evidence\AuditEventService::record` |
| BlockerService | `App\Services\Ai\Evidence\BlockerService::open` |
| CertificationRuntimeService | `App\Services\Ai\Evidence\CertificationRuntimeService::certify` |
| ClaimVerificationService | `App\Services\Ai\Evidence\ClaimVerificationService::register` |
| EvidenceControlPlaneService | `App\Services\Ai\Evidence\EvidenceControlPlaneService::snapshot` |
| EvidencePackService | `App\Services\Ai\Evidence\EvidencePackService::build` |
| EvidenceReadinessService | `App\Services\Ai\Evidence\EvidenceReadinessService::report` |
| GateRunService | `App\Services\Ai\Evidence\GateRunService::record` |
| MissionEvidenceAdapter | `App\Services\Ai\Evidence\MissionEvidenceAdapter::buildMissionPack` |
| ReceiptService | `App\Services\Ai\Evidence\ReceiptService::emit` |
| SourceRefService | `App\Services\Ai\Evidence\SourceRefService::register` |
| TestResultService | `App\Services\Ai\Evidence\TestResultService::record` |

Façades: 13.
