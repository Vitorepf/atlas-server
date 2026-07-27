# CODEMAP — app/Services/Ai/Aemor

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AemorOutcomeEnvelopeAdapter | `App\Services\Ai\Aemor\Envelope\AemorOutcomeEnvelopeAdapter::origin` |
| AtlasAemorCertificationService | `App\Services\Ai\Aemor\AtlasAemorCertificationService::certify` |
| AtlasAemorJudgmentService | `App\Services\Ai\Aemor\AtlasAemorJudgmentService::judge` |
| AtlasAemorRuntimeService | `App\Services\Ai\Aemor\AtlasAemorRuntimeService::openEpisode` |
| AtlasEngineeringOutcomeRecorder | `App\Services\Ai\Aemor\AtlasEngineeringOutcomeRecorder::record` |
| CompoundingOutcomeEnvelopeAdapter | `App\Services\Ai\Aemor\Envelope\CompoundingOutcomeEnvelopeAdapter::origin` |
| DevProceduralOutcomeEnvelopeAdapter | `App\Services\Ai\Aemor\Envelope\DevProceduralOutcomeEnvelopeAdapter::origin` |
| OutcomeEnvelope | `App\Services\Ai\Aemor\Envelope\OutcomeEnvelope::normalizeStatus` |
| OutcomeEnvelopeAdapter | `App\Services\Ai\Aemor\Envelope\OutcomeEnvelopeAdapter::origin` |
| OutcomeEnvelopeBridge | `App\Services\Ai\Aemor\Envelope\OutcomeEnvelopeBridge::enabled` |

Façades: 10.
