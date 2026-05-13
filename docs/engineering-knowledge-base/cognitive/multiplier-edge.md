---
id: atlas-ai-cognitive-multiplier-edge
type: engineering_knowledge
title: Atlas AI Cognitive Plane - Multiplier Edge
status: implemented-operational-read-model
category: architecture
priority: 96
summary: 10 capabilities cardinais cognitivas que NENHUM sistema educacional concorrente pode replicar por motivo arquitetural - onde a Tese de canal multiplicador encontra o Cognitive Plane. Lista resumida; detalhe operacional em APs dedicados.
tags:
  - atlas-ai
  - cognitive
  - multiplier-edge
  - dreyfus
  - latticework
  - structural-edge
capabilities:
  - cognitive_multiplier_edge
  - dreyfus_dynamic_pedagogy
  - evidence_driven_self_assessment
  - cross_domain_latticework
  - multi_provider_discord_detector
  - atlas_vitor_socratic_tutor
  - cross_domain_evidence_routing
  - temporal_compression_validation
decisions:
  - 10 capabilities cardinais; Dreyfus first por bang-for-buck (impacto na primeira sessao, menor pre-requisito).
  - Multi-Provider Debate Engine e opt-in cardinal, nunca default.
  - Atlas-Vitor Socratico nasce como modo do Atlas-Vitor Cognitivo, nao capability separada.
  - Cross-Domain Evidence Routing e ponte entre `programming` (uso real) e `learning` (currículo).
  - Latticework e projection cross-domain do Knowledge Graph.
  - Temporal Compression Validation e a metrica cardinal de Rivals-Learning.
maintenance:
  - Manter abaixo de 260 linhas.
  - Detalhes executaveis por capability vivem em `docs/ap/AP-###-cognitive-*.md` quando ja existe AP numerado; AP-COG-EDGE-* fica reservado para futuros sem numero.
  - Atualizar quando capability sair de scaffold ou ganhar AP dedicado.
related_paths:
  - docs/engineering-knowledge-base/cognitive/implementation-briefing.md
  - docs/engineering-knowledge-base/cognitive/README.md
  - docs/engineering-knowledge-base/cognitive/overview.md
  - docs/engineering-knowledge-base/cognitive/capabilities-core.md
  - docs/engineering-knowledge-base/cognitive/roadmap.md
  - docs/ap/AP-163-cognitive-dreyfus-dynamic-pedagogy.md
owner: atlas-ai
layer: 2-and-3
line_limit: 260
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cognitive-multiplier-edge

graph_title: Atlas AI Cognitive Plane - Multiplier Edge

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/cognitive/multiplier-edge.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - cognitive

evidence:
  - docs/engineering-knowledge-base/cognitive/multiplier-edge.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - cognitive

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

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Cognitive Plane — Multiplier Edge

Subset Atlas-unico do Cognitive Plane.

## Pergunta-norte deste doc

> Esta capability so existe porque o Atlas e Atlas?

Sim → fica aqui. Nao → vai pra `capabilities-core.md`.

## Authority

Em conflito: Tese central > Kernel > `cognitive/overview.md` > este doc > AP numerado ou AP-COG-EDGE futuro.

## As 10 Capabilities Cardinais

Resumo executivo. Detalhe operacional (schema, migration, services, gates, telas) vive em AP dedicado.

| # | Capability | Por que so o Atlas pode | Pre-req | Evidence | Fase | AP |
|---|---|---|---|---|---|---|
| 1 | **Dreyfus Dynamic Pedagogy** | conhece nivel real por dominio via Programming/Finance/Learning ledgers cruzados; nao confia em auto-relato | flows `learning` v2 | implemented-operational-read-model | **1 (done)** | AP-163 |
| 2 | **Evidence-driven Self-Assessment** | mastery derivada de uso real auditavel no ledger | Evidence Ledger ~30-60d | consensus | 2 | AP-COG-EDGE-02 |
| 3 | **Cross-Domain Latticework** | Knowledge Graph cruzado com domains de trabalho real | Knowledge Graph ~500+ nós | emerging | 3 | AP-COG-EDGE-03 |
| 4 | **Multi-Provider Discord Detector** (opt-in) | canal multi-provider neutro por design | Provider Driver Registry | emerging | 4 | AP-COG-EDGE-04 |
| 5 | **Atlas-Vitor Socratic Tutor** | modelo local fine-tuned no Knowledge Graph pessoal | Atlas-Vitor Cognitivo + ledger maduro | speculative | 5 | AP-COG-EDGE-05 |
| 6 | **Cross-Domain Evidence Routing** | Programming/Finance detectam uso real -> Curator ajusta currículo | Evidence Ledger + Curator | emerging | 2 | AP-COG-EDGE-06 |
| 7 | **Temporal Compression Validation** | Rivals-Learning real - tempo ate `transfer_proof` Atlas vs uso direto | Rivals-Learning maduro | emerging | 6 | AP-COG-EDGE-07 |
| 8 | **Personal Worked Examples Generator** | usa SEU codigo (commits, PRs), SUAS decisoes (Strategic Decision domain), SEUS Feynman antigos como worked examples com fading. Worked examples + fading com material proprio que nenhum tutor tem | Evidence Ledger ~30-60d + Worked Example Engine | implemented_partial | 2 | AP-169 |
| 9 | **Predictive Failure Insertion** | Atlas conhece onde voce provavelmente vai falhar (Dreyfus + failure_signature + fallback de gap/decay). **Insere problema calibrado** que ativa Generation Effect personalizado; alvo explicito obrigatorio, nunca `unknown` | Failure Tracker + Dreyfus; KG maduro futuro | implemented_partial | 4 | AP-170 |
| 10 | **Process Pattern Personal Detector** | varre o ledger pessoal e destila padroes humanos emergentes ("voce aplicou abordagem X em 3 contextos diferentes em 90d - isso e um pattern; quer nomear?"). Process Pattern Catalog populado automaticamente com sua propria experiencia | Evidence Ledger maduro + Process Pattern Catalog | emerging | 3 | AP-COG-EDGE-10 |

## Capability 1 — Dreyfus Dynamic Pedagogy (Fase 1, AP-163)

Pedagogia muda por nivel detectado (5 niveis: novato → competente → proficiente → expert → master). Atlas conhece seu nivel real por dominio via ledgers cruzados; nao confia em auto-relato (Dunning-Kruger).

| Nivel | Pedagogia certa |
|---|---|
| Novato | regras explicitas, scaffolding pesado, feedback em cada gesto |
| Competente | casos com variacao controlada, articulacao de decisao |
| Proficiente | casos completos, estrategia, transferencia adjacente |
| Expert | desafios mal-estruturados, defesa adversarial, criacao |
| Master | dialogo de pares, criacao de doutrina, formacao de outros |

**Comando canonico local:** `php artisan atlas:study laravel-queues` opera em modo expert; `php artisan atlas:study agricultura-soja` opera em modo novato. Product wrapper futuro pode expor `atlas study ...`.

Detalhe completo: `docs/ap/AP-163-cognitive-dreyfus-dynamic-pedagogy.md`.

**Status implementado:** migration `dreyfus_overlays`, repository, evidence aggregator, resolver, prompt builder, Decision Receipt extension, `pedagogy_matches_stage`, eventos `DREYFUS_*`, SLOs `cognitive.dreyfus.*`, `atlas:dreyfus` e `atlas:study`.

## Capability 2 — Evidence-driven Self-Assessment (Fase 2)

Mastery e **derivada de evidencia auditavel**, nao auto-relato. `MasteryEvidenceCollector` varre ledger semanalmente; computa `mastery_profile` por nó com: aplicacoes reais, falhas, Feynman scores, transfer proofs, decay estimado. Operador apenas confirma ou contesta a derivacao; nao declara dominado por clicar botao.

**Comandos:** `atlas mastery <node>`, `atlas mastery review`, `atlas mastery dispute <node>`.

## Capability 3 — Cross-Domain Latticework (Fase 3)

Latticework de Munger (rede de modelos transferiveis) deixa de ser metafora e vira **projection cross-domain do Knowledge Graph**. Detecta automaticamente: "voce usou bottleneck-detection em Programming; mesmo modelo aplica em Finance, em Strategic Decision". Apresenta side-by-side com modelo matematico unificado.

**Comandos:** `atlas latticework <node>`, `atlas latticework discover`. Surface: Constelacao Surface (serendipidade governada).

## Capability 4 — Multi-Provider Discord Detector (Fase 4, opt-in cardinal)

Mesma pergunta em N providers em paralelo; **discordancia vira sinal didatico** (area onde nem o estado-da-arte agregado converge).

**Restricoes operacionais (cardinais):**

- **NUNCA default**; rate-limit explicito; `selection_mode=multi_provider_debate` auditado
- Rodar apenas em: validacao de Pareto Discovery, `mastery_review` de nó controverso, `first_principles_decompose` de premissa critica, `atlas debate <topic>` explicito
- **Proibido em**: active recall, flashcard, daily_plan, micro_session

**Comando:** `atlas debate <topic>`. Output mostra zona de consenso vs discord_score.

## Capability 5 — Atlas-Vitor Socratic Tutor (Fase 5)

Modo do Atlas-Vitor Cognitivo (nao capability separada) que **so pergunta, nunca afirma**. Faz perguntas que so ele pode fazer porque conhece sua trajetoria pessoal: "voce usou X em 2025-Q1 mas nunca aplicou em Y. por que?". Tutor humano nao tem acesso ao seu ledger; Atlas-Vitor tem.

**Comando:** `atlas socratic <topic>` ou `atlas socratic` (Atlas escolhe). Modo Voice particularmente eficiente porque forca clareza verbal.

## Capability 6 — Cross-Domain Evidence Routing (Fase 2)

Programming domain (e Finance, Personal Dev, Strategic Decision) registra o que voce usa **de fato no trabalho**. `CrossDomainEvidenceRouter` (capability) roda diariamente; le isso; **prioriza fila existente** do learning automaticamente. Usou PCA 5x na semana → pre-requisitos de algebra linear sobem na fila SRS.

**Restricao:** routing **nao matricula em area nova**, so prioriza fila existente. `atlas routing pause` quando operador quer modo nao-rotativo.

## Capability 8 — Personal Worked Examples Generator (Fase 2)

**Eixo Tim Cook / alta performance operacional.** Atlas usa material proprio do operador como worked examples + fading:

- Commits e PRs (Programming domain)
- Decisoes registradas (Strategic Decision domain)
- Feynman explicacoes antigas
- Sessoes de estudo passadas

`PersonalWorkedExamplesGenerator` consome ledger + Knowledge Graph + dreyfus_stage do nó-alvo e produz:

| Stage do operador | Output |
|---|---|
| Novato | exemplo completo: solucao + raciocinio passo-a-passo + por que funcionou |
| Competente | exemplo com 2-3 etapas faltando para preencher |
| Proficiente | so problema + solucao final, raciocinio escondido |
| Expert | so problema; voce reconstroi |

**Comando canonico local:** `php artisan atlas:worked-example <topic>` ou aparece automaticamente no `learning.deep_work` quando dreyfus_stage <= 3.

**Por que so o Atlas faz**: tutor humano nao tem seu codigo. ChatGPT direto nao tem seu historico longitudinal.

## Capability 9 — Predictive Failure Insertion (Fase 4)

Atlas conhece **exatamente onde voce vai falhar**: gaps no Knowledge Graph + decay overlay alto + dreyfus_stage baixo + failure_signature historico em area adjacente. Em vez de evitar a falha, **insere problema calibrado** que vai ativar Generation Effect personalizado.

Implementa C14 como erro preditivo calibrado: insere problema que o operador provavelmente erra de forma util, registra divergencia e exige comparacao + transferencia. Nao e "hard mode" permanente.

**Comando atual:** `php artisan atlas:predict failure <node> --json`. Futuro: aparece em `learning.daily_plan` como "1 problema do dia que voce provavelmente vai errar".

**Por que so o Atlas faz**: precisa do historico de falhas + grafo + dreyfus simultaneos.

## Capability 10 — Process Pattern Personal Detector (Fase 3)

Varre seu ledger pessoal e destila padroes humanos emergentes:

> "Voce aplicou abordagem X em 3 contextos diferentes nos ultimos 90 dias com sucesso (programming.refactor 2026-03, strategic_decision.team-restructure 2026-04, finance.portfolio_review 2026-05). Isso e um pattern recorrente. Quer nomear e adicionar ao seu Process Pattern Catalog?"

Operador nomeia (ex.: `cut-then-rebuild`), revisa estrutura sugerida, aceita. Vira nó cross-domain do Knowledge Graph + entrada formal no catalogo.

**Comando canonico local:** `php artisan atlas:pattern catalog`. `atlas pattern propose` fica para detector pessoal futuro.

**Por que so o Atlas faz**: precisa do ledger transversal de meses para detectar repeticoes contextuais.

## Capability 7 — Temporal Compression Validation (Fase 6)

Metrica cardinal de Rivals-Learning: **tempo ate `transfer_proof` em contexto novo**, comparado contra: tempo medio historico do operador; tempo do ciclo Claude/ChatGPT direto (Rivals); meta declarada (3-10x mais rapido).

**Comandos:** `atlas compression <area>`, `atlas compression all`, `atlas compression rivals <area>`.

Plataforma de curso mede completion. Atlas mede transfer real, longitudinal, com ledger auditavel.

## Roadmap dominante

| Fase | Capability | Justificativa |
|---|---|---|
| 1 (now) | Dreyfus Dynamic Pedagogy | impacto na primeira sessao; pre-req minimo |
| 2 | Cross-Domain Evidence Routing + Evidence-driven Self-Assessment | maximiza Evidence Ledger acumulado (~30-60d) |
| 3 | Cross-Domain Latticework | depende de Knowledge Graph maduro (~500+ nós) |
| 4 | Multi-Provider Discord Detector | depende de pipeline cognitivo estavel |
| 5 | Atlas-Vitor Socratic Tutor | depende de Atlas-Vitor Cognitivo treinado |
| 6 | Temporal Compression Validation | depende de Rivals-Learning maduro |

**Regra de ouro:** Fase 1 sai antes de qualquer outra. Sem Dreyfus, todas as outras capabilities operam sobre suposicoes erradas de nivel.

## Anti-patterns transversais (Multiplier Edge)

| Anti-pattern | Por que evitar |
|---|---|
| Implementar 7 capabilities em paralelo | Vaporware. Roadmap dominante existe por razao. |
| Multi-Provider Debate como default | Custo + latencia + ruido |
| Latticework forcando conexoes superficiais | Ruido. So conexoes de alta similaridade matematica |
| Atlas-Vitor Socratico afirmando | Quebra protocolo. So perguntas |
| Cross-Domain Routing matricular automaticamente | Viola governanca. So prioriza fila existente |
| Compression Validation sem transfer real | Compressao sem qualidade = regressao silenciosa |

## Continuidade

Cada capability cardinal tera AP dedicado quando entrar implementacao. AP-163, AP-169 e AP-170 ja existem como specs numeradas; demais futuros podem usar AP-COG-EDGE ate serem promovidos.

## Resumo

10 capabilities cardinais cognitivas que NENHUM sistema educacional concorrente pode replicar por motivo arquitetural - onde a Tese de canal multiplicador encontra o Cognitive Plane. Lista resumida; detalhe operacional em APs dedicados.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
