---
id: atlas-frontier-evolution-foundry
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Frontier Evolution Foundry (AFEF)
slug: atlas-frontier-evolution-foundry
status: future
implementation_state: future_spec_no_runtime_yet
category: agentic-engineering
priority: 95
summary: >
  Capability futura que deixa o loop de stewardship GERAR o proprio backlog de
  evolucao quando o backlog existente e honestamente esgotado, em vez de parar ou
  fabricar filler. AFEF e proposal-only e blindada por nove invariantes nao
  burlaveis (evidencia verificavel obrigatoria, metrica falsificavel, juiz
  independente, sem auto-canonizacao, medido-ou-revertido, anti-duplicacao,
  decomposicao bounded, gate de budget/raridade, contencao de drift). Consome o
  admission bridge, o canonical backlog compiler, os operator decision receipts e
  o Evidence Ledger existentes; nao cria OS, runtime nem provider novo.
tags: [atlas-ai, software-company, frontier-evolution-foundry, self-construction, area-focus-loop]
capabilities: [frontier_evolution_foundry, self_evolving_backlog_compiler, evolution_proposal_generation, adversarial_proposal_adjudication, measured_or_reverted_evolution]
decisions:
  - AFEF nunca escreve codigo de producao nem doc canonico; so emite artefatos de proposta que atravessam a armadura e o operador.
  - O passo proposta para canonico exige decisao humana com receipt ate a Foundry ter historico de acerto comprovado (I4).
  - Toda evolucao implementada e medida; nao melhorou na tolerancia entao git revert automatico e proposta vira refuted_by_reality (I5).
  - AFEF consome admission bridge, canonical backlog, operator decision e Evidence Ledger existentes; proibido criar OS/runtime/provider paralelo.
maintenance:
  - Atualizar antes de mudar qualquer invariante, estagio do pipeline, schema foundry.* ou ordem de build.
  - Bloquear quando IA tentar auto-canonizar, pular evidencia/metrica/juiz independente, ou alegar implementacao sem AP entregue e medido.
risk_level: high
owner: agentic_engineering_os/dev_forge
graph_id: atlas-frontier-evolution-foundry
graph_title: Atlas Frontier Evolution Foundry
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-software-company-stewardship-stack
graph_status: future
graph_source: repo
depends_on: [atlas-software-company-stewardship-stack, atlas-ai-self-construction-os, atlas-autonomy-admission]
flows_to: [atlas-software-company-stewardship-stack]
unlocks: [self_generated_evolution_backlog, measured_compounding_self_improvement]
governs: [evolution_proposals, foundry_roadmap, evolution_outcomes]
authority_class: proposer
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/ap/AP-806-loop-autonomy-certification-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusFactoryMaxCanonicalBacklogService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusSelfConstructionAdmissionBridgeService.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-frontier-evolution-foundry.md
evidence:
  - docs/engineering-knowledge-base/atlas-frontier-evolution-foundry.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusFactoryMaxCanonicalBacklogService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusSelfConstructionAdmissionBridgeService.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Entregar AP-A (schemas foundry.* + Evidence Harvester/Verifier) antes de qualquer geracao.
allowed_changes:
  - Refinar invariantes, estagios do pipeline, schemas e ordem de build mantendo proposal-only e os 9 gates.
forbidden_changes:
  - auto_canonize_proposal
  - skip_evidence_or_metric_or_independent_judge
  - create_parallel_os_runtime_or_provider
  - count_unmeasured_improvement_as_success
requires_evidence: false
line_limit: 520
schema:
  - atlas.foundry.evolution_proposal.v1
  - atlas.foundry.proposal_verdict.v1
  - atlas.foundry.evolution_outcome.v1
  - atlas.foundry.roadmap.v1
---

# Atlas Frontier Evolution Foundry (AFEF)

## Resumo

Hoje o loop de stewardship **executa** um backlog curado e, quando esgota o
trabalho admissivel, **para honestamente** (AP-806 recusa filler). A AFEF adiciona
um poder novo: quando o backlog e honestamente esgotado, o loop entra em um **modo
arquiteto** que GERA o proprio backlog de evolucao — propostas avancadas de
auto-melhoria — que o operador promove em findings canonicas e os ciclos seguintes
implementam.

A grandeza nao e "gerar docs avancadas". Um modelo forte gera auto-melhoria
plausivel, bonita e inutil para sempre; essa e a versao MACRO do filler e e o maior
risco do sistema. A grandeza e **planejamento multi-horizonte em que cada passo e
provado multiplicar capacidade — ou e revertido.** AFEF compoe capacidade
*demonstrada*, nunca *alegada*.

> Regra inviolavel: nada entra no futuro da fabrica sem evidencia verificavel,
> metrica falsificavel, juiz independente e rollback medido.

## Papel no Atlas

AFEF e a camada que transforma a fabrica de "executora de backlog" em "fabrica que
cria o proprio futuro", dentro do Software Company Stewardship Stack e consumindo o
Self-Construction OS e o ACOS. Ela nao e um segundo cerebro nem um runtime
paralelo: e um **proposer** governado que so produz artefatos de proposta. A
autoridade estrategica ("no que a fabrica deve se tornar") permanece com o operador
(cerebro); os providers sao musculo. AFEF existe para sustentar antifragilidade
composta: quanto mais a fabrica roda, mais capacidade *provada* ela acumula.

## Onde Se Encaixa

AFEF intercepta o ponto `backlog_exhausted` do AP-806 em
`AutonomousEvolutionSessionService`, ANTES do honest stop, atras de uma flag
default-off. Maquina de estados:

```
EXECUTION MODE --(I8: 0 packets admissiveis por N ciclos + metricas estaveis + budget ok)--> EXHAUSTION GATE
EXHAUSTION GATE --(nao satisfez)--> HONEST STOP (comportamento de hoje, intacto)
EXHAUSTION GATE --(satisfez)--> FRONTIER MODE (premium, proposal-only, multi-horizonte)
FRONTIER MODE --> ARMOR PIPELINE (I1 -> I6 -> I3 -> I7 -> I9; falha em qualquer = drop+log)
ARMOR PIPELINE --> OPERATOR PROMOTION (I4: accept/reject/defer com receipt)
OPERATOR PROMOTION --(accepted)--> BACKLOG COMPILER (servico existente) --> packets
packets --> volta para EXECUTION MODE --(apos merge)--> I5 medir-ou-reverter
```

Frontier Mode e **proposal-only**: nunca escreve codigo de producao nem doc
canonico. So emite artefatos de proposta que precisam atravessar a armadura e o
operador.

## Contratos

Schemas canonicos (todos proposal/evidence; nenhum autoriza merge sozinho):

- **`atlas.foundry.evolution_proposal.v1`** — proposal_id, horizon
  (next_cycle | next_version | multiplier_capability), title, thesis,
  `evidence_refs[]` (I1: cycle_ids, commit_hashes, blocker_counts, file:line,
  repro_cmd), why_it_multiplies, `success_metric {property, baseline, target,
  measure_cmd}` (I2/I9), `rollback {trigger, method:git_revert, verify_cmd}`,
  risk_level, dependencies[], `proposed_packets[] {objective, allowed_files[],
  required_tests[]}` (I7), provider_tier_required, anti_pattern_self_check.
- **`atlas.foundry.proposal_verdict.v1`** — veredito por lente refuted/confirmed +
  evidencia da refutacao (I3).
- **`atlas.foundry.evolution_outcome.v1`** — baseline, post_value, improved?,
  action (consolidate | reverted) (I5).
- **`atlas.foundry.roadmap.v1`** — roadmap vivo, versionado, multi-horizonte de
  capacidades-multiplicadoras, cada uma `proposed | accepted | implemented | proven
  | reverted`. E a "documentacao avancada de auto-melhoria" — viva e auditavel,
  nunca prosa.

## Fluxo

Pipeline de blindagem multi-agente, adversarial. Estagios independentes; falhar em
qualquer estagio dropa a proposta **com motivo registrado** (drops viram evidencia
negativa para a proxima rodada). E um funil, nao um carimbo.

1. **Evidence Harvester** (barato) — le ledger/commits/blockers/metricas reais;
   produz o dossie de evidencia. Coleta fatos, nao opina.
2. **Frontier Generator** (premium) — recebe SO o dossie; gera N propostas
   multi-horizonte; proibido propor sem citar item do dossie (I1).
3. **Evidence Verifier** (codigo deterministico) — re-verifica cada ancora (o
   cycle_id existe? o commit? o gap reproduz?). Ancora falsa = drop (I1).
4. **Dedup / Prior-Art** (semantico + estrutural) — ja existe no codigo/docs/
   propostas passadas? = drop (I6).
5. **Adversarial Judge Panel** (premium, modelo != gerador, 3 lentes:
   real-vs-fantasia / multiplica-vs-polish / implementavel+falsificavel) — maioria
   precisa CONFIRMAR; default refutar (I3).
6. **Decomposer** (medio) — 3 a 12 packets bounded; senao `needs_operator_spec`
   (I7).
7. **Drift Mapper** (codigo) — mapeia para propriedade canonica medida; senao drop
   (I9).

Sobreviventes vao para o inbox do operador (I4).

## Regras para IA

A armadura: nove invariantes nao burlaveis, validados por codigo, nao por confianca
no modelo. Falhar qualquer um rejeita o artefato antes de tocar o repo.

- **I1 Evidence-Bound** — toda proposta cita ancoras *verificaveis*. Sem ancora
  verificavel = auto-reject antes do juiz.
- **I2 Falsifiability** — toda proposta declara metrica de sucesso com comando
  executavel + gatilho de rollback. Sem metrica falsificavel = reject.
- **I3 Independent Adjudication** — o juiz roda em provider/modelo DIFERENTE do
  gerador e e instruido a refutar; default = rejeitar em duvida.
- **I4 No Self-Canonization** — proposta -> revisado-pelo-operador -> canonico. O
  passo proposta->canonico EXIGE receipt humano ate haver historico de acerto.
- **I5 Measured-or-Reverted** — apos implementar, a metrica de I2 e medida; nao
  melhorou na tolerancia = git revert automatico + proposta vira
  refuted_by_reality.
- **I6 Anti-Duplication** — busca semantica + estrutural contra codigo, docs e
  propostas passadas (aceitas E rejeitadas). Match = reject.
- **I7 Bounded Decomposition** — proposta aceita decompoe em 3 a 12 packets bounded
  com arquivos reais + teste. Nao decomponivel = needs_operator_spec, nunca packet
  automatico.
- **I8 Budget & Rarity Gate** — Frontier Mode so dispara em exaustao *medida* (0
  packets admissiveis por N ciclos + metricas estaveis) com teto de gasto premium
  por janela.
- **I9 Drift Containment** — toda proposta deve melhorar uma *propriedade canonica
  medida* (validade %, waste %, backlog depth, blocker rate, p95). Nao mapeia =
  reject.

Padroes proibidos: sem auto-canonizacao (I4); sem proposta sem evidencia (I1) ou
sem metrica (I2); sem auto-julgamento (I3); sem "melhoria" nao medida (I5); sem OS/
runtime/provider novo; sem vocabulario `benchmark`/`rivals`/`superiority`.

## Escopo de Implementacao

Sequencia de build, cada AP independente e reversivel:

1. **AP-A** Schemas `foundry.*` + Evidence Harvester/Verifier (sem geracao) — provar
   que da para coletar e *verificar* evidencia real do ledger.
2. **AP-B** Exhaustion Gate (I8) — interceptar `backlog_exhausted`, rotear para
   Frontier atras de flag default-off.
3. **AP-C** Frontier Generator + Armor Pipeline (I1,I3,I6,I7,I9) — proposal-only no
   inbox; nada auto-canoniza.
4. **AP-D** Operator Promotion (I4) -> Backlog Compiler — proposta aceita vira
   finding canonica -> packets.
5. **AP-E** Measured-or-Reverted (I5) + Roadmap.v1 — fechar o loop de prova.

Fronteira dura: Frontier Mode e proposal-only; nunca escreve producao nem canonico.

## Dependencias

- Backlog compiler = `AreaFocusFactoryMaxCanonicalBacklogService` +
  `AreaFocusSelfConstructionAdmissionBridgeService` + `FindingSlicePlannerService`.
- Operator promotion (I4) = `AreaFocusOperatorDecisionService`.
- Inbox = curation inbox / AtlasInbox existente.
- Exhaustion entry (I8) = ponto `backlog_exhausted` do AP-806 em
  `AutonomousEvolutionSessionService`.
- Evidencia = Evidence Ledger + receipts do loop.
- Novo de verdade: so o Frontier Generator (estagio 2), o Judge Panel (estagio 5) e
  os schemas `foundry.*`. O resto e wiring.

## Evidencias

A prova de que AFEF funciona NAO e gerar propostas — e o outcome medido:

- `atlas.foundry.evolution_outcome.v1` com `action=consolidate` so quando o
  `measure_cmd` real provou melhoria na propriedade canonica.
- `roadmap.v1` com capacidades em estado `proven` (medidas), nao `implemented`
  (apenas mergeadas).
- Drops do pipeline registrados com motivo (evidencia negativa auditavel).
- Topologia de provider: barato/deterministico para Harvester/Verifier/Dedup/Drift/
  Decomposer; premium para Generator e Judge Panel (modelo != gerador); barato para
  implementar packets. Frontier Mode raro e gated (I8).

## Riscos

- **Auto-ilusao (risco central):** a fabrica se convencer de que evolui gerando
  make-work sofisticado. Mitigado por I1 (evidencia verificavel), I2 (metrica
  falsificavel) e I5 (medido-ou-revertido). Sem os tres, AFEF vira maquina de docs
  bonitas.
- **Conluio gerador/juiz:** mitigado por I3 (modelo diferente + refutar por
  default).
- **Inundacao do repo com especulacao:** mitigado por I4 (operador no gate) e I6
  (anti-duplicacao vs propostas passadas).
- **Drift de objetivo:** mitigado por I9 (toda proposta mapeia para propriedade
  canonica medida).
- **Queima de budget premium:** mitigado por I8 (raridade + teto por janela).
- **Escritor externo concorrente** no worktree base recria `base_worktree_dirty` e
  trava merges; durante geracao/implementacao a base deve ser dedicada ao loop.

## Exemplos

Ciclo de vida de uma proposta (ilustrativo):

1. Exhaustion Gate dispara apos 0 packets admissiveis por N ciclos.
2. Harvester nota 30 ciclos com blocker `owner_runtime_senior_loop_execution_not_passed`.
3. Generator propoe: "Unify review-lock with quarantine retry window", `success_metric`
   = "stale_locked_findings de 76 -> 0 medido por <cmd de contagem>", `rollback` = git
   revert se backlog depth cair.
4. Verifier confirma os 30 cycle_ids reais; Dedup nao acha duplicata; Judge Panel
   (modelo != gerador) confirma 2/3 lentes; Decomposer gera 3 packets bounded; Drift
   Mapper mapeia para `blocker_rate`.
5. Operador aceita (receipt). Compiler cria finding canonica -> 3 packets.
6. Ciclos implementam; apos merge o `measure_cmd` mede a propriedade; melhorou ->
   roadmap marca `proven`; nao melhorou -> git revert + `refuted_by_reality`.

## Proximas Acoes

- Entregar AP-A (schemas `foundry.*` + Evidence Harvester/Verifier) com evidencia
  real do ledger antes de qualquer geracao.
- So depois habilitar AP-B/AP-C atras de flag default-off.
- Manter este doc como fonte autoral; regenerar projecoes de provider apos mudancas.
- Sucesso so quando, em run longo, a fabrica continua produzindo trabalho admissivel
  apos esgotar o backlog humano E toda evolucao retida tem outcome `proven` medido.
