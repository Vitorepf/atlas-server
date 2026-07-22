# AI CODEMAP — initial navigation skeleton

<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->

This is intentionally incomplete. It is a small, verified starting point for
navigation during GOD-DEBULK, not a corpus-complete ownership map.

| Change concern | Concrete navigation target |
| --- | --- |
| Classify incoming AI intent before routing | `App\Services\Ai\Router\AtlasAiIntentKernelService::classify` |
| Build a provider-safe context-feedback proposal | `App\Services\Ai\AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::advise` |
| Project safe trace artifacts for a trace | `App\Services\Ai\AiTraceArtifactsProjection::forTrace` |
