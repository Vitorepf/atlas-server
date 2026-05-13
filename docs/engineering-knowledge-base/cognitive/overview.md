---
id: atlas-ai-cognitive-overview
type: engineering_knowledge
title: Atlas AI Cognitive Plane - Overview
status: active
category: architecture
priority: 95
summary: Visao executiva Layer 2 do Cognitive Plane. Tese cognitiva, piramide de Pareto vs ciclo de gurus, 4 pilares (teorico/pratico/cognitivo/transferencial), 5 movimentos (declarar/gerar erro/praticar/provar/revisar), encaixe nos layers Atlas, autoridade.
tags:
  - atlas-ai
  - cognitive
  - overview
  - thesis
  - pareto
  - 4-pillars
  - 5-movements
capabilities:
  - cognitive_plane_overview
  - cognitive_thesis
  - pareto_curve_discovery
  - declarative_mastery_objective
  - five_movements_method
decisions:
  - Cognitive Plane e expansao do `learning` domain + capabilities cognitivas no Core + Curator preditivo dedicado; nao novo Atlas.
  - Operador declara dominancia ("quero dominar X") como input canonico; Atlas faz Pareto curve discovery e monta trilha do cume.
  - Atlas substitui a funcao "comprar curso externo"; fontes vivem internas/auditaveis.
  - Meta operacional: comprimir o ciclo "guru raso -> mediano -> bom" em pelo menos 3x; meta longa 5-10x.
  - Aprendizado tem 4 pilares inseparaveis; faltar um invalida maestria.
  - Metodo opera em 5 movimentos: declarar, gerar erro (Generation Effect), praticar, provar, revisar.
maintenance:
  - Manter abaixo de 260 linhas (contrato canonico Doc-OS).
  - Atualizar quando tese, pilares, movimentos ou autoridade mudarem.
  - Status `active` significa que a spec e canonica; maturidade de runtime continua nos APs e na matriz implemented-vs-scaffold.
related_paths:
  - docs/engineering-knowledge-base/cognitive/implementation-briefing.md
  - docs/engineering-knowledge-base/cognitive/README.md
  - docs/engineering-knowledge-base/cognitive/principles.md
  - docs/engineering-knowledge-base/cognitive/capabilities-core.md
  - docs/engineering-knowledge-base/cognitive/multiplier-edge.md
  - docs/engineering-knowledge-base/cognitive/pipeline-overlay.md
  - docs/engineering-knowledge-base/cognitive/roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/domains/learning.md
owner: atlas-ai
layer: 2
line_limit: 260
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cognitive-overview

graph_title: Atlas AI Cognitive Plane - Overview

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/cognitive/overview.md

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
  - docs/engineering-knowledge-base/cognitive/overview.md

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
# Atlas AI Cognitive Plane — Overview

Visao executiva. Detalhes operacionais nas specs irmas.

## Scope

Sub-arquitetura especializada do Atlas AI responsavel por gerenciar trajetoria cognitiva completa do operador como **canal unico**: o que estudar, quando, como consolidar, como provar maestria, como antecipar a proxima area. Nao substitui o `learning` domain; expande-o com flows versionados e status governado por AP.

## Authority

Em conflito, ordem vence:

1. Tese central (Layer -1) — `atlas-ai-thesis-multiplier-channel.md`
2. Kernel (Layer 1) — contratos executaveis
3. Master (Layer 2) — produto e planes
4. Esta pasta `cognitive/` — design cognitivo
5. AP correspondente em `docs/ap/AP-###-cognitive-*.md` — detalhe executavel
6. `domains/learning.md` — semantica de domain

## A Tese Cognitiva

**Utopia perfeita:** maximizar simultaneamente conhecimento (o que se sabe), cognicao (como se pensa) e habilidades cognitivas (o que se consegue fazer com o que se sabe), em qualquer area, com tempo ate maestria reduzido em ordem de magnitude versus o ciclo aprendiz-faz-sozinho.

Tres propriedades inseparaveis:

| Propriedade | Definicao |
|---|---|
| Cobertura completa | os 4 pilares atendidos; faltar um invalida |
| Compressao temporal | tempo Atlas / tempo natural >= 3x; meta longa 5-10x |
| Auto-reforco | cada area dominada alimenta o grafo, refina Curator, treina Atlas-Vitor cognitivo |

Pergunta-norte cognitiva (analoga a pergunta-norte da Tese central):

> **Esta feature multiplica meu output cognitivo, ou compete com o ato de aprender? Mantem o Atlas como canal unico, ou cria fricca de escape?**

Multiplica + canal unico -> constroi. Compete + escape -> descarta.

## A Piramide de Pareto — descoberta de cume

Toda area tem **piramide real** (estrutura cognitiva do dominio) e **piramide vendida** (o que produtores empacotam pra iniciantes pagantes). Raramente coincidem.

```
PIRAMIDE VENDIDA (escada)        PIRAMIDE REAL (cume)

[guru excelente]                 [conceitos que dominam]
[guru mediano]                   [composicao de primeiros principios]
[guru raso]                      [armadilhas que so quem dominou conhece]
[introducao]                     [transferencias que nao parecem evidentes]
                                 [restante do dominio - 80% segue dos 20% acima]
```

### O ciclo natural ineficiente

| Mes | O que acontece | Custo |
|---|---|---|
| 0 | paga curso de guru raso | dinheiro + 2 meses |
| 2 | descobre que existe nivel maior | dinheiro novo |
| 5 | descobre nivel acima ainda | mais dinheiro + frustracao |
| 8+ | finalmente domina | tempo + dinheiro + estudo desalinhado |

### A inversao Atlas

Operador declara "quero dominar X". Atlas mapeia o **cume primeiro** via Pareto Discovery Engine:

| Etapa | O que faz |
|---|---|
| Levantamento de fontes canonicas | livros seminais, papers fundadores, voz de quem ja chegou no topo |
| Mapeamento de conceitos-cume | os 20% que se compoem entre si e cobrem 80% |
| Resolucao de pre-requisitos | so o que e necessario, na ordem correta |
| Deteccao de armadilhas conhecidas | falhas comuns documentadas no topo |
| Identificacao de transferencias | o que essa area emprestada de outras ja dominadas |
| Plano de transferencia ativa | onde no projeto/vida real do Vitor isso pode aplicar |

Compradores de curso sobem do chao. O Atlas desce do topo.

## Os 4 Pilares Cognitivos

Maestria de uma area exige os quatro. O Atlas nao marca dominado em rubrica que cobre so um.

| Pilar | Definicao | Falha tipica |
|---|---|---|
| Teorico | conceitos, modelos, primeiros principios, vocabulario formal | "li mas nao consigo explicar" |
| Pratico | ferramentas, procedimentos, gestos, fluencia operacional | "entendo mas nao sei fazer" |
| Cognitivo | como pensar dentro da area: padroes, intuicao, deteccao de armadilha | "sei tudo mas tomo decisao ruim" |
| Transferencial | aplicar em contexto novo, conectar com areas adjacentes | "fiz exatamente o exercicio mas trava em problema novo" |

## O Metodo na Pratica — 5 Movimentos

```
DECLARAR -> GERAR ERRO -> PRATICAR -> PROVAR -> REVISAR
```

| Movimento | O que o operador faz | O que o Atlas faz | Fundamento |
|---|---|---|---|
| Declarar | "Quero dominar X em N meses" | Pareto curve discovery + curriculum | First Principles + objetivos SMART |
| Gerar Erro | faz previsao/hipotese antes de aprender | apresenta problema cru pre-teoria e captura `operator_prediction` | Generation Effect + Productive Failure |
| Praticar | bloco focado em zona 80/20 + microsessoes intercaladas | escolhe nó proximo da fronteira; mistura dominios | Pratica Deliberada (Ericsson) + Desirable Difficulty (Bjork) + Interleaving |
| Provar | Feynman em voz, caso real, artefato deployavel | scoreia clareza, marca lacuna, exige `transfer_proof` | Feynman + Construcionismo (Papert) + Transferencia Analogica |
| Revisar | responde fila SRS do dia; aceita pausa difusa | calcula proxima revisao por nó (FSRS); propoe DMN/NSDR | FSRS + Spacing (Bjork) + DMN + NSDR |

A inversao **"Gerar Erro" antes de "Praticar"** e a aplicacao pratica do erro preditivo calibrado: o operador registra uma hipotese, o Atlas revela a realidade validada, compara a divergencia e transforma a diferenca em atualizacao de modelo mental.

```text
previsao do operador -> realidade validada -> divergencia -> principio extraido -> transfer_test
```

Essa etapa nao e frustracao artificial. O problema precisa estar perto da fronteira real de habilidade (`dreyfus_stage`), com carga cognitiva aceitavel e com comparacao posterior obrigatoria. Sem comparacao e transferencia, "errar" vira ruido.

## Encaixe nos Layers Atlas

| Layer | Mudanca | Sem mudanca |
|---|---|---|
| -1 Tese | aprender e multiplicador; uso direto de IA externa para estudo e ciclo de morte | tudo |
| 0 Glossario | aditivo: termos cognitivos | identidade Atlas |
| 1 Kernel | aditivo: novos `LedgerEventType`, novos SLOs cognitivos, novo `input_kind=cognitive` | contratos base, hash chain, envelope tipado |
| 1.5 Runtime Boundaries | Python AI/Data ganha papel cognitivo (SRS, NLP, Knowledge Graph) | Laravel decide; Python executa sob receipt |
| 2 Master | Cognitive Plane vira vista sub-arquitetural sobre Domain Plane | os 7 planes |
| 3 Topology | pipeline tem hooks cognitivos por etapa | as 17 etapas |
| 4 Domains | `learning` expande para catalogo cognitivo versionado (22 flows implementados/planejados; status real vem do AP); `personal_development.cognitive_load_review` reforcado; `self_improvement.cognitive_review` adicionado | Programming, Finance, etc. |

## Cognitive Plane vs Domain Plane

Cognitive Plane nao e tabela nova. E **leitura cruzada** do Domain Plane filtrada pela capability `cognitive_development`:

```
DOMAIN PLANE                COGNITIVE PLANE (vista)
- programming               - learning (dono cognitivo)
- finance                   - research (fontes/curadoria)
- personal_development      - personal_development (load + energia)
- ...                       - writing (Feynman e sintese)
                            - self_improvement (curator preditivo)
                            - health (sono/dieta/recuperacao)
                                       |
                                       v
                            ATLAS KERNEL PIPELINE (mesmo)
```

## Compromisso Cardinal

Toda decisao no Cognitive Plane e auditada contra a pergunta-norte. Sem desvios.

## Continuidade

- Detalhes operacionais nas specs irmas (`README.md` desta pasta)
- Detalhes executaveis nos APs em `docs/ap/AP-###-cognitive-*.md`
- Implementacao por fase conforme `roadmap.md` — Dreyfus first

## Resumo

Visao executiva Layer 2 do Cognitive Plane. Tese cognitiva, piramide de Pareto vs ciclo de gurus, 4 pilares (teorico/pratico/cognitivo/transferencial), 5 movimentos (declarar/gerar erro/praticar/provar/revisar), encaixe nos layers Atlas, autoridade.

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
