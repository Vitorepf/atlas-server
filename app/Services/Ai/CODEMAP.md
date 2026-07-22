# AI CODEMAP — initial navigation skeleton

<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->

This is intentionally incomplete. It is a small, verified starting point for
navigation during GOD-DEBULK, not a corpus-complete ownership map.

| Change concern | Concrete navigation target |
| --- | --- |
| Classify incoming AI intent before routing | `App\Services\Ai\Router\AtlasAiIntentKernelService::classify` |
| Route legacy keyword intents | `App\Services\Ai\Router\AiIntentRouter::route` |
| Persist a sequenced stream event | `App\Services\Ai\Streaming\AiStreamRecorder::record` |
| Load a canonical Atlas skill | `App\Services\Ai\Skills\AiSkillStore::load` |
| Evaluate post-run response quality | `App\Services\Ai\Analysis\AiQualityEvaluator::evaluateTrace` |
| Plan quality remediation | `App\Services\Ai\Analysis\AiQualityActionService::planFor` |
| Build a provider-safe context-feedback proposal | `App\Services\Ai\AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::advise` |
| Project safe trace artifacts for a trace | `App\Services\Ai\AiTraceArtifactsProjection::forTrace` |
| Ingest canonical YouTube knowledge | `App\Services\Ai\Knowledge\YouTubeKnowledgeIngestionService::ingestFromInput` |
| Project canonical YouTube knowledge | `App\Services\Ai\Knowledge\YoutubeCanonicalProjection::projectIngestion` |
| Aggregate a multi-provider council trace | `App\Services\Ai\Arena\AiCouncilCoordinator::sync` |
| Classify compaction loss before a write | `App\Services\Ai\Compaction\CompactionLossPolicy::classify` |
