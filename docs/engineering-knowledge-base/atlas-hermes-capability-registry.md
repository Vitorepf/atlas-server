---
id: atlas-hermes-capability-registry
type: engineering_knowledge
title: Atlas Hermes Capability Registry
status: active
category: architecture
priority: 92
summary: Camada abstrata que introspecta o runtime Hermes, versiona um capability manifest, faz diff para surfar capacidades novas como candidatos governados e mapeia capacidades pedidas para flags reais so com aprovacao do ATLS, entregando captura automatica N×M com soberania Atlas.
tags:
  - atlas-ai
  - hermes
  - capability-registry
  - executive-runtime
  - antifragile
capabilities:
  - hermes_capability_introspection
  - hermes_capability_manifest
  - hermes_capability_diff_candidates
  - hermes_capability_aware_invocation
  - runtime_capability_governance
decisions:
  - ATLS e soberano sobre quais capacidades Hermes sao usadas; o registry so observa e quarentena, nunca habilita.
  - Nenhuma capacidade vira utilizavel sem estar no manifest (Hermes suporta) E na allowlist de policy (Atlas aprovou).
  - Qualquer mudanca no Hermes e auto-detectada pelo probe e surge como CapabilityCandidate revisavel (auto-captura).
  - A invocacao e abstrata: zero flags hardcoded; o builder le o manifest e emite so o que e suportado e permitido.
  - Default-safe: capability_policy nasce off; a captura e separada da habilitacao (N×M = Hermes N detectado, Atlas M aprova).
maintenance:
  - Manter abaixo de 520 linhas; expandir detalhes por adapter em docs filhos se virar implementacao pesada.
  - Atualizar quando um novo adapter consumidor (MCP, delegation, skills, hooks) ou uma nova classe de capacidade for adicionada.
related_paths:
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime.md
  - docs/engineering-knowledge-base/atlas-hermes-executive-runtime-product-spec.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - app/Services/Ai/Hermes/HermesCapabilityProbe.php
  - app/Services/Ai/Hermes/HermesCapabilityRegistry.php
  - app/Services/Ai/Hermes/HermesCapabilityInvocationBuilder.php
  - app/Services/Ai/Hermes/HermesMissionCapabilitiesFactory.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-hermes-capability-registry
graph_title: Atlas Hermes Capability Registry
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-hermes-executive-runtime
graph_status: active
graph_source: repo
macro_layer: false
human_name: Registro Abstrato de Capacidades do Hermes
canonical_name: Atlas Hermes Capability Registry
technical_name: atlas-hermes-capability-registry
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-hermes-capability-registry.md
owner: architecture
repo_paths:
  - docs/engineering-knowledge-base/atlas-hermes-capability-registry.md
  - app/Services/Ai/Hermes/HermesCapabilityProbe.php
  - app/Services/Ai/Hermes/HermesCapabilityRegistry.php
  - app/Services/Ai/Hermes/HermesCapabilityInvocationBuilder.php
  - app/Services/Ai/Hermes/HermesMissionCapabilitiesFactory.php
  - app/Models/HermesCapabilityManifest.php
  - app/Models/HermesCapabilityCandidate.php
  - app/Console/Commands/AtlasHermesCapabilitiesCommand.php
  - app/Services/Ai/HermesCliProvider.php
  - config/atlas.php
allowed_changes:
  - Adicionar novas classes de capacidade ao probe/manifest e novas estrategias de emit no builder, mantendo default-safe.
  - Evoluir os adapters consumidores (MCP, delegation, skills, hooks) sob o mesmo contrato de manifest + allowlist.
forbidden_changes:
  - Habilitar qualquer capacidade Hermes sem allowlist de policy e candidato aprovado.
  - Emitir uma flag/toolset ausente do manifest (anti-hardcode quebrado).
  - Tratar a saida do probe como verdade promovida sem o gate Atlas; fazer o probe chamar modelo ou rede.
depends_on:
  - atlas-hermes-executive-runtime
  - atlas-ai-knowledge-governance-system
flows_to:
  - atlas-runtime-router
unlocks:
  - hermes-mcp-adapter
  - hermes-delegation-adapter
  - hermes-skill-provisioner
governs:
  - hermes-capability-capture-and-enablement
evidence:
  - docs/engineering-knowledge-base/atlas-hermes-capability-registry.md
  - app/Services/Ai/Hermes/HermesCapabilityProbe.php
  - app/Services/Ai/Hermes/HermesCapabilityRegistry.php
  - app/Services/Ai/Hermes/HermesCapabilityInvocationBuilder.php
  - app/Services/Ai/Hermes/HermesMissionCapabilitiesFactory.php
  - app/Models/HermesCapabilityManifest.php
  - app/Models/HermesCapabilityCandidate.php
  - app/Console/Commands/AtlasHermesCapabilitiesCommand.php
  - tests/Unit/Ai/Hermes/HermesCapabilityProbeTest.php
  - tests/Unit/Ai/Hermes/HermesCapabilityRegistryTest.php
  - tests/Unit/Ai/Hermes/HermesCapabilityInvocationBuilderTest.php
  - tests/Unit/Ai/Hermes/HermesMissionCapabilitiesFactoryTest.php
  - tests/Feature/Ai/Hermes/AtlasHermesCapabilitiesCommandTest.php
required_tests:
  - php artisan test tests/Unit/Ai/Hermes/HermesCapabilityProbeTest.php tests/Unit/Ai/Hermes/HermesCapabilityRegistryTest.php tests/Unit/Ai/Hermes/HermesCapabilityInvocationBuilderTest.php tests/Unit/Ai/Hermes/HermesMissionCapabilitiesFactoryTest.php tests/Feature/Ai/Hermes/AtlasHermesCapabilitiesCommandTest.php
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan atlas:ai:architecture-validate --json
requires_evidence: true
risk_level: high
visual_tags:
  - runtime
  - sovereignty
  - capability
  - antifragile
ai_entrypoints:
  - Leia Resumo, Pipeline e Contratos antes de propor consumo de qualquer capacidade Hermes nova.
ai_usage_notes:
  - O probe e read-only e nunca chama modelo; trate o manifest como read model, nao verdade promovida.
  - Para habilitar uma capacidade nova, promova o CapabilityCandidate e adicione o id em capability_policy.allow; nunca hardcode flags.
quality_gates:
  - docs-health status ok
  - architecture-validate status ok
failure_modes:
  - Probe chamar modelo/rede ou vazar segredos do config.yaml.
  - Builder emitir flag ausente do manifest (hardcode) ou capacidade nao aprovada.
  - Capacidade nova ser auto-habilitada sem candidato revisado.
observability_signals:
  - Cada probe/diff/record emite atlas.hermes.capability_registry_receipt.v1 selado.
  - Cada invocacao emite atlas.hermes.capability_invocation_receipt.v1 com resolved/dropped e manifest_version.
  - ProviderUsagePayload carrega capability_manifest_hash e capability_invocation_status para o Evidence Ledger.
implementation_state: phase_4_capability_registry_keystone_implemented_consumers_landing
next_actions:
  - Promover CapabilityCandidates revisados para a allowlist via gate dedicado (HermesCapabilityEnablementGate).
  - Conectar os adapters consumidores (MCP, delegation, skills, hooks) ao manifest + allowlist.
  - Agendar probe periodico (cron governado) para diff continuo e captura automatica de drift.
---
# Atlas Hermes Capability Registry

## Resumo

O Capability Registry e a camada que torna o Hermes um runtime **abstrato**: o
ATLS captura automaticamente qualquer capacidade que o Hermes ganhe, mantendo
soberania total sobre o que e de fato usado. Nada sobre flags especificas do
Hermes fica hardcoded na invocacao.

```text
Hermes muda (nova flag/toolset/MCP/skill/hook)
-> Probe introspecta e descreve no manifest (auto-captura)
-> Registry faz diff e quarentena a novidade como CapabilityCandidate
-> ATLS revisa e aprova (allowlist de policy)
-> Builder mapeia a capacidade pedida para a flag real, so se suportada e aprovada
```

## Decisao Executiva

A equacao N×M opera aqui de forma concreta. Quando um provider/runtime salta N×
(o Hermes ganha capacidades), o probe captura esse N automaticamente; a
governanca Atlas (allowlist, candidato revisavel, evidence, reversibilidade)
adiciona o M×. O ganho composto vem de **separar deteccao de habilitacao**:

```text
Deteccao (automatica)  : Probe + Registry diff -> CapabilityCandidate
Habilitacao (soberana) : allowlist + candidato aprovado -> Builder emite a flag
```

## Papel no Atlas

O Capability Registry e um modulo filho do Executive Runtime Layer do ATLS
(`atlas-hermes-executive-runtime`). Ele nao decide intencao nem policy; apenas
descreve o que o runtime Hermes oferece e governa o que pode ser usado. O Runtime
Router decide SE o Hermes executa; este registry decide O QUE o Hermes pode usar.

## Onde Se Encaixa

O registry e filho do `atlas-hermes-executive-runtime`. Ele alimenta a
ExecutiveMission com um bloco `capabilities` e governa o que a invocacao
`hermes chat` pode realmente acionar. O Runtime Router decide se o Hermes e o
executor; o Capability Registry decide o que o Hermes pode usar quando e
escolhido.

| Componente | Papel | Soberania |
|---|---|---|
| `HermesCapabilityProbe` | Introspecta o Hermes local (read-only) | So observa; nunca habilita |
| `HermesCapabilityRegistry` | Versiona + diff + quarentena candidatos | Atlas aprova antes de usar |
| `HermesMissionCapabilitiesFactory` | Normaliza o pedido de capacidades na missao | ATLS declara o desejado |
| `HermesCapabilityInvocationBuilder` | Mapeia pedido -> flag real (gated) | Fail-closed por manifest+policy |
| `AtlasHermesCapabilitiesCommand` | Superficie operador (probe/diff/candidates) | Read-only por padrao |

## Fluxo

```text
hermes --version / --help / chat --help / mcp list / skills list / hooks list / bundles list
-> HermesCapabilityProbe (Symfony Process, timeout 15s, sem modelo, redacao AtlasSecurity)
-> atlas.hermes.capability_manifest.v1 (entries tipadas + section_status + manifest_hash)
-> HermesCapabilityRegistry.record (persiste versao, diff vs anterior)
-> CapabilityCandidate por entry adicionada/alterada (quarentena, enabled=false)
-> HermesCapabilityInvocationBuilder.apply (resolve pedido da missao contra manifest+policy)
-> args mutados + atlas.hermes.capability_invocation_receipt.v1
```

O probe e **fail-soft**: binario ausente ou timeout produz um manifest
degradado (`probe_status=binary_offline|degraded`) com `section_status` por
secao, nunca lanca excecao. Como `--json` nao e garantido nos subcomandos, o
probe parseia `--help`/saida de lista em texto (mesma tecnica do health check
existente) e tenta `<sub> --json` antes do texto.

## Contratos

`atlas.hermes.capability_manifest.v1`:

```yaml
schema_version: atlas.hermes.capability_manifest.v1
manifest_version: int            # atribuido pelo Registry ao persistir
hermes_version: string|null      # de `hermes --version`, redigido
probed_at: iso8601
probe_status: ok|degraded|binary_offline
section_status: { chat, subcommands, toolsets, mcp, skills, bundles, hooks, delegation, providers, config_yaml }
entries:
  - id: "CLASS:key"              # ex.: toolset:browser, flag:checkpoints, mcp_server:github
    capability_class: toolset|flag|subcommand|mcp_server|skill|bundle|hook|delegation|provider|context_ref|feature
    capability_key: string
    hermes_token: string|null    # token CLI real a emitir (nome de toolset, flag); null se nao emitivel
    supported: bool
    requires_config: bool
    detail: {}
    source: string
manifest_hash: sha256
```

So entries com `capability_class` em `{toolset, flag, context_ref}` sao
emitidas pelo builder: toolset -> append no `--toolsets`; flag -> flag
standalone; context_ref -> sintaxe `@` no prompt. As demais sao informativas /
de governanca (subcommand, mcp_server, skill, hook, delegation, provider).

## Regras para IA

- Nao trate o manifest como verdade promovida; e read model de introspecao.
- Nao emita flag/toolset ausente do manifest (anti-hardcode); nunca habilite capacidade fora da allowlist.
- Para habilitar capacidade nova, promova o CapabilityCandidate e adicione o id em `capability_policy.allow`.
- O probe e read-only: nunca chamar modelo/rede; redigir segredos do config.yaml.

## Candidatos e Recibos

Cada capacidade nova/alterada vira `atlas.hermes.capability_candidate.v1` numa
linha `hermes_capability_candidates`: `enabled=false`,
`gate_status='quarantined_for_atlas_capability_review'`, `risk_level` derivado
(mcp_server/hook/delegation/provider = high), com evidence do manifest. Classes
`always_quarantine` (mcp_server, hook, delegation, code_exec, gateway) nunca sao
auto-habilitadas.

Recibos selados via `HermesAdapterReceipt` (receipt_hash determinístico):

- `atlas.hermes.capability_registry_receipt.v1` — probe/diff/record (added/removed/changed/candidate counts).
- `atlas.hermes.capability_invocation_receipt.v1` — por invocacao: `resolved[]`, `dropped[{id,reason}]`, `emitted_toolsets`, `manifest_version`, `status`.

Razoes de drop (fail-closed): `unknown_capability`,
`unsupported_by_hermes_manifest`, `not_in_policy_allowlist`,
`blocked_by_permission_mode`, `requires_config_yaml_not_present`,
`policy_disabled`.

## Missao e Invocacao

`HermesExecutiveMissionFactory` agora sela um bloco `capabilities`
(`atlas.hermes.mission_capabilities.v1`) dentro do `mission_hash`, com
`requested_capability_ids` (lista plana `CLASS:key`). Em `runStreaming`, o
`HermesCapabilityInvocationBuilder.apply(args, capabilities, manifest, policy,
permissionMode)` roda de forma **aditiva e default-safe**: com
`capability_policy.enabled=false` (padrao) ele nao altera os args (status
`policy_disabled`) e o caminho legado de `--toolsets` permanece intacto. Quando
ligado, so emite capacidades presentes no manifest E na allowlist E permitidas
pelo modo.

## Governanca (Soberania Atlas)

- **Atlas aprova antes de habilitar**: probe/registry so observam e quarentenam.
- **Default-safe**: `config atlas.ai.providers.hermes_cli.capability_policy` nasce `enabled=false`, `allow=[]`.
- **Fail-closed**: capacidade ausente do manifest ou nao aprovada -> zero flags + entrada em `dropped[]`; nunca chega ao binario.
- **Reversivel**: remover o id da allowlist (ou dropar as tabelas) desliga sem afetar o caminho legado.
- **Evidence**: `manifest_hash` + `capability_invocation` recibo no `ProviderUsagePayload` e `cli_invocation`, provando qual conjunto de capacidades governou cada run.
- **Sem modelo/rede no probe**; segredos do config.yaml (env/oauth/headers) gravados so como presenca/hash, nunca valores.

## Implementacao Atual

Keystone implementado e testado: `HermesCapabilityProbe`,
`HermesCapabilityRegistry` (+ models `HermesCapabilityManifest` /
`HermesCapabilityCandidate` + migrations), `HermesMissionCapabilitiesFactory`,
`HermesCapabilityInvocationBuilder`, comando `atlas:hermes:capabilities`
(probe/diff/candidates, `--write`), wiring default-safe no `HermesCliProvider` e
projecao no `ProviderUsagePayload`. Testes unitarios cobrem parse do manifest,
diff/quarentena, resolucao fail-closed e o comando.

Consumidores governados (adapters MCP, delegation, skills) plugam no manifest
como read model + allowlist; hooks->evidence e fase seguinte.

## Escopo de Implementacao

| Area | DoD |
|---|---|
| Probe | introspecta Hermes read-only, manifest selado, fail-soft |
| Registry | versiona + diff + CapabilityCandidate quarentenado |
| Builder | mapeia pedido -> flag so se manifest-present + allowlist + modo |
| Invocacao | default-safe (policy off = no-op); evidence no ProviderUsage |
| Reversibilidade | allowlist off ou drop das tabelas desliga sem afetar caminho legado |

## Dependencias

- `atlas-hermes-executive-runtime` (boundary canonico soberano).
- `atlas-ai-knowledge-governance-system` (docs canonicos como fonte autoral).

## Riscos

- Drift entre o manifest persistido e o Hermes real entre probes — mitigado por probe periodico e `manifest_hash` no recibo de invocacao.
- Uma capacidade de alto risco (mcp_server/hook/delegation) ser aprovada cedo demais — mitigada por `always_quarantine` + gate de habilitacao dedicado.
- `--json` indisponivel mudar o formato de `--help` — mitigado por parse tolerante + `section_status` honesto.

## Exemplos

- Operador habilita um MCP server: o probe detecta `mcp_server:github` -> Registry
  quarentena um CapabilityCandidate -> operador aprova (allowlist) -> a proxima
  missao que pedir a capacidade emite o toolset governado `mcp-github`.
- Hermes ganha uma flag nova de chat: o proximo probe captura como `flag:<nova>` e
  ela so vira utilizavel apos revisao e aprovacao do Atlas.

## Evidencias

- Esta doc canonica e os arquivos em `repo_paths`.
- Recibos `atlas.hermes.capability_registry_receipt.v1` e `atlas.hermes.capability_invocation_receipt.v1` selados via `HermesAdapterReceipt`.
- Testes `tests/Unit/Ai/Hermes/HermesCapability*Test.php` e `tests/Feature/Ai/Hermes/AtlasHermesCapabilitiesCommandTest.php`.
- `ProviderUsagePayload` carrega `capability_manifest_hash` + `capability_invocation_status` para o Evidence Ledger.

## Proximas Acoes

1. `HermesCapabilityEnablementGate` para promover candidato -> allowlist com confirmacao do operador.
2. Probe periodico governado (cron) para diff continuo e captura de drift.
3. Conectar MCP, delegation, skills e hooks ao manifest + allowlist como consumidores plenos.
