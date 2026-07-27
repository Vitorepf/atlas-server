# CODEMAP — app/Services/Ai/RuntimeBoundary

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| GraphRankRuntimeClient | `App\Services\Ai\RuntimeBoundary\GraphRankRuntimeClient::rank` |
| HonestMetricsRuntimeClient | `App\Services\Ai\RuntimeBoundary\HonestMetricsRuntimeClient::honestyGate` |
| NearDuplicateRuntimeClient | `App\Services\Ai\RuntimeBoundary\NearDuplicateRuntimeClient::detect` |
| SemanticCrossEncoderRuntime | `App\Services\Ai\RuntimeBoundary\SemanticCrossEncoderRuntime::crossEncoderRerank` |
| SemanticLateInteractionRuntime | `App\Services\Ai\RuntimeBoundary\SemanticLateInteractionRuntime::lateInteractionRerank` |
| SemanticRagRuntimeClient | `App\Services\Ai\RuntimeBoundary\SemanticRagRuntimeClient::available` |
| SemanticRetrievalRuntime | `App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime::available` |
| StatsEngineRuntimeClient | `App\Services\Ai\RuntimeBoundary\StatsEngineRuntimeClient::ks` |

Façades: 8.
