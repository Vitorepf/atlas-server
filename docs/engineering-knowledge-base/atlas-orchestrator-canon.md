---
id: atlas-orchestrator-canon
type: engineering_knowledge
title: Atlas Orchestrator Canon
status: active
category: architecture
priority: 97
summary: Mapa mental unico da espinha de execucao e roteamento do Atlas em cinco camadas (Hyperflow, Decision Core, Workcell Fabric, Proof Loop, Learning Loop), com a regra petrea role slot diferente de runtime identity, a Cognitive Pressure Layer de papeis cognitivos, e os tetos honestos. E canon/mapa sobre orgaos que ja existem, nao um sistema novo nem um big-refactor.
implementation_state: partial_organs_exist_broad_learning_loop_unproven
macro_layer: false
human_name: Canon do Atlas Orchestrator
canonical_name: Atlas Orchestrator Canon
technical_name: atlas-orchestrator-canon
cartography_type: architecture
canonical_source: docs/engineering-knowledge-base/atlas-orchestrator-canon.md
owner: architecture
tags:
  - atlas-ai
  - orchestrator
  - canonical-architecture
  - provider-neutral
  - cognitive-pressure-layer
  - role-slot
capabilities:
  - orchestrator_layer_canon
  - provider_neutral_naming
  - role_slot_runtime_binding
  - cognitive_pressure_layer_plan
decisions:
  - As cinco camadas canonicas sao Hyperflow (para onde vai), Decision Core (quem/qual runtime/modelo/topologia), Workcell Fabric (celula provider-neutral), Proof Loop (real vs fake-green), Learning Loop (aprende para a proxima).
  - As cinco camadas mapeiam 1 para 1 em orgaos que JA existem; isto e canon/mapa, nao um sistema novo. NAO fazer big-refactor de unificacao (refutado pela Obra #6 e pelo kernel-unification-map).
  - Regra petrea role slot diferente de runtime identity - nenhuma camada de arquitetura leva nome de provider; o runtime que ocupa um papel e substituivel (hoje Hermes carrega GLM/Kimi/Minimax; amanha pode ser outro).
  - Workcell Fabric e o AAWR (AtlasAgenticWorkcellRuntimeService), ja provider-neutral e com role_roster. NAO criar camada nova; reusar. Hermes Executive Mesh e um runtime adapter que roda SOB a Workcell Fabric, nao um par dela nem uma camada de arquitetura.
  - Learning Loop parcial - o ADML ja consome ledger de outcome vivo por (task_category, role) com auto-deativacao de rota degradada, mas a auto-ativacao e flag-gated default-OFF; o que falta e o loop AMPLO unificado Dev/Forge/Product OutcomeMemory para o Decision Core provado como sistema.
  - A Cognitive Pressure Layer sao 8 papeis cognitivos (nao 28); comecar por 3 (context_cartographer, boundary_wiring_guard, runtime_verifier) com sinal de outcome ja vivo.
  - Teto honesto - a sociedade cognitiva nao ultrapassa o juiz mais forte; largura (modelos mid abundantes) multiplica verificacao, nao profundidade (frontier igual juiz). O multiplicador e o loop fechado, nao o tamanho do elenco; renomear camada rende aproximadamente zero de capacidade.
maintenance:
  - Atualizar quando uma camada, um papel da Pressure Layer, ou o binding role-slot para runtime mudar.
  - NAO permitir que qualquer camada volte a carregar nome de provider.
  - NAO promover isto a big-refactor; capturar valor por slice reversivel com prova.
depends_on:
  - atlas-ai-canonical-architecture-index
  - atlas-agentic-workcell-runtime
flows_to:
  - atlas-agentic-workcell-runtime
  - atlas-hermes-executive-mesh
unlocks:
  - cognitive-pressure-layer
governs:
  - architecture
evidence:
  - docs/engineering-knowledge-base/atlas-orchestrator-canon.md
evidence_refs:
  - symbol: AtlasAiRouterService
  - symbol: AtlasDecideService
  - symbol: AtlasAgenticWorkcellRuntimeService
  - symbol: AtlasDecideMetaLearningService
  - command: atlas:engineering:knowledge docs-health
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: low
next_actions:
  - Fechar e provar o loop amplo Dev/Forge/Product OutcomeMemory para o Decision Core como sistema unificado.
  - Implementar os 3 guardas iniciais da Pressure Layer sobre o role_roster do AAWR.
  - Aposentar Hermes Mesh como nome de camada, dobrando no AAWR por slice reversivel.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-workcell-runtime.md
  - docs/engineering-knowledge-base/atlas-hermes-executive-mesh.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-hyperflow-certification-runbook-v1.md
  - docs/obra-linha-acos-fechamento-2026-07-07.md
  - app/Services/Ai/Router/AtlasAiRouterService.php
  - app/Services/Ai/AtlasDecide/AtlasDecideMetaLearningService.php
  - app/Services/Ai/AgenticWorkcell/AtlasAgenticWorkcellRuntimeService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-orchestrator-canon
graph_title: Atlas Orchestrator Canon
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-orchestrator-canon.md
  - app/Services/Ai/Router/AtlasAiRouterService.php
  - app/Services/Ai/AtlasDecide/AtlasDecideMetaLearningService.php
  - app/Services/Ai/AgenticWorkcell/AtlasAgenticWorkcellRuntimeService.php
allowed_changes:
  - Adicionar papeis a Pressure Layer com falha real mais sinal de outcome; refinar o binding role-slot para runtime.
forbidden_changes:
  - Dar nome de provider a qualquer camada de arquitetura.
  - Fazer big-refactor de unificacao das superficies de orquestracao.
  - Creditar capacidade a um rename; capacidade vem de Proof mais Learning fechados.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Tratar o canon como big-refactor em vez de mapa mais slice.
  - Confundir largura (modelo mid abundante) com profundidade (juiz frontier).
observability_signals:
  - docs-health status ok
---
# Atlas Orchestrator Canon
Mapa mental unico da espinha de execucao e roteamento do Atlas. Canon sobre orgaos que ja existem, nao um sistema novo.

## Resumo
O Atlas Orchestrator descreve a espinha de execucao/roteamento em cinco camadas com responsabilidade limpa: `Hyperflow -> Decision Core -> Workcell Fabric -> Proof Loop -> Learning Loop`. As cinco mapeiam 1 para 1 em orgaos ja implementados; por isso o canon e fiel, nao fantasia. E vocabulario/mapa mais slices reversiveis, NAO um big-refactor. A convergencia foi cross-model em 2026-07-07.

## Papel no Atlas
Da intencao ate a entrega, cada decisao importante passa por cinco funcoes:

```text
Hyperflow        -> para onde vai (rota: Dev / Forge / pesquisa / decisao)
Decision Core    -> quem faz quais papeis; runtime / modelo / topologia
Workcell Fabric  -> monta e opera a celula provider-neutral de role slots
Proof Loop       -> isto e real ou fake-green?
Learning Loop    -> o que melhora na proxima vez
```

## Onde Se Encaixa
Cada camada e um orgao real (glossario dos termos Dev/Forge em `atlas-canonical-glossary-and-naming.md`):

| Camada | Orgao real |
|---|---|
| Hyperflow | `AtlasAiRouterService` / Hyperflow certification runbook |
| Decision Core | `AtlasDecideService` |
| Workcell Fabric | AAWR - `AtlasAgenticWorkcellRuntimeService` (`role_roster`, provider-neutral) |
| Proof Loop | gates mais Evidence Ledger mais Sovereign Acceptance Gate |
| Learning Loop | `OutcomeMemory` mais `AtlasDecideMetaLearningService` |

Dev, Forge e Autonomos ja rodam sobre estes cinco orgaos; o canon so nomeia de forma consistente uma espinha ja compartilhada.

## Contratos
- `role slot != runtime identity` - nenhuma camada de arquitetura leva nome de provider; o runtime que ocupa um papel e substituivel.
- Workcell Fabric e o AAWR existente. Reusar, nao recriar. Hermes Executive Mesh e um runtime adapter SOB a Workcell Fabric.
- Reuse-first: nenhum orgao novo quando um ja existe. Sem big-refactor de unificacao.
- VETO de orgao orfao: produtor mais consumidor no mesmo slice ou nao landa.

## Fluxo
```text
intencao
-> Hyperflow escolhe a rota
-> Decision Core atribui papeis e runtime por papel
-> Workcell Fabric monta e opera a celula (role slots)
-> Proof Loop prova real vs fake-green (gates mais evidencia)
-> Learning Loop pesa o outcome e ajusta a proxima decisao
```

## Regras para IA
- Os 8 pontos da sintese: canon das 5 camadas; matar nome Hermes Mesh; nao criar Workcell novo (reusar AAWR); nao big-refactor; fechar/provar o loop amplo de Learning; endurecer o Proof; Pressure Layer em 3 papeis iniciais; runtime abundante e pressao, nao arquitetura.
- Ordem: pontos 5 e 6 (Learning mais Proof) NAO sao trabalho novo - sao o fechamento em voo; nao forke. Pontos 1/2/3/8 (canon mais rename) sao obra barata. Ponto 7 (Pressure Layer) vem depois.
- Os 3 guardas rendem de 1a ordem na hora (bloqueiam land ruim mesmo com o loop amplo ainda nao provado); o loop amplo da o ganho composto.

## Escopo de Implementacao
- Fase 0 (em voo) - fechamento da linha ACOS: endurece o Proof, avanca o Learning e faz o instrumento TPE. E os pontos 5 e 6.
- Fase 1 (barata, hygiene) - canonizar as 5 camadas (este doc, ja feito) mais aposentar Hermes Mesh dobrando no AAWR por slice reversivel. Rende clareza, nao capacidade.
- Fase 2 (alavanca) - fechar e PROVAR o loop amplo unificado Dev/Forge/Product OutcomeMemory para o Decision Core. Parte por (task_category, role) ja existe e e testada; falta a unificacao cross-surface e a ativacao em regime.
- Fase 3 - Pressure Layer: 3 guardas sobre o role_roster do AAWR; expandir para os outros 5 papeis so por evidencia.

## Dependencias
- `atlas-ai-canonical-architecture-index` (raiz de resolucao de conflito).
- `atlas-agentic-workcell-runtime` (AAWR e a camada Workcell Fabric).
- `atlas-hermes-executive-mesh` (runtime adapter sob a Workcell Fabric).
- `AtlasAiRouterService`, `AtlasDecideService`, `AtlasDecideMetaLearningService`, `OutcomeMemory` (Dev/Forge/Product).

## Evidencias
- Workcell provider-neutral com role_roster: `AtlasAgenticWorkcellRuntimeService` (role_roster nasce no runtime).
- Roteamento por outcome vivo por (task_category, role) mais auto-deativacao: `AtlasDecideMetaLearningService` (`liveEvidenceSignal`, `autoActivateFromLiveEvidence`, `AtlasDecideLiveOutcomeFeedbackService`); auto-ativacao flag-gated `adml_auto_activation_enabled` default-OFF. Testado por `AtlasDecideMetaLearningServiceTest` e `AtlasDecideLiveEvidenceActivationTest`.
- Gate deste doc: `php artisan atlas:engineering:knowledge docs-health --json`.

## Riscos
- A sociedade cognitiva NAO ultrapassa o teto do juiz mais forte. Largura (modelos mid abundantes) multiplica verificacao, nao profundidade (frontier igual juiz). Por um mid como arbitro final de decisao profunda derruba o teto.
- O multiplicador e o loop fechado (critica -> veredito -> peso -> proxima decisao), NAO o tamanho do elenco nem o nome das camadas. Renomear rende aproximadamente zero de capacidade.
- Gates de valor (janela viva) precisam de dias de dados reais; codigo completo nao e prova. Nao fabricar numero.
- Confundir mapa com big-refactor: o valor esta em 1 slice fino, nao em unificar 230 arquivos (refutado pela Obra #6).

## Exemplos
Uma celula (Workcell) com papeis estaveis e runtime substituivel:

```text
workcell:
  planner:         <runtime disponivel>
  executor:        <runtime disponivel>
  pressure_critic: <runtime disponivel>
  verifier:        <runtime disponivel>
```

Pressure Layer - 8 papeis (previne / sinal / tier / quando). Comecar por 3: `runtime_verifier` (invocacao-de-orgao maior que 0, mata orfao), `context_cartographer` (simbolo resolve no code index, mata alucinacao), `boundary_wiring_guard` (diff dentro do blast-radius, mata edicao fora de escopo). Alocacao: WIDTH nos modelos mid abundantes; DEPTH no juiz frontier.

## Proximas Acoes
- Fechar e provar o loop amplo Dev/Forge/Product OutcomeMemory para o Decision Core.
- Implementar os 3 guardas iniciais da Pressure Layer sobre o role_roster do AAWR.
- Aposentar Hermes Mesh como nome de camada, dobrando no AAWR por slice reversivel.
