---
id: atlas-dev-patamares
type: engineering_knowledge
title: Atlas Dev Patamares
status: active
category: programming
priority: 100
summary: Mapa canonico dos patamares conceituais do Atlas Dev. Cada patamar tem identidade propria (nao "v1/v2"), frase de capability, pre-requisitos, componentes e criterio de prontidao. Define ponto de partida historico (A0), patamar em construcao atual (A1-A2), patamares planejados proximos (A3-A5) e patamares futuros (A6-A7). Modelo segue patamares-l0-l5 das Obras. Mudanca de patamar exige AP + bump documental + nova identidade conceitual.
tags:
  - atlas-dev
  - patamares
  - maturity-ladder
  - capability-evolution
capabilities:
  - atlas_dev_maturity_ladder
  - capability_achievement_definitions
  - evolution_navigation
decisions:
  - Atlas Dev evolui em patamares com identidade conceitual, NUNCA em versoes numericas sequenciais.
  - Patamar atual em construcao e A1 (Atlas Dev Foundation); proximo planejado e A2 (Atlas Dev Plan Visible).
  - Cada patamar tem frase de capability simples; complexidade interna nao define patamar.
  - MVP de qualquer patamar pode ser pequeno, mas ontologia nao pode bloquear patamares superiores.
maintenance:
  - Atualizar quando patamar virar pronto (mudar status passado/em construcao/planejado).
  - Adicionar patamar novo apenas quando AP + revisao humana aprovarem identidade conceitual.
  - Manter abaixo de 260 linhas.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-index.md
  - docs/engineering-knowledge-base/atlas-dev-glossary.md
  - docs/engineering-knowledge-base/atlas-dev-policy.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/obras/patamares-l0-l5.md
  - docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-patamares
graph_title: Atlas Dev Patamares
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-dev-index
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-patamares.md
allowed_changes:
  - Atualizar status de patamar (passado/em construcao/planejado/futuro).
  - Adicionar patamar novo no fim da ladder quando AP aprovar.
  - Refinar frase de capability quando ambiguidade emergir.
forbidden_changes:
  - Inserir patamar entre patamares existentes sem AP + bump documental.
  - Renomear patamar existente sem AP.
  - Trocar identidade conceitual de patamar (renomear, mudar capability) sem AP.
  - Introduzir "v2", "v3" como nomes de patamar; patamar tem identidade propria.
depends_on:
  - atlas-dev-index
  - atlas-dev-policy
  - atlas-ai-evolution-roadmap
flows_to:
  - atlas-dev-efficient-programming-flow-v1
unlocks:
  - atlas_dev_evolution_clarity
  - capability_achievement_tracking
governs:
  - atlas_dev.patamares
  - atlas_dev.evolution_ladder
evidence:
  - docs/engineering-knowledge-base/atlas-dev-patamares.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - patamares
  - ladder
  - atlas-dev
ai_entrypoints:
  - Leia este doc apos atlas-dev-policy. Identifica qual patamar Atlas Dev esta hoje antes de implementar.
ai_usage_notes:
  - Patamar atual define escopo do trabalho. Implementar feature de patamar futuro hoje = anti-padrao.
  - Cada patamar tem componentes proprios. Nao misturar componentes de patamares diferentes na mesma fatia.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - IA implementa feature de patamar A4 enquanto A1 ainda nao esta completo. Resultado: fundacao instavel.
  - IA confunde patamar conceitual com versao tecnica de arquivo. Resultado: arquivos -v1/-v2 sem sentido.
observability_signals:
  - docs-health status ok
  - patamar atual coerente com runbook progress
next_actions:
  - Promover A1 para "passado" quando Marco 1 do runbook for cert verde.
  - Adicionar patamares futuros conforme AP novo aprovar.
line_limit: 260
---
# Atlas Dev Patamares

Patamares conceituais do Atlas Dev. Identidade propria, nao "v1/v2". Cada patamar tem frase de capability + componentes + criterio de prontidao.

## A0 — Atlas Dev Conversation

**Frase**: "Operador usa Atlas via chat com engine cru sem spec, scope ou receipt."

**Status**: passado (estado historico antes da equipe Criacao comecar).

**Componentes**:
- `AtlasCliDevCommand` + `AiChatCommand --dev`
- Open Brain context injection auto
- Provider escolhido manualmente
- Sem mini-spec, sem task contract, sem scope guard, sem verification receipt

**Limitacoes**:
- Sem governance proporcional. Sem evidence. Sem replay.
- IA pode tocar qualquer arquivo. "Tente de novo" como repair.
- Sem distincao Atlas AI vs Atlas Dev vs Forge.

**Por que mudou**: tese de fluxo governado dentro de Atlas AI exige spec + scope + verification + receipt. Sem isso, "Atlas Dev" e provider com branding.

## A1 — Atlas Dev Foundation

**Frase**: "Schemas, Discovery, Prompt Projection, Telemetry e Persistence canonicos prontos."

**Status**: **em construcao** (Marco 1 do runbook).

**Pre-requisito**: A0 passado + decisoes locked do `atlas-dev-efficient-programming-flow-v1`.

**Componentes**:
- 17 DTOs read-only em `app/Services/Ai/Programming/AtlasDev/Schemas/` (4 camadas: Plano + Contexto + Receipt + Telemetria — inclui `ProviderCallResult`, `DiffParseResult`, `PatchApplyResult` além dos 14 originais)
- `DocContextTierSelector` + `CodeDiscoveryEngine` + `OpenBrainProjectionAdapter` em `Discovery/`
- `ProviderPromptBuilder` + `PromptQualityChecker` em `PromptProjection/`
- `TelemetryEmitter` + `ErrorLedgerWriter` em `Telemetry/`
- `ReceiptStorage` + persisters dos 17 artefatos em `Persistence/`
- `AtlasDevEfficientFlowServiceProvider` registrado em `bootstrap/providers.php`

**Ready when**:
- `composer test --filter=AtlasDev/(Schemas|Discovery|PromptProjection|Telemetry|Persistence)` verde
- Coverage >= 95% nas pastas
- `no_rivals_leakage_tests` + `no_surface_leakage_tests` + `no_template_pollution_tests` verdes
- `docs-health` verde
- Comando `atlas:dev:debug:smoke` (interno) produz artefatos validos sem provider call

**Capability achievement**: "Atlas Dev tem fundacao canonica para Plan-only e Run reais."

## A2 — Atlas Dev Plan Visible

**Frase**: "Operador ve plan-only completo no Atlas AI Desktop antes de gastar token."

**Status**: **planejado proximo** (Marco 2 do runbook).

**Pre-requisito**: A1 cert verde.

**Componentes**:
- `IntakeNormalizer` + `TaskClassifier` + `RiskLevelScorer` + `SpecComposer` + `RoutingDecisionEngine` em `Pipeline/`
- `AtlasDevFastPathOrchestrator::planOnly()`
- `AtlasDesktopAiAdapter` em `Surface/`
- Endpoint `POST /ai/interactions/atlas-dev/plan` retornando 11 artefatos + `routing_decision` + `ui_hints`
- Frontend Desktop renderiza painel Plano + painel Contexto + inline indicators
- Migration `atlas_dev_run_index` (lightweight)

**Ready when**:
- Endpoint `/atlas-dev/plan` retorna response valida em 3 cenarios: fast_path, read_only, forge_promotion
- Desktop renderiza Plano + Contexto sem regredir chat existente
- Feature flag `atlas_dev_efficient_plan_enabled` ON em dev
- Smoke teste: operador ve plano de tarefa real sem custo de token

**Capability achievement**: "Operador inspeciona plano governado antes de qualquer chamada provider."

## A3 — Atlas Dev Provider Verified

**Frase**: "Atlas Dev executa uma chamada provider locked com Scope Guard, Verification e Receipt persistido."

**Status**: **planejado** (Marco 3 do runbook).

**Pre-requisito**: A2 cert verde.

**Componentes**:
- `SonnetClaudeCliAdapter` + `DiffParser` em `Provider/`
- `ScopeGuard` + `VerificationCommandRunner` + `VerificationGate` + `CompletionStateGate` + `ReceiptComposer` em `Gate/`
- `AtlasDevFastPathOrchestrator::patch()`
- Endpoint `POST /ai/interactions/atlas-dev/run` com confirmation_token single-use
- Endpoint `GET /ai/interactions/atlas-dev/runs/{run_id}/stream` (SSE primario)
- Endpoint `GET /ai/interactions/atlas-dev/runs/{run_id}` (REST fallback)
- Frontend Desktop renderiza diff viewer + tests panel + receipt expandido + phase progress

**Ready when**:
- Smoke teste manual: 5 tarefas seguras (R1) passam end-to-end
- VerificationReceipt persistido com hashes validos
- SSE stream entrega fases em tempo real
- Provider lock enforced (sem fallback escondido)
- Feature flag `atlas_dev_efficient_run_enabled` ON em dev

**Capability achievement**: "Atlas Dev produz patch governado com evidence verificavel sem council nem fallback."

## A4 — Atlas Dev Self-Healing

**Frase**: "Atlas Dev se cura via repair loop barato com erro real, escala honesta quando repair nao converge."

**Status**: **planejado** (Marco 4 do runbook).

**Pre-requisito**: A3 cert verde.

**Componentes**:
- `FailureCapsuleBuilder` + `FailureSignatureHasher` + `RepairPromptComposer` + `RepairOrchestrator` em `Repair/`
- Loop de repair integrado ao `AtlasDevFastPathOrchestrator::patch()`
- Limites por R-level enforcados (R1=0-1, R2=1, R3=1-2 repairs)
- SSE stream com eventos `repair_planned`, `repair_executing`, `repair_finished`
- Frontend Desktop renderiza failure capsule modal + repair attempts tracker

**Ready when**:
- 3 cenarios cobertos: first-attempt-pass, fail-then-repair-pass, two-fails-same-signature-escalate
- Failure capsules persistidos como `failure_capsule.<n>.json`
- Receipt com `repair.attempt_count` correto
- Telemetria registra `repair_attempts > 0` quando aplicavel
- Mesma `failure_signature` 2x => abort + escalate enforcado

**Capability achievement**: "Atlas Dev recupera de falhas via erro real, sem council nem fallback, escalando honestamente para Forge quando preciso."

## A5 — Atlas Dev Surface Parity

**Frase**: "Atlas Dev disponivel em Desktop + CLI + App + API via adapters thin."

**Status**: **planejado** (Marco 5 do runbook).

**Pre-requisito**: A4 cert verde.

**Componentes**:
- `EscalationSignalScorer` + `EscalationDecisionEngine` + `ForgePromotionPreviewBuilder` em `Escalation/`
- Surface adapters (cada um < 200 LOC): `AtlasCliDevAdapter`, `AtlasAppAdapter`, `AtlasApiInteractionAdapter` (Desktop ja em A2)
- `AtlasCliDevWorkflowService::runAtlasDevEfficient()` evoluido com flag `--efficient`
- Cert local `atlas_dev_efficient_flow` marcado `available`
- Botao "promover -> Forge" funcional no Desktop

**Ready when**:
- 4 surfaces respondem usando o mesmo orchestrator
- Feature tests por surface verdes
- Doc principal status `draft -> building` (ou `active` quando todos os marcos verdes)
- Memoria provider-safe atualizada
- Esta equipe **encerra** transferindo para equipe de medicao com cert available

**Capability achievement**: "Operador opera Atlas Dev de qualquer surface com paridade de funcionalidade e governance."

## A6 — Atlas Dev Adaptive Engine

**Frase**: "Atlas Dev opera com Atlas Decide ativo escolhendo o melhor engine por categoria de tarefa."

**Status**: **futuro** (pos-A5).

**Pre-requisito**: A5 cert verde + Atlas Decide ativo para Programming domain.

**Componentes futuros**:
- `LightTaskContract.policy_profile.decision_mode` migra de `manual_override` para `auto_best_allowed`
- Provider Performance Ledger consultado entre runs para escolher engine
- Variantes futuras: `atlas_dev_codex`, `atlas_dev_gemini` reutilizam o mesmo contract
- Fallback degenerado em caso de erro/limite/saldo (engine alternativo dentro do mesmo run NAO; entre runs SIM)

**Ready when**: a definir via AP novo quando Atlas Decide ativar Programming.

**Capability achievement**: "Atlas Dev herda salto de qualquer engine automaticamente via Atlas Decide."

## A7 — Atlas Dev Continuous Learning

**Frase**: "Atlas Dev aprende via FastPathErrorLedger -> Programming Curator -> Proposal Inbox -> Human Review."

**Status**: **futuro distante** (pos-A6).

**Pre-requisito**: A6 cert verde + Programming Curator do dominio Self-Improvement maduro.

**Componentes futuros**:
- `Programming Curator` operacional
- Proposal Inbox com revisao humana
- Atlas Dev ajusta thresholds e heuristicas via proposals aprovadas (NAO auto-aplica)
- Telemetria + ErrorLedger virando learning real

**Ready when**: a definir via AP novo quando Curator maduro.

**Capability achievement**: "Atlas Dev evolui de forma governada via human-in-the-loop."

## Final Level Definitions

- **A0**: "operador usa engine cru com branding Atlas"
- **A1**: "Atlas Dev tem fundacao canonica"
- **A2**: "operador ve plano antes de gastar token"
- **A3**: "Atlas Dev produz patch governado com evidence"
- **A4**: "Atlas Dev se cura sem council nem fallback"
- **A5**: "Atlas Dev disponivel em todas as surfaces com paridade"
- **A6**: "Atlas Dev herda salto de engine automaticamente"
- **A7**: "Atlas Dev evolui via human-in-the-loop"

## Mudanca De Patamar (Processo)

Cada salto de patamar exige:

1. AP novo aprovado (Architectural Proposal).
2. Frase de capability nova definida no AP.
3. Componentes do patamar implementados e testados.
4. Cert local verde para o patamar anterior.
5. Bump de status neste doc (planejado -> em construcao -> passado).
6. Doc do patamar anterior NAO e deletado; vira referencia historica via `archive/` se preciso.

Mudanca de patamar NAO usa "v2", "v3". Cada patamar tem nome proprio descritivo. Sufixo `-v1/-v2` em arquivo e convencao tecnica de snapshot dentro do mesmo patamar.

## Resumo

Mapa canonico dos patamares do Atlas Dev. A0 passou; A1 em construcao; A2-A5 planejados nos marcos do runbook; A6-A7 futuros. Cada patamar tem identidade propria, frase de capability e criterio de prontidao.

## Papel no Atlas

Garante que toda IA identifica em qual patamar Atlas Dev opera hoje antes de implementar. Impede que feature de patamar futuro entre antes da fundacao do patamar atual.

## Onde Se Encaixa

Filho de `atlas-dev-index`. Irmao de `atlas-dev-glossary` e `atlas-dev-policy`. Modelo segue `obras/patamares-l0-l5.md`. Patamar atual da escopo a `atlas-dev-efficient-programming-flow-v1`.

## Contratos

- Cada patamar tem identidade propria; nao "v1/v2".
- Mudanca de patamar exige AP novo + bump documental.
- Implementar feature de patamar futuro hoje = anti-padrao.

## Fluxo

IA le este doc -> identifica patamar atual -> implementa feature do patamar atual -> quando patamar virar pronto, status muda aqui.

## Regras para IA

- Identificar patamar atual antes de implementar.
- Nao misturar componentes de patamares diferentes na mesma fatia.
- Reportar se feature pedida pertence a patamar futuro.

## Escopo de Implementacao

Manutencao deste doc + status dos patamares. Detalhes de implementacao vivem no runbook do patamar atual.

## Dependencias

- `atlas-dev-index` (entrypoint)
- `atlas-dev-policy` (invariants)
- `atlas-ai-evolution-roadmap` (roadmap global Atlas)
- `obras/patamares-l0-l5` (modelo de patamares)

## Evidencias

- Existencia deste doc.
- Cert local de cada patamar quando virar pronto.
- Runbook do patamar atual com Marcos alinhados.

## Riscos

- IA implementar feature de A4 enquanto A1 nao esta verde.
- Confundir patamar conceitual com versao tecnica de arquivo.
- Renomear patamar sem AP.

## Exemplos

Valido: A1 em construcao. IA implementa schemas + validators. Marca A1 como passado quando cert verde.

Invalido: A1 em construcao. IA pula direto para Repair Loop (A4). Resultado: fundacao incompleta, repair sem base.

## Proximas Acoes

- Marcar A1 como "passado" quando Marco 1 cert verde.
- Iniciar A2 quando A1 passado.
- Adicionar patamar A8+ quando AP futuro aprovar.
