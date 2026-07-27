# CODEMAP — Mobile

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AiCriticalInboxReviewReadModel | `App\Services\Ai\Mobile\AiCriticalInboxReviewReadModel::review` |
| AiInboxHumanPresentation | `App\Services\Ai\Mobile\AiInboxHumanPresentation::forItem` |
| AtlasInboxService | `App\Services\Ai\Mobile\AtlasInboxService::create` |
| AtlasLiveActivityPushService | `App\Services\Ai\Mobile\AtlasLiveActivityPushService::publish` |
| AutoImprovementProposalScanner | `App\Services\Ai\Mobile\AutoImprovementProposalScanner::scan` |
| ContextBundleService | `App\Services\Ai\Mobile\ContextBundleService::create` |
| InboxActionRegistry | `App\Services\Ai\Mobile\InboxActionRegistry::handle` |
| InsightInboxEmitter | `App\Services\Ai\Mobile\InsightInboxEmitter::emit` |
| InsightWatcherService | `App\Services\Ai\Mobile\InsightWatcherService::run` |
| JobResultInboxEmitter | `App\Services\Ai\Mobile\JobResultInboxEmitter::emitIfImportant` |
| MobileGatewayRateLimiter | `App\Services\Ai\Mobile\MobileGatewayRateLimiter::assertPairingInitiateAllowed` |
| MobileHealthService | `App\Services\Ai\Mobile\MobileHealthService::snapshot` |
| MobileMaintenanceService | `App\Services\Ai\Mobile\MobileMaintenanceService::expireStale` |
| MobileNotificationPreferences | `App\Services\Ai\Mobile\MobileNotificationPreferences::defaults` |
| MobilePairingService | `App\Services\Ai\Mobile\MobilePairingService::initiate` |
| MobilePushService | `App\Services\Ai\Mobile\MobilePushService::dispatchForInboxItem` |
| MobileReliabilityMonitor | `App\Services\Ai\Mobile\MobileReliabilityMonitor::check` |
| ProactiveLayerReadModel | `App\Services\Ai\Mobile\ProactiveLayerReadModel::report` |
| ProposalInboxEmitter | `App\Services\Ai\Mobile\ProposalInboxEmitter::emit` |
| SelfDiagnosticEmitter | `App\Services\Ai\Mobile\SelfDiagnosticEmitter::run` |

Façades: 20.
