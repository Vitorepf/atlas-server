---
id: atlas-documentation-reality-evolution-ladder
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Documentation Reality Evolution Ladder
slug: atlas-documentation-reality-evolution-ladder
status: planned
category: documentation-governance
priority: 99
summary: Escada canonica de evolucao do ADRS — L0 imune (hoje), L1 gerativo/antifragil, L2 ancorado-em-realidade e formador-de-intencao, e a assintota L-inf auto-modelo reflexivo. Define a ordem inviolavel e o gate de promocao de cada degrau. Nao e runtime; e o mapa que orienta a evolucao sem perder o rumo.
human_summary: O mapa de para onde o ADRS pode crescer, em degraus. Cada degrau e uma categoria nova, sobe um por vez e so com prova, e o topo (conhecer a si mesmo) e uma direcao, nao um sprint.
human_what: Indice-mae dos niveis de evolucao do sistema de realidade documental, do imune atual ate o auto-modelo reflexivo.
human_purpose: Garantir que o Atlas saiba qual e o proximo degrau real do seu substrato de verdade, e nunca pule etapa construindo castelo na areia.
human_input: Recebe o estado atual do ADRS (L0), os docs dos niveis L1/L2/L-inf e as provas de maturidade de cada um.
human_output: Entrega a ordem de evolucao, o salto de categoria de cada degrau e o gate que autoriza subir.
human_change_when: Mexa quando um nivel mudar de natureza, ou quando um degrau for promovido de north-star para runtime provado.
human_block_when: Bloqueie qualquer tentativa de pular degrau (ex.: construir L2 antes do L1 provado, ou tratar L-inf como sprint).
canonical_name: Atlas Documentation Reality Evolution Ladder
technical_name: AtlasDocumentationRealityEvolutionLadderService
cartography_type: index
tags:
  - atlas-ai
  - documentation-governance
  - adrs
  - evolution-ladder
  - antifragile
  - reflective
capabilities:
  - documentation_reality_evolution_map
  - level_promotion_gate
  - category_shift_taxonomy
  - sequencing_discipline
decisions:
  - O ADRS tem quatro niveis canonicos: L0 imune, L1 gerativo/antifragil, L2 ancorado-em-realidade, L-inf auto-modelo reflexivo.
  - A ordem e inviolavel: nenhum nivel abre antes do anterior estar provado (drift zero, gates verdes).
  - Cada nivel e doc filho proprio com gate proprio; esta escada e so o indice, nao a especificacao deles.
  - L-inf e assintota (direcao), nao alvo de sprint; tratar como entregavel proximo e proibido.
  - O teto do ADRS e, na pratica, o teto do Atlas inteiro; por isso subir a escada e o maior multiplicador composto do sistema.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando um nivel mudar de natureza ou for promovido a runtime.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
graph_id: atlas-documentation-reality-evolution-ladder
graph_title: Atlas Documentation Reality Evolution Ladder
graph_world: atlas
graph_layer: system
graph_kind: index
graph_parent: atlas-documentation-reality-system
graph_status: planned
graph_source: repo
owner: documentation-governance
implementation_state: north_star_no_runtime_yet
depends_on:
  - atlas-documentation-reality-system
  - atlas-documentation-reality-block-upgrade-map
flows_to:
  - atlas-documentation-reality-generative-leap
  - atlas-documentation-reality-outcome-grounded-leap
  - atlas-documentation-reality-reflective-self-model
unlocks:
  - rung_by_rung_evolution
  - no_castle_on_sand
governs:
  - documentation-governance-evolution
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-block-upgrade-map.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-generative-leap.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-leap.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-reflective-self-model.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-evolution-ladder.md
allowed_changes:
  - Refinar a taxonomia dos niveis e os gates de promocao.
  - Adicionar um nivel intermediario se uma categoria nova e real surgir entre dois degraus.
forbidden_changes:
  - Permitir pular degrau ou abrir um nivel sem o anterior provado.
  - Tratar L-inf como alvo de implementacao proxima.
  - Duplicar a especificacao dos niveis aqui; esta doc e indice, os filhos sao a fonte.
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
requires_evidence: false
risk_level: critical
line_limit: 520
ai_entrypoints:
  - Leia esta escada para saber qual e o proximo degrau real do ADRS antes de propor qualquer evolucao.
  - Use os gates de promocao para decidir se um nivel pode abrir.
ai_usage_notes:
  - Esta doc e indice/mapa, nao especificacao; o detalhe de cada nivel vive no doc filho correspondente.
  - Nenhum nivel aqui esta declarado como runtime; promocao exige codigo que resolve no indice com drift zero.
next_actions:
  - Concluir L0 write-bound (blindar) antes de abrir L1.
  - Manter L2 e L-inf como north-star ate o degrau anterior estar provado.
---

# Atlas Documentation Reality Evolution Ladder

## Resumo

Esta escada e o **indice canonico** de para onde o ADRS pode crescer. Cada degrau
e uma **categoria nova** (salto de tipo, nao de grau), sobe-se **um por vez e so
com prova**, e o topo e uma **direcao**, nao um sprint.

```text
L0  Imune              -> mantem o Atlas vivo            (ADRS hoje)
L1  Gerativo/Antifragil -> faz o Atlas compor             (doc: generative-leap)
L2  Ancorado-em-Realidade -> acerta o alvo certo no mundo (doc: outcome-grounded-leap)
L-inf Auto-modelo Reflexivo -> o Atlas se conhece         (doc: reflective-self-model)
```

## Papel no Atlas

O ADRS e o substrato que decide quao bem **toda** outra parte do Atlas pode ser
construida e verificada. Logo, o teto do ADRS e, na pratica, o teto do Atlas
inteiro — e subir esta escada e o maior multiplicador composto do sistema.

Sem este indice, cada IA inventaria seu proprio "proximo nivel" e o Atlas
perderia o rumo da propria evolucao. Com ele, ha uma unica resposta canonica para
"qual e o proximo degrau real?".

## Onde Se Encaixa

```text
atlas-documentation-reality-system (mae, L0 imune)
  -> atlas-documentation-reality-block-upgrade-map (blinda L0 ao maximo robusto)
    -> ESTA escada (indice dos niveis)
       -> L1 generative-leap
       -> L2 outcome-grounded-leap
       -> L-inf reflective-self-model
```

## Contratos

A verdade-mae que cada degrau guarda muda de categoria:

| Nivel | Natureza | Verdade que guarda | Salto de categoria |
|---|---|---|---|
| L0 | imune (reativo) | "o codigo bate com o doc?" | — |
| L1 | gerativo/antifragil | "vai bater? + se reconcilia e se imuniza" | reativo -> proativo |
| L2 | ancorado-em-realidade | "o **resultado** bate com a **intencao**, no mundo?" | interno -> externo |
| L-inf | auto-modelo reflexivo | "o que eu **nao sei** sobre mim mesmo?" | objeto -> sujeito |

Gate de promocao (igual para todo degrau):

```text
nivel anterior provado (drift zero, gates verdes)
+ codigo que resolve no indice para as capabilities do degrau
+ teste que prova o comportamento novo
= autorizado a abrir
```

## Fluxo

```text
L0 blindado (write-bound)
  -> L1 aberto: P1 preditivo -> P2 gerativo -> P3 auto-imunizante (provados)
    -> L2 aberto: O1 outcome-grounded -> O2 intent co-forming -> O3 multi-estate
      -> L-inf: horizonte que orienta; nunca sprint
```

## Regras para IA

- NUNCA abrir um degrau antes do anterior estar provado.
- NUNCA tratar L-inf como entregavel proximo; e assintota.
- NUNCA duplicar a especificacao de um nivel aqui; o doc filho e a fonte.
- Toda promocao de nivel passa pelo gate de maturidade com drift zero.

## Escopo de Implementacao

Esta doc e **somente indice/mapa**. Nenhum runtime e criado por ela. Cada nivel
tem seu proprio doc filho com seu proprio escopo de implementacao e seus proprios
gates.

## Dependencias

- atlas-documentation-reality-system (L0, doc-mae).
- atlas-documentation-reality-block-upgrade-map (blindagem do L0).
- Os tres docs filhos de nivel (L1, L2, L-inf).

## Evidencias

Doc de mapa (north-star). A evidencia e o proprio contrato + o doc-mae ADRS.
Nenhum nivel esta declarado como runtime.

## Riscos

- **Pular degrau:** abrir L2/L-inf sem o anterior provado. Mitigacao: gate de promocao inviolavel.
- **Indice virar especificacao:** esta doc inchar e competir com os filhos. Mitigacao: aqui so o mapa.
- **L-inf virar promessa:** tratar a assintota como roadmap de curto prazo. Mitigacao: e direcao, nao sprint.

## Exemplos

```text
Pergunta: "qual o proximo degrau do ADRS?"
Resposta canonica: depende do estado provado.
  - L0 ainda nao write-bound -> proximo passo e blindar, nao L1.
  - L0 blindado, L1 nao provado -> proximo e P1 do L1.
  - L1 provado -> ai sim abre O1 do L2.
```

## Proximas Acoes

- Blindar L0 (write-bound) — pre-requisito de toda a escada.
- Manter L1/L2/L-inf como north-star ate cada degrau anterior estar provado.
