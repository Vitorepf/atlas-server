# CODEMAP — Cli

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AtlasCliCheckpointService | `App\Services\Ai\Cli\AtlasCliCheckpointService::list` |
| AtlasCliDashboardService | `App\Services\Ai\Cli\AtlasCliDashboardService::build` |
| AtlasCliDevEfficientHandler | `App\Services\Ai\Cli\AtlasCliDevEfficientHandler::run` |
| AtlasCliDevWorkflowService | `App\Services\Ai\Cli\AtlasCliDevWorkflowService::preflight` |
| AtlasCliDoctorService | `App\Services\Ai\Cli\AtlasCliDoctorService::diagnose` |
| AtlasCliDogfoodService | `App\Services\Ai\Cli\AtlasCliDogfoodService::requiredScenarios` |
| AtlasCliInstallService | `App\Services\Ai\Cli\AtlasCliInstallService::plan` |
| AtlasCliModelCatalogService | `App\Services\Ai\Cli\AtlasCliModelCatalogService::select` |
| AtlasCliPanel | `App\Services\Ai\Cli\AtlasCliPanel::make` |
| AtlasCliProviderStrategyService | `App\Services\Ai\Cli\AtlasCliProviderStrategyService::recommend` |
| AtlasCliQualityService | `App\Services\Ai\Cli\AtlasCliQualityService::evaluate` |
| AtlasCliSessionService | `App\Services\Ai\Cli\AtlasCliSessionService::snapshot` |
| AtlasCliSetupService | `App\Services\Ai\Cli\AtlasCliSetupService::diagnose` |
| AtlasCliStartService | `App\Services\Ai\Cli\AtlasCliStartService::briefing` |
| AtlasCliTelemetry | `App\Services\Ai\Cli\AtlasCliTelemetry::correlationId` |
| AtlasFileAttachmentService | `App\Services\Ai\Cli\AtlasFileAttachmentService::fromUploadedFiles` |
| AtlasImageAttachmentService | `App\Services\Ai\Cli\AtlasImageAttachmentService::fromPaths` |
| AtlasReplHistory | `App\Services\Ai\Cli\AtlasReplHistory::load` |
| AtlasTerminalTheme | `App\Services\Ai\Cli\AtlasTerminalTheme::ok` |
| DevProgressReporter | `App\Services\Ai\Cli\DevProgressReporter::note` |
| HistorySearch | `App\Services\Ai\Cli\Repl\HistorySearch::appendQueryChar` |
| IntentPermissionResolver | `App\Services\Ai\Cli\IntentPermissionResolver::resolve` |
| IntentResolution | `App\Services\Ai\Cli\IntentResolution::isWrite` |
| KeyEvent | `App\Services\Ai\Cli\Repl\KeyEvent::char` |
| KeySequenceParser | `App\Services\Ai\Cli\Repl\KeySequenceParser::parse` |
| ReplComposer | `App\Services\Ai\Cli\Repl\ReplComposer::text` |
| ReplMessages | `App\Services\Ai\Cli\Repl\ReplMessages::imageRemoved` |
| ReplRenderer | `App\Services\Ai\Cli\Repl\ReplRenderer::setStatusBarProducer` |
| StatusBarFormatter | `App\Services\Ai\Cli\Repl\StatusBarFormatter::format` |

Façades: 29.
