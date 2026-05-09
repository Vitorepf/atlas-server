---
id: atlas-ai-cognitive-principles
type: engineering_knowledge
title: Atlas AI Cognitive Plane - Principles, Evidence Hierarchy, External Input Filter
status: active
category: architecture-governance
priority: 95
summary: 22 principios duros do Cognitive Plane (C1-C22) com fundamento cientifico, hierarquia de evidencia (consensus/emerging/contested/speculative) como gate de capability, filtro critico para contribuicoes de IA externa em 6 perguntas + 7 veredictos, anti-patterns transversais.
tags:
  - atlas-ai
  - cognitive
  - principles
  - evidence-level
  - external-input-filter
  - anti-patterns
capabilities:
  - cognitive_principles_governance
  - evidence_level_classifier
  - external_input_critical_filter
decisions:
  - 22 principios duros (C1-C22) governam toda decisao no Cognitive Plane; conflito entre principios e Tese central, Tese vence.
  - Toda capability cognitiva carrega `evidence_level` (consensus/emerging/contested/speculative); contested/speculative nao vira default sem Rivals validation.
  - Toda contribuicao de IA externa (Gemini, ChatGPT, livro, curso, guru) atravessa filtro de 6 perguntas e recebe 1 de 7 veredictos.
  - Texto de produto proibido prometer ganho cognitivo sem AP-99 longitudinal (C15).
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar quando novo principio C20+ for promovido ou nivel de evidencia de capability mudar.
  - Status `active` significa governanca canonica vigente; nao significa que toda capability cognitiva ja esta em produto final.
related_paths:
  - docs/engineering-knowledge-base/cognitive/README.md
  - docs/engineering-knowledge-base/cognitive/overview.md
  - docs/engineering-knowledge-base/cognitive/capabilities-core.md
  - docs/engineering-knowledge-base/cognitive/multiplier-edge.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
owner: atlas-ai
layer: 2
line_limit: 260
---

# Atlas AI Cognitive Plane — Principles, Evidence Hierarchy, External Input Filter

Governanca dura do Cognitive Plane.

## Authority

Em conflito: Tese central (Layer -1) > principios duros do Atlas (14) > principios cognitivos (22, este doc) > anti-patterns.

## Os 22 Principios Duros (C1-C22)

| # | Principio | Fundamento |
|---|---|---|
| C1 | Atlas nao ensina; Atlas treina. Operador e o aprendiz; o sistema e a academia. | Construcionismo (Papert) |
| C2 | Nao dominou se nao aplicou em contexto novo. `transfer_proof` e gate. | Transferencia Analogica |
| C3 | Esquecer e design, nao bug. Curva de decay e respeitada. | Bjork; FSRS |
| C4 | Predicao supera reacao. Curator propoe antes da necessidade. | analise de trajetoria |
| C5 | Cognitive load > threshold pausa o sistema. Sono/fadiga/estresse vencem currículo. | Sweller; NSDR |
| C6 | Conhecimento existe em grafo, nao em lista. | grafo cognitivo |
| C7 | Aprendizado e privado por default. Knowledge Graph nao vaza para provider. | privacy by design |
| C8 | Currículo e revisavel, nao imutavel. | iteracao Curator |
| C9 | Maestria exige os 4 pilares (teorico, pratico, cognitivo, transferencial). | desenho instrucional |
| C10 | Atlas e canal unico de aprendizado. Comprar curso externo e excecao auditada. | Tese central |
| C11 | IA externa e fonte ou proposta, nunca contrato direto. Filtro critico (secao abaixo). | Tese + governanca |
| C12 | Compressao temporal e mensuravel. Sem Rivals-Learning, Cognitive Plane vira filosofia. | Rivals analogo |
| C13 | Latencia do Atlas e funcao vital, nao UX. Lag cognitivo equivale a lapso de memoria; SLOs tem peso constitucional. | Mente Estendida (Clark/Chalmers) |
| C14 | Aprendizado profundo exige **erro preditivo calibrado**: o operador tenta prever/solver antes da resposta, compara com a realidade e atualiza o modelo mental. Dificuldade deve ser desejavel, nao frustracao aleatoria. | Generation Effect + Productive Failure + Active Inference como lente |
| C15 | Honestidade cientifica e gate de capability. Toda capability carrega `evidence_level`. Contested/speculative nao vira default sem Rivals. Sem promessas "+X%" sem AP-99. | governanca cientifica |
| C16 | Maestria gera artefato, nao resumo. `transfer_proof` exige output deployavel/publico/testavel. | Construcionismo |
| C17 | Atlas e Red Team, nao Yes Man. Em decisoes, Atlas tenta quebrar logica e aponta vies. | Kahneman; agent_behavior_contract |
| C18 | Foco e difuso sao complementares, nao opostos. Hiperfoco prolongado isolado degrada insight. | DMN; hipofrontalidade |
| C19 | Open loop e ferramenta deliberada, nao bug. Parar no meio (Hemingway) usa Zeigarnik a favor. | Efeito Zeigarnik |
| C20 | **Falha repetida e estagnacao; falha diversificada e exploracao.** Nao se aprende errando a mesma coisa — se aprende errando coisas diferentes. Atlas classifica `failure_signature`; repeticao da mesma assinatura dispara alerta; falha com assinatura nova marca progresso. | Productive Failure (Kapur) + Bayesian updating |
| C21 | **Maestria operacional vem de Worked Examples + Fading, nao de pratica direta cega.** Em dominios tecnicos, processo de aprender e: ver solucao completa -> ver com etapas faltando -> resolver sozinho. Pular para pratica direta sem scaffolding e ineficiente. | Sweller; Renkl |
| C22 | **Padroes humanos sao reusaveis como Design Patterns GoF.** Decisao/processo/comunicacao/recuperacao de falha tem padroes nomeados, com contexto, problema, solucao, evidencia pessoal e anti-patterns. Process Pattern Catalog e Latticework de Munger formalizado. | GoF Design Patterns + Munger Latticework |

E heranca Atlas (14 principios duros): Surface nao decide o que estudar, Provider nao escolhe currículo, Tool nao promove mastery, Domain `learning` nao muta calendario, Self-Improvement nao auto-matricula, Tudo repetido vira Core.

## C14 — Erro Preditivo Calibrado

**Definicao:** erro preditivo e a diferenca entre o modelo mental atual do operador ("eu acho que a causa/solucao e X") e a realidade validada depois ("a causa/solucao era Y"). O Atlas usa essa diferenca como motor pedagogico.

Nao e "fazer o operador errar por errar". E um ciclo controlado:

```text
prever / tentar -> revelar realidade -> comparar divergencia -> extrair principio -> transferir
```

### Contrato operacional

| Campo | Obrigatorio |
|---|---|
| `prediction_prompt` | problema cru antes da teoria |
| `operator_prediction` | hipotese, tentativa ou solucao inicial |
| `validated_reality` | resposta canonica, worked example, teste ou evidencia real |
| `prediction_error_delta` | o que divergiu entre previsao e realidade |
| `model_update` | principio novo que corrige o modelo mental |
| `transfer_probe` | caso futuro para provar que a correcao transferiu |

### Guardrails

- O problema deve ser calibrado por `dreyfus_stage`, carga cognitiva e contexto; muito facil gera tedio, muito dificil gera ruido.
- C14 nunca autoriza humilhacao, overload, clickbait ou "hard mode" permanente.
- Frases absolutas como "sem erro nao ha aprendizado" devem ser lidas como regra de design, nao como tese biologica literal.
- Medidas de ganho exigem AP-99/Rivals-Learning; sem isso, usar linguagem: "pode aumentar retencao", "evidencia sugere", "experimento".

## Hierarquia de Evidencia Cientifica (gate de capability)

C15 exige que toda capability cognitiva carregue `evidence_level`. Quatro niveis canonicos:

| Nivel | Definicao | Tratamento |
|---|---|---|
| `consensus` | consenso amplo na ciencia cognitiva; replicacoes multiplas; uso em pratica clinica/educacional madura | pode entrar como default apos teste de contrato |
| `emerging` | promissor, evidencia inicial solida, ainda em consolidacao | entra como opcional/preview; vira default apos AP-99 + Rivals |
| `contested` | estudos divergem; replicacoes fracas; ganho disputado | so opcional gamificado; nunca default; **proibido prometer ganho** |
| `speculative` | conceito interessante sem validacao empirica robusta; folclore ou laboratorio sem traducao pratica | nao entra como capability; vira proposal pro Curator com `requires_rivals_validation` |

### Classificacao de exemplos

| Conceito | Nivel |
|---|---|
| FSRS, Retrieval Practice, Pratica Deliberada, Generation Effect, Cognitive Load Theory, DMN, Construcionismo, Mente Estendida, Adversarial/Red Team, Cognitive Flexibility | `consensus` |
| Aprendizagem Perceptiva (PLMs em dominios visuais) | `consensus` em escopo |
| Leitura Incremental (Wozniak), Hemingway/Zeigarnik tatico, Pareto Discovery em currículo, Predictive Curriculum, TMR em laboratorio | `emerging` |
| Dual N-Back para ganho de QI | `contested` |
| Acetilcolina como prescricao operacional, frequencias binaurais, "compila codigo dormindo", promessas "+X% retencao" sem AP-99, TMR como produto sem hardware | `speculative` |

### Governanca

- Capability nova entra com `evidence_level` declarado no manifest
- Promover de `emerging` para `consensus` exige AP-99 cognitivo + Rivals-Learning positivo
- Capability `contested`/`speculative` que vira default sem promocao formal e bug arquitetural; bloqueado por `architecture-validate`
- Texto de produto **proibido prometer ganho cognitivo sem AP-99**. Linguagem permitida: "literatura sugere", "pode ajudar", "experimento opcional"

## Filtro Critico de Contribuicoes Externas

Esta secao e cardinal. O Atlas e canal unico. Toda contribuicao externa (Gemini, ChatGPT, livro, paper, curso, guru, video, blog) e **fonte ou proposta**, nao contrato.

### Por que IA externa nao serve para projetar o Atlas

| Limitacao | Impacto |
|---|---|
| Nao conhece a Tese (multiplicador/canal unico) | sugere produtos paralelos que vazariam Evidence |
| Nao conhece o pipeline canonico de 17 etapas | propoe atalhos que pulam Decision Receipt |
| Nao conhece anti-duplicacao Core vs Domain | mistura capability horizontal com domain |
| Nao conhece runtime boundaries | sugere "build proprio" onde existe Core |
| Nao conhece os 14 principios duros + 22 cognitivos | recomenda violacao silenciosa |
| Conhece estado-da-arte academico | util como insumo, nunca como contrato |
| Nao tem Evidence do operador | sugere generico, nao calibrado |

### O Filtro — 6 perguntas

Toda contribuicao externa entra como `external_input_proposal` e atravessa:

1. Multiplica output cognitivo do operador, ou compete com o canal Atlas?
2. Cria capability horizontal (Core), domain (Domain) ou produto paralelo (descartar)?
3. Viola algum dos 14 principios duros do Atlas ou os 22 cognitivos?
4. Pode ser absorvida como **fonte/insumo** dentro do pipeline canonico?
5. Existe AP-99 ou Evidence que confirme ganho, ou e opiniao?
6. Reduz o ciclo de maestria, ou apenas reorganiza-o?

### Os 7 veredictos

| Veredicto | Acao |
|---|---|
| `accepted_as_source` | absorvida via Research domain ou Content Intelligence |
| `accepted_as_capability` | promovida ao Core via AP curto + spec + teste |
| `accepted_as_specialist_profile` | vira specialist profile dentro de `learning` |
| `filtered_partial` | parte aceita, parte rejeitada; documentar |
| `rejected_compete_with_atlas` | descarte com motivo registrado |
| `rejected_violates_principle` | descarte com principio violado |
| `requires_rivals_validation` | so entra apos Rivals-Learning provar multiplicador positivo |

### Exemplos preventivos

| Sugestao tipica de IA externa | Veredicto provavel |
|---|---|
| "Use Anki" | `accepted_as_capability` (SRS no Core, sem dependencia externa) |
| "Crie app proprio gamificado" | `rejected_compete_with_atlas` (App canonico ja e surface) |
| "Use Feynman" | `accepted_as_capability` (ja no Core) |
| "Pague o curso X" | `filtered_partial` (curso vira fonte; ato de pagar vira proposal pro Curator) |
| "Treine fluencia com tutor humano" | `accepted_as_source` (humano e source/event no ledger) |
| "Modelo proprio para tudo" | `rejected_violates_principle` (modelo proprio so como pre/pos-processador) |
| "Agente que estuda por voce" | `rejected_compete_with_atlas` (estudo e do operador) |

## Anti-patterns Transversais

| Anti-pattern | Por que evitar |
|---|---|
| Comprar curso e replicar a estrutura no Atlas | Atlas e o produtor; reproduzir e ciclo de morte |
| Marcar dominado apos finalizar trilha sem transferencia | viola C2 e C9 |
| Revisar topico semanalmente sem SRS | viola C3 — decay e design |
| Ignorar load alto e forcar deep work | viola C5 — colapso de adesao |
| Listar topicos em vez de mapear grafo | viola C6 |
| Mandar Knowledge Graph cru para provider externo | viola C7 |
| Curador matricular automaticamente | viola C11 e governanca de proposal |
| Tratar sugestao do Gemini/ChatGPT como contrato | viola C11 |
| Construir app proprio gamificado paralelo | viola Tese de canal unico |
| Currículo eterno imutavel | viola C8 |
| Otimizar tempo sem medir transferencia | compressao sem qualidade = regressao silenciosa |
| Rodar Multi-Provider Debate em flow continuo | viola C13 (latencia) e custo |
| Identity Tracker afirmativo (push motivacional) | viola C15 — autoajuda |
| Calibracao Epistemica em flow continuo | burocracia cognitiva |
| Capability `contested`/`speculative` virando default sem Rivals | viola C15 |

## Continuidade

Capabilities cognitivas concretas em `capabilities-core.md` e `multiplier-edge.md`. Implementacao executavel em `docs/ap/AP-###-cognitive-*.md`; briefing operacional em `implementation-briefing.md`.
