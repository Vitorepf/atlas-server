# CODEMAP — Vox

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| VoxActionOutcomeService | `App\Services\Ai\Vox\VoxActionOutcomeService::completed` |
| VoxAutoModeRouter | `App\Services\Ai\Vox\Routing\VoxAutoModeRouter::decide` |
| VoxClaudeCliExecutor | `App\Services\Ai\Vox\Execution\VoxClaudeCliExecutor::id` |
| VoxCodexCliExecutor | `App\Services\Ai\Vox\Execution\VoxCodexCliExecutor::id` |
| VoxCognitiveFlowGovernor | `App\Services\Ai\Vox\Governor\VoxCognitiveFlowGovernor::govern` |
| VoxCompiler | `App\Services\Ai\Vox\VoxCompiler::compile` |
| VoxConfirmationService | `App\Services\Ai\Vox\Confirmation\VoxConfirmationService::issue` |
| VoxDogfoodService | `App\Services\Ai\Vox\Dogfood\VoxDogfoodService::allowedOutcomes` |
| VoxDogfoodSummaryService | `App\Services\Ai\Vox\Dogfood\VoxDogfoodSummaryService::build` |
| VoxEvidenceService | `App\Services\Ai\Vox\VoxEvidenceService::transcriptReady` |
| VoxExecutionGate | `App\Services\Ai\Vox\Execution\VoxExecutionGate::evaluate` |
| VoxExecutor | `App\Services\Ai\Vox\Execution\VoxExecutor::id` |
| VoxExecutorRouter | `App\Services\Ai\Vox\Execution\VoxExecutorRouter::healthSnapshot` |
| VoxFilesystemEditExecutor | `App\Services\Ai\Vox\Execution\VoxFilesystemEditExecutor::id` |
| VoxFlowOrchestrator | `App\Services\Ai\Vox\Routing\VoxFlowOrchestrator::decide` |
| VoxIntentExtractor | `App\Services\Ai\Vox\VoxIntentExtractor::extract` |
| VoxInterlocutorPolicy | `App\Services\Ai\Vox\Interlocutor\VoxInterlocutorPolicy::evaluate` |
| VoxMetricsService | `App\Services\Ai\Vox\Metrics\VoxMetricsService::snapshot` |
| VoxNoteCaptureExecutor | `App\Services\Ai\Vox\Execution\VoxNoteCaptureExecutor::id` |
| VoxPromptCompiler | `App\Services\Ai\Vox\VoxPromptCompiler::compile` |
| VoxPromptPolisher | `App\Services\Ai\Vox\VoxPromptPolisher::polish` |
| VoxReadinessService | `App\Services\Ai\Vox\Readiness\VoxReadinessService::probe` |
| VoxReceiptService | `App\Services\Ai\Vox\VoxReceiptService::issueR0` |
| VoxSchema | `App\Services\Ai\Vox\VoxSchema::prohibitedAudioFields` |
| VoxTerminalProposeExecutor | `App\Services\Ai\Vox\Execution\VoxTerminalProposeExecutor::id` |
| VoxV3CertificationPackService | `App\Services\Ai\Vox\Gate\VoxV3CertificationPackService::build` |
| VoxV3HardeningAuditService | `App\Services\Ai\Vox\Audit\VoxV3HardeningAuditService::checkNames` |
| VoxV3PromotionGateService | `App\Services\Ai\Vox\Gate\VoxV3PromotionGateService::evaluate` |
| VoxV5CertificationService | `App\Services\Ai\Vox\Gate\VoxV5CertificationService::build` |
| VoxV68CertificationService | `App\Services\Ai\Vox\Gate\VoxV68CertificationService::build` |
| VoxV6CertificationService | `App\Services\Ai\Vox\Gate\VoxV6CertificationService::build` |
| VoxV6QualityBenchService | `App\Services\Ai\Vox\Gate\VoxV6QualityBenchService::build` |

Façades: 32.
