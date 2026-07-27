# CODEMAP — Hermes

<!-- ATLAS-CODEMAP: GENERATED — do not hand-edit.
     Rebuild: php artisan atlas:codemap --write
     Verify:  php artisan atlas:codemap --verify -->

Public façades of this zone: classes named by at least one file outside it.
The target is the first public entry method — the door, not the whole surface.

| Public façade | Concrete navigation target |
| --- | --- |
| AtlasHermesAcpRuntime | `App\Services\Ai\Hermes\Acp\AtlasHermesAcpRuntime::run` |
| HermesAcpChannel | `App\Services\Ai\Hermes\Acp\HermesAcpChannel::start` |
| HermesAcpSessionPool | `App\Services\Ai\Hermes\Acp\HermesAcpSessionPool::lease` |
| HermesAcpTransport | `App\Services\Ai\Hermes\Acp\HermesAcpTransport::start` |
| HermesCapabilityEnablementGate | `App\Services\Ai\Hermes\HermesCapabilityEnablementGate::approve` |
| HermesCapabilityInvocationBuilder | `App\Services\Ai\Hermes\HermesCapabilityInvocationBuilder::apply` |
| HermesCapabilityProbe | `App\Services\Ai\Hermes\HermesCapabilityProbe::probe` |
| HermesCapabilityRegistry | `App\Services\Ai\Hermes\HermesCapabilityRegistry::record` |
| HermesDelegationAdapter | `App\Services\Ai\Hermes\HermesDelegationAdapter::authorize` |
| HermesDoctorPreflight | `App\Services\Ai\Hermes\Mesh\HermesDoctorPreflight::assess` |
| HermesExecutiveMissionFactory | `App\Services\Ai\Hermes\HermesExecutiveMissionFactory::build` |
| HermesHookBridge | `App\Services\Ai\Hermes\HermesHookBridge::register` |
| HermesHookSink | `App\Services\Ai\Hermes\HermesHookSink::ingest` |
| HermesKanbanCli | `App\Services\Ai\Hermes\Kanban\HermesKanbanCli::invoke` |
| HermesKanbanProcessCli | `App\Services\Ai\Hermes\Kanban\HermesKanbanProcessCli::invoke` |
| HermesKanbanSwarmService | `App\Services\Ai\Hermes\Kanban\HermesKanbanSwarmService::compose` |
| HermesManagedMcpConfigProvisioner | `App\Services\Ai\Hermes\HermesManagedMcpConfigProvisioner::write` |
| HermesMcpAdapter | `App\Services\Ai\Hermes\HermesMcpAdapter::resolve` |
| HermesMemoryAdapter | `App\Services\Ai\Hermes\HermesMemoryAdapter::persistCandidates` |
| HermesMeshJobRunner | `App\Services\Ai\Hermes\Mesh\HermesMeshJobRunner::run` |
| HermesMeshProcessWorkerFactory | `App\Services\Ai\Hermes\Mesh\HermesMeshProcessWorkerFactory::workerFor` |
| HermesMeshRoutingAdvisor | `App\Services\Ai\Hermes\Mesh\HermesMeshRoutingAdvisor::advise` |
| HermesNativeFcCapabilityAttestor | `App\Services\Ai\Hermes\HermesNativeFcCapabilityAttestor::capabilitiesFor` |
| HermesNativeFunctionCallSupport | `App\Services\Ai\Hermes\HermesNativeFunctionCallSupport::atlasApplyPatchDeclaration` |
| HermesProcedureAdapter | `App\Services\Ai\Hermes\HermesProcedureAdapter::persistCandidates` |
| HermesResultPacketFactory | `App\Services\Ai\Hermes\HermesResultPacketFactory::build` |
| HermesRuntimeRouter | `App\Services\Ai\Hermes\HermesRuntimeRouter::isAutoRoutingCandidate` |
| HermesScheduleAdapter | `App\Services\Ai\Hermes\HermesScheduleAdapter::persistCandidates` |
| HermesSessionEvidenceImporter | `App\Services\Ai\Hermes\Mesh\HermesSessionEvidenceImporter::import` |
| HermesSkillProvisioner | `App\Services\Ai\Hermes\HermesSkillProvisioner::provision` |
| HermesWorkcellAdapter | `App\Services\Ai\Hermes\Mesh\HermesWorkcellAdapter::plan` |
| ManagedHermesHome | `App\Services\Ai\Hermes\ManagedHermesHome::path` |

Façades: 32.
