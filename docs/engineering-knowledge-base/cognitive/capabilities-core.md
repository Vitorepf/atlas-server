---
id: atlas-ai-cognitive-capabilities-core
type: engineering_knowledge
title: Atlas AI Cognitive Plane - Capabilities Core
status: scaffold
category: architecture
priority: 94
summary: ~32 capabilities cognitivas horizontais que vivem no Core (servem learning + research + writing + self_improvement). Cada capability declara papel, runtime, evidence_level e fundamento cientifico. Anti-duplicacao Core vs Domain enforcada.
tags:
  - atlas-ai
  - cognitive
  - capabilities
  - core
  - srs
  - recall
  - feynman
  - knowledge-graph
capabilities:
  - cognitive_capabilities_catalog
  - core_horizontal_capabilities
  - evidence_level_per_capability
decisions:
  - Capabilities cognitivas horizontais (servem >1 dominio ou surface) vivem no Core, nao no Domain `learning`.
  - Cada capability carrega `evidence_level` (consensus/emerging/contested/speculative) por C15.
  - SRS default e FSRS (consensus); SM-2 deprecado.
  - Specialist profiles cognitivos vivem dentro do `learning`, nao como dominios paralelos.
  - Multi-Provider Debate, Identity Tracker e Confidence Calibration tem restricoes operacionais especificas (ver tabela).
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar quando capability mudar de evidence_level, sair de scaffold ou ganhar AP dedicado.
related_paths:
  - docs/engineering-knowledge-base/cognitive/implementation-briefing.md
  - docs/engineering-knowledge-base/cognitive/README.md
  - docs/engineering-knowledge-base/cognitive/principles.md
  - docs/engineering-knowledge-base/cognitive/multiplier-edge.md
  - docs/engineering-knowledge-base/cognitive/pipeline-overlay.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
owner: atlas-ai
layer: 2
line_limit: 260
---

# Atlas AI Cognitive Plane — Capabilities Core

## Aviso de leitura

A lista abaixo e o **mapa exaustivo de design**, nao plano de execucao. Padrao Atlas: docs canonicos sempre listam todas as capabilities mapeadas (ver `kernel-architecture.md` com 100+ artefatos). Execucao e governada pelo `roadmap.md`. Olhar a lista inteira para programar e armadilha; olhar o roadmap e o caminho.

## Authority

Capabilities horizontais que servem learning + research + writing + self_improvement. Pertencem ao Core conforme `core-vs-domain.md`. Cada capability declara `evidence_level`. Capabilities Atlas-unicas (cardinais) vivem em `multiplier-edge.md`.

## Catalogo

| Capability | Papel | Runtime | Evidence | Fundamento |
|---|---|---|---|---|
| Spaced Repetition Engine | algoritmo FSRS por nó do grafo (default; SM-2 deprecado) | Python AI/Data | consensus | Wozniak; FSRS |
| Active Recall Generator | gera perguntas tipadas (factual/conceitual/transferencia/contraexemplo) | Python AI/Data | consensus | Bjork (Retrieval Practice) |
| Generation Engine / Pretest | apresenta problema antes da teoria; captura `operator_prediction`; compara com realidade validada; gera `prediction_error_delta` e `model_update` | Provider + Laravel | consensus | Generation Effect + Productive Failure |
| Feynman Validator | scoreia explicacao humana (clareza, lacuna, analogia fragil) | Laravel + Provider | consensus | Feynman Technique |
| Knowledge Graph Builder | extrai conceitos e relacoes; consolida grafo | Python AI/Data | consensus | grafo cognitivo |
| Curriculum Engine | objetivos + estado -> trilha resolvida | Laravel Kernel | consensus | desenho instrucional |
| Pareto Discovery Engine | mapeia cume da area antes da escada | Laravel + Python + Research | emerging | Pareto + curadoria |
| Predictive Curriculum Advisor | shadow advisory dentro do Curator | Laravel + Python | emerging | analise de trajetoria |
| Interleaving Scheduler | mistura intencionalmente nós de dominios diferentes no daily_plan | Laravel | consensus | Bjork (Interleaving) |
| Micro-Skill Isolator | decompoe meta em micro-habilidades treinaveis isoladamente | Laravel + Provider | consensus | Pratica Deliberada (Ericsson) |
| Multi-Perspective Case Generator | gera caso visto por 3+ lentes (engenharia, negocio, design) | Provider + Harness | consensus | Cognitive Flexibility (Spiro) |
| Deep Transfer Probe | testa atravessamento entre dominios distantes; nucleo > superficie | Provider + Validator | consensus | Transferencia Analogica |
| Cognitive Load Classifier | classifica carga em intrinseca/extrinseca/germinativa | Go Edge + Laravel | consensus | Sweller |
| Cognitive Load Monitor | sinal continuo derivado de telemetry/sleep/focus/energia | Go Edge + Laravel | consensus | telemetria + Personal Dev |
| Flow Trigger Engine | verifica gatilhos antes de iniciar deep_work (risco/novidade/complexidade/feedback) | Laravel | consensus | Hipofrontalidade Transitoria |
| DMN Oscillator | apos X minutos focado, propoe pausa difusa governada (caminhada/silencio) | Laravel + scheduler | consensus | Default Mode Network |
| NSDR Engine | propoe Non-Sleep Deep Rest pos-deep-work; cronometra; nao prescreve | Laravel + App/Voice | consensus | NSDR (Walker, Huberman) |
| Hemingway Checkpoint | forca parar sessao no meio de logica clara para abrir loop Zeigarnik | Laravel + study_session | emerging | Efeito Zeigarnik |
| Incremental Reading Engine | fragmenta fontes do Knowledge Graph; serve em micro_session intercalada | Python AI/Data | emerging | Wozniak (Incremental Reading) |
| Perceptual Drill Engine | flashes rapidos para treinar Sistema 1 (codigo limpo/sujo, layout, padrao visual) | Provider + Python | consensus em dominio | Aprendizagem Perceptiva (PLMs) |
| Adversarial Validator (Red Team) | papel canonico cardinal: tenta quebrar logica, aponta vies cognitivo | Provider + Laravel | consensus | Kahneman (Sistema 1/2, vieses) |
| First Principles Probe / Ontological Reduction | desdobra problema ate verdades atomicas; reconstroi a partir do nucleo | Provider | consensus | First Principles |
| Multi-Provider Debate Engine (opt-in cardinal) | mesma pergunta em N providers em paralelo; discordancia vira sinal didatico. **NUNCA default**; rodado apenas em decisoes pesadas com `selection_mode=multi_provider_debate` auditado | Laravel + Provider Driver Registry (paralelo) | emerging | multi-agent debate |
| Confidence Calibration Drill (intermitente) | drill **raro** de calibracao epistemica. **Cadencia limitada**: somente em `mastery_review` (mensal), `transfer_test` (quinzenal) ou `first_principles_decompose`. **Proibido em flow continuo** | Laravel + Provider | consensus | metacognicao (Tetlock; Dunning-Kruger) |
| Identity Tracker (observacional) | derivacao **read-only** do Evidence Ledger ("nos ultimos 90d, X% das suas decisoes de tempo foram estudo - padrao estavel"). **Proibido push afirmativo, frase motivacional ou afague de ego** | Laravel + projection | emerging | habit/identity science (BJ Behavior Model real) |
| Dual N-Back Drill (opcional, sem promessa de QI) | exercicio de memoria de trabalho; gamificado; sem prescricao | Python | contested | Dual N-Back |
| Game/Case Engine (Harness) | gera desafios gamificados e cases por dominio | Harness pattern | consensus | engajamento + Construcionismo |
| Cognitive Forge Harness | sessao multi-flow pesada com Evidence packet final | Harness pattern | consensus | Engineering Harness pattern |
| TMR (Targeted Memory Reactivation) | requires_rivals_validation + hardware (StackChan/wearable) | future | emerging | TMR (laboratorio) |
| **Worked Example Engine + Process Fading Scheduler** | **implementado-operational-read-model**: apresenta solucao completa de processo/decisao; fade scheduler vai removendo etapas conforme dreyfus_stage avanca; gera explicit step-by-step pra novato, caso parcial pra competente, caso cru pra proficiente+. **Cardinal para dominios tecnicos e operacionais**. | Laravel | consensus | Sweller, Renkl (Worked Examples + Fading) |
| **Process Pattern Catalog** | **implementado-operational-read-model**: catalogo formal de padroes humanos de decisao/processo. Cada pattern: name, category, intent, problem_context, forces, solution, personal_evidence, consequences, anti_patterns, related_patterns. **Latticework de Munger formalizado como Design Patterns GoF aplicaveis** | Laravel | consensus | Munger Latticework + GoF Design Patterns |
| **Failure Signature Classifier + Bayesian Failure Tracker** | **implementado-operational-read-model**: classifica falhas em `failure_signature` provider-safe, persiste recorrencia, mede `failure_diversity_index`, emite `FAILURE_REPETITION_ALERT`, expõe CLI `atlas:failure` e registra `learning.failure_review` + `self_improvement.failure_pattern_review`. Implementa C20; proposal emission futura consome este read model. | Laravel | emerging | Productive Failure (Kapur) + Bayesian updating |
| **Self-Explanation Generator** | refinamento do Active Recall com tipo `self_explanation_question` ("explique pra si mesmo por que isso e verdade") | Provider + Laravel | consensus | Chi, Bassok (Self-Explanation) |
| **Self-Regulated Learning Orchestrator** | **implementado-operational-read-model**: overlay metacognitivo opt-in com preferences por dominio, episodios SRL, forethought/performance/reflection, CLI `atlas:srl`, gate de fase e events `SRL_*`. Runtime UI hooks em surfaces ficam como consumer work. | Laravel | consensus | Zimmerman (Self-Regulated Learning) |
| **Multimedia Composer (Mayer Principles)** | hint do Output Renderer: combina texto + diagrama + voz + codigo conforme 12 principios validados de Mayer (signaling, segmenting, coherence, etc.) | Laravel + provider | consensus | Mayer (Multimedia Learning) |

## Specialist Profiles iniciais de `learning`

`learning.mathematics` · `learning.programming_concepts` · `learning.systems_thinking` · `learning.linguistics` · `learning.physics` · `learning.economics` · `learning.cognitive_science` · `learning.history` · `learning.philosophy` · `learning.design` · `learning.business` · `learning.health_science` · **`learning.process_engineering`** · **`learning.operational_excellence`** · **`learning.pattern_thinking`**

Os tres ultimos enderecam **alta performance operacional** (eixo Tim Cook): otimizacao de processo, supply chain, decisao em escala, padroes humanos transversais. Conectam com domains `operations` e `strategic_decision`.

Atlas Decide usa o profile como sinal; nao hardcoda provider por area.

## Restricoes operacionais especiais

| Capability | Restricao | Motivo |
|---|---|---|
| Multi-Provider Debate | nunca default; opt-in cardinal; rate-limit explicito; rodado apenas em decisoes pesadas (mastery_review, validacao de conceito controverso, Pareto Discovery) | custo + latencia; viola C13 se default |
| Confidence Calibration | cadencia limitada (mensal/quinzenal/sob demanda); proibido em flow continuo | burocracia cognitiva |
| Identity Tracker | observacional read-only; proibido push afirmativo | viola C15 — autoajuda disfarçada |
| Dual N-Back | sem promessa de QI; opcional; nunca virara default | `contested` |
| TMR | future; depende de hardware StackChan/wearable + Rivals validation | `speculative` como produto |

## Anti-Duplicacao Core vs Domain

Capability nova entra no Core se:

- Serve mais de uma surface OU
- Serve mais de um dominio (learning, research, writing, self_improvement)

Senao vira:

- specialist_profile dentro de `learning`
- Domain feature interno
- Surface feature

Capability cardinal Atlas-unica vai pra `multiplier-edge.md`, nao aqui.

## Validation

Apos alterar este doc:

```bash
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
atlas engineering knowledge docs-health
atlas ai architecture-validate --json
```

## Continuidade

Detalhes executaveis (schema, migration, services, gates, tests) por capability vivem em `docs/ap/AP-###-cognitive-*.md` quando a capability sair de scaffold. Briefing operacional em `implementation-briefing.md`; roadmap em `roadmap.md` define prioridade.
