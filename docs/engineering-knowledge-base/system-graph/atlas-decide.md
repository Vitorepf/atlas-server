---
id: atlas-decide
type: engineering_knowledge
title: Atlas Decide
status: active
category: kernel
priority: 98
summary: Engrenagem do Kernel que escolhe provider, modelo, autonomia, budget, fallback e contrato antes de qualquer execucao.
tags:
  - atlas
  - kernel
  - decide
  - provider-routing
capabilities:
  - atlas_decide
  - model_selection
  - provider_routing
  - atlas_decide_decision_receipt
decisions:
  - Atlas Decide e dono da escolha de provider/modelo; surfaces exibem a decisao, nao decidem sozinhas.
  - Manual override precisa ser auditado e refletido no Decision Receipt.
  - Em Forge pesado, Atlas Decide deve produzir provider topology com papeis, fallback chain, capacidade, budget e blockers.
  - Fallback de provider nunca pode ser silencioso; deve gerar receipt, evidence e estado visivel.
maintenance:
  - Atualizar quando sinais, fallback, budget, AP-99 ou politica de modelo mudarem.
  - Validar com docs-health apos qualquer alteracao.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
  - docs/engineering-knowledge-base/system-graph/decision-receipt.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-decide
graph_title: Atlas Decide
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
human_name: Atlas Decide
canonical_name: Atlas Decide
technical_name: AtlasDecideService
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/system-graph/atlas-decide.md
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/atlas-decide.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
allowed_changes:
  - Ajustar sinais de decisao quando houver evidencia de performance, custo, latencia ou qualidade.
  - Atualizar fallback chain e politica de override quando os providers evoluirem.
forbidden_changes:
  - Permitir que UI escolha provider/modelo sem Decision Receipt.
  - Declarar provider vencedor sem evidencia, budget e fallback.
depends_on:
  - policy-profile
  - context-builder
  - evidence-loop
flows_to:
  - decision-receipt
unlocks:
  - runtime-executor
governs:
  - provider-routing
  - model-selection
evidence:
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
next_actions:
  - Conectar Decision Receipt v2 aos sinais reais do Atlas Decide.
gear_flow:
  - graph_id: atlas-decide-intent-risk
    name: Intento + risco
    kind: input
    summary: Recebe tipo de tarefa, risco, surface e dominio antes de qualquer escolha de provider.
  - graph_id: atlas-decide-policy-limits
    name: Policy limits
    kind: policy
    summary: Aplica privacidade, permissao, autonomia, budget e limites de ferramentas.
  - graph_id: atlas-decide-context-signals
    name: Contexto + evidencia
    kind: context
    summary: Cruza Context Builder, Evidence Loop, historico de performance e capacidade local.
    gear_flow:
      - graph_id: atlas-decide-context-builder-contract
        name: Context Builder
        kind: context
        summary: Monta pacote de contexto governado para a decisao.
      - graph_id: atlas-decide-evidence-loop-signal
        name: Evidence Loop
        kind: input
        summary: Retorna sinais reais, historico e aprendizado operacional.
      - graph_id: atlas-decide-performance-ledger
        name: Performance Ledger
        kind: decision
        summary: Projeta qualidade, latencia e confiabilidade por provider/modelo.
      - graph_id: atlas-decide-capacity-state
        name: Capacidade + quota
        kind: gate
        summary: Bloqueia ou limita execucao quando quota, rate limit ou budget falham.
  - graph_id: atlas-decide-provider-topology
    name: Provider topology
    kind: decision
    summary: Escolhe provider, modelo, papeis, fallback chain e blocker quando nao ha capacidade.
  - graph_id: atlas-decide-budget-autonomy
    name: Budget + autonomia
    kind: gate
    summary: Define custo estimado, nivel de autonomia, dry-run e necessidade de revisao humana.
  - graph_id: atlas-decide-receipt-output
    name: Decision Receipt
    kind: output
    summary: Emite contrato auditavel para Runtime Executor; sem receipt, runtime nao executa.
  - graph_id: atlas-decide-failure-path
    name: Falha governada
    kind: failure
    summary: Rate limit, quota, timeout ou provider incapaz exigem child receipt ou bloqueio visivel.
visual_tags:
  - module
  - module
  - system-graph

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok
---
# Atlas Decide

## Resumo

Atlas Decide e a engrenagem que transforma contexto, politica, risco e historico de performance em uma decisao executavel. Ele define quem executa, com qual modelo, sob qual autonomia, com qual budget e com qual fallback.

No Atlas Forge Continuum OS, Atlas Decide tambem define a topologia de
execucao: builder principal, reviewer, context scout, repair agent, provider
fallback e blocker `provider_capacity_exhausted` quando nenhum provider capaz
estiver disponivel.

## Papel no Atlas

Ele impede que cada surface vire um seletor manual de provider. Atlas Code, CLI, mobile e MCP podem solicitar execucao, mas a decisao passa pelo Kernel.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Depende de `policy-profile`, `context-builder` e feedback do `evidence-loop`. Alimenta `decision-receipt`, que vira contrato antes do runtime.

## Contratos

Entrada: envelope, contexto, perfil de politica, sinais de performance, risco, budget e disponibilidade de providers. Saida: decisao com provider, modelo, papel, fallback chain, autonomia, custo estimado, capacidade, blocker quando necessario e justificativa auditavel.

## Fluxo

Policy/Profile e Context Builder chegam ao Decide. Evidence Loop calibra historico. Decide escolhe rota e entrega ao Decision Receipt.

## Regras para IA

IA nao pode escolher provider por preferencia local. Deve consultar o contrato de modelo e registrar override quando a escolha for humana.

Em Forge, IA nao pode trocar provider apos rate limit, quota, timeout ou erro
sem registrar fallback governado. Se nao houver provider capaz, deve bloquear
com `provider_capacity_exhausted`, nao degradar a tarefa silenciosamente.

Para `surface=atlas_code` ou `flow=programming.forge`, Atlas Decide deve
materializar `forge_provider_topology` dentro do Decision Receipt. Essa topologia
e a autoridade runtime (`decision_source=live_atlas_decide`): inclui papeis,
provider/modelo, fallback chain, quality gates, budget decision, receipt id/hash
e `runtime_dispatch_allowed`. A policy estatica (`decision_source=static_policy`)
e permitida apenas como read-model de certificacao quando nao ha receipt real.
Qualquer reroute executavel apos falha de provider exige child Decision Receipt;
enquanto ele nao existir, `fallback_child_receipt_required=true`.

## Escopo de Implementacao

Permitido: regras de selecao, metricas, fallback e documentacao de sinais. Proibido: provider dropdown livre fora do receipt.

Permitido para Forge Continuum: provider topology, role assignment, fallback
classification e estado visivel para Atlas Code. Proibido: fallback invisivel,
provider menos capaz assumindo tarefa critica sem justificativa, ou completion
baseado apenas em reroute. Tambem e proibido dispatch runtime a partir de
`static_policy`.

## Dependencias

- `policy-profile`
- `context-builder`
- `evidence-loop`
- `atlas-ai-model-selection-strategy`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md`
- `docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md`

## Riscos

- UI escolher provider sem governanca.
- Custo real divergir do estimado.
- Historico AP-99 ficar obsoleto.

## Exemplos

Tarefa de arquitetura pode ir para Claude; implementacao localizada pode ir para Codex; override humano precisa aparecer no receipt.

Tarefa pesada pode usar Claude como primary builder, Codex como critical
reviewer e Gemini como context scout se Atlas Decide justificar essa topologia.
Se Claude bater limite, Atlas Decide pode rerotear para o melhor provider capaz
disponivel; se nenhum existir, o Forge deve bloquear honestamente.

## Proximas Acoes

Mapear os 14 sinais reais usados por Atlas Decide em um payload de Decision Receipt v2.

> Sinais locais de capacidade dos 5 providers runtime (claude_cli, codex_cli,
> gemini_cli, claude_codex, atlas-local) vivem em
> `atlas-forge-provider-capacity-continuity-v1.md` e sao consumidos por Atlas
> Decide via Provider Topology — nenhum probe externo, cooldown e blocker
> `provider_capacity_exhausted` honestos.
