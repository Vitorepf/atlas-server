---
id: atlas-software-company-stewardship-stack
type: engineering_knowledge
title: Atlas Software Company Stewardship Stack
status: future
category: agentic-engineering
priority: 100
implementation_state: future_target_not_current_runtime
summary: Canonical umbrella stack for every capability that lets Atlas care for and improve software as a governed autonomous software company: Night Shift, Night Shift Product Mode, Area Focus Loop, Area Stewardship, Portfolio Stewardship, Autonomous Executive Layer and Self-Expanding Software Company.
human_summary: Nome canonico da pilha inteira de cuidado autonomo de software do Atlas.
human_what: Define o guarda-chuva, nomes, fronteiras e ordem de leitura para Night Shift, Product Mode, Area Focus e Stewardship.
human_purpose: Impedir que IAs inventem outro OS/nome/runtime para a mesma area e facilitar descoberta imediata.
human_input: Intencoes sobre Night Shift, areas, stewardship, portfolio, executive recommendations, autonomous software company e melhorias em loop.
human_output: Nome canonico, stack tree, regras para IA, docs filhos, fronteiras e prioridade de implementacao.
human_change_when: Mexa quando qualquer capability da familia Night Shift/Product Mode/Area Focus/Stewardship mudar nome, autoridade ou fronteira.
human_block_when: Bloqueie quando IA tentar criar OS novo, runtime paralelo, umbrella name concorrente ou claim de implementacao sem evidence.
tags:
  - atlas-ai
  - software-company
  - stewardship-stack
  - night-shift
  - area-focus-loop
  - area-stewardship
capabilities:
  - software_company_stewardship_stack
  - night_shift
  - night_shift_product_mode
  - area_focus_loop
  - area_stewardship
  - portfolio_stewardship
  - autonomous_executive_layer
  - self_expanding_software_company
decisions:
  - Atlas Software Company Stewardship Stack e o nome canonico da area inteira.
  - A stack vive dentro do Atlas Autonomous Software Company Runtime.
  - A stack nao e OS novo, runtime paralelo, domain runtime novo ou substituta do AAEOS.
  - Area Focus Loop e o motor de ciclos por area.
  - Area Stewardship e responsabilidade continua por uma area.
  - Portfolio Stewardship, Autonomous Executive e Self-Expanding vivem na ladder futura.
  - A prioridade atual de implementacao e Area Focus Loop para `agentic_engineering_os`.
  - Merge, deploy, secrets e destructive changes continuam proibidos sem operador.
maintenance:
  - Atualize este doc antes de criar qualquer doc novo sobre Night Shift, Product Mode, Area Focus, Stewardship, Portfolio ou Executive dentro da software company.
  - Mantenha este doc como o primeiro ponto de leitura para IAs.
  - Nao duplique esta stack com nomes como AGOS, Autonomous Company Genesis OS, Stewardship OS ou Night Shift OS.
related_paths:
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-software-company-stewardship-stack
graph_title: Atlas Software Company Stewardship Stack
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-autonomous-software-company-runtime
graph_status: future
graph_source: repo
human_name: Atlas Software Company Stewardship Stack
canonical_name: Atlas Software Company Stewardship Stack
technical_name: atlas-software-company-stewardship-stack
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
allowed_changes:
  - Refinar nomes, fronteiras, stack tree, AI rules, promotion gates and child doc links.
forbidden_changes:
  - Chamar a stack de OS novo.
  - Criar runtime paralelo a Autonomous Software Company Runtime, Night Shift, Product Mode, Dev, Forge, Self-Construction ou Evidence.
  - Declarar Portfolio, Executive ou Self-Expanding implementados sem runtime evidence.
depends_on:
  - atlas-autonomous-software-company-runtime
  - atlas-autonomous-software-company-night-shift
  - atlas-autonomous-software-company-night-shift-product-mode
  - atlas-area-stewardship-layer
  - atlas-stewardship-evolution-ladder
flows_to:
  - night_shift
  - product_mode
  - area_focus_loop
  - area_stewardship
  - stewardship_evolution_ladder
unlocks:
  - ai_discovery_for_stewardship_stack
  - area_focus_loop_priority
  - stewarded_software_company
governs:
  - atlas.software_company_stewardship_stack
  - atlas.night_shift
  - atlas.night_shift.product_mode
  - atlas.night_shift.area_focus_loop
  - atlas.area_stewardship
  - atlas.stewardship_evolution
evidence:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
ai_entrypoints:
  - Leia este doc primeiro quando a tarefa mencionar Night Shift, Product Mode, Area Focus Loop, Area Stewardship, Portfolio Stewardship, Autonomous Executive, Self-Expanding Software Company ou software company que melhora a si mesma.
  - Depois leia o doc filho especifico da capability que sera alterada.
quality_gates:
  - canonical-stack-name-required
  - child-doc-owner-required
  - no-new-os
  - no-parallel-runtime
  - evidence-required
  - operator-review-required
  - no-merge-without-operator
  - no-deploy-without-operator
failure_modes:
  - IA cria outro nome guarda-chuva para a mesma stack.
  - IA comeca em Portfolio/Executive antes de Area Focus Loop operacional.
  - IA chama Product Mode de runtime implementado sem cockpit/evidence.
  - IA cria executor paralelo para Night Shift ou Stewardship.
observability_signals:
  - stewardship_stack_level
  - current_priority_capability
  - active_area_id
  - child_doc_loaded
  - duplicate_name_blocked
next_actions:
  - Implementar Area Focus Loop read-only para `agentic_engineering_os`.
  - Conectar Area Focus Loop ao Product Mode e Morning Inbox.
  - Promover Area Stewardship somente depois de evidence do Area Focus Loop.
---
# Atlas Software Company Stewardship Stack

## Resumo

Atlas Software Company Stewardship Stack e o nome canonico da pilha inteira que
faz o Atlas cuidar, melhorar, governar e evoluir software autonomamente sob
review humano.

Frase canonica:

```text
Atlas Software Company Stewardship Stack e a pilha governada que permite ao
Atlas operar ciclos, areas, portfolios e decisoes executivas de uma empresa de
software autonoma, com evidence, branch isolation, inbox e aprovacao humana para
acoes irreversiveis.
```

## Papel no Atlas

Este doc e o guarda-chuva de descoberta. Qualquer IA que veja Night Shift,
Product Mode, Area Focus Loop, Area Stewardship, Portfolio Stewardship,
Autonomous Executive ou Self-Expanding Software Company deve carregar este doc
primeiro.

Ele nao substitui o Atlas Autonomous Software Company Runtime. Ele organiza a
stack de stewardship que vive dentro dele.

## Onde Se Encaixa

```text
Atlas Agentic Engineering OS
-> Atlas Autonomous Software Company Runtime
   -> Atlas Software Company Stewardship Stack
      -> Night Shift
      -> Night Shift Product Mode
      -> Area Focus Loop
      -> Area Stewardship Layer
      -> Portfolio Stewardship Layer
      -> Autonomous Executive Layer
      -> Self-Expanding Software Company
```

## Contratos

### Nome Canonico

Use sempre:

```text
Atlas Software Company Stewardship Stack
```

Slug:

```text
atlas-software-company-stewardship-stack
```

Aliases aceitos:

- Software Company Stewardship Stack;
- Stewardship Stack;
- stack de stewardship da software company;
- pilha de stewardship da empresa de software.

Nomes proibidos:

- Atlas Stewardship OS;
- Night Shift OS;
- Area Stewardship OS;
- AGOS;
- Autonomous Company Genesis OS;
- qualquer OS novo para esta mesma area.

### Stack Tree

| Nivel | Nome | Papel |
|---:|---|---|
| 1 | Night Shift | Roda ciclos sandboxed, primeiro no Atlas. |
| 2 | Night Shift Product Mode | Cockpit, onboarding, tiers, budget, kill switch e review. |
| 3 | Area Focus Loop | Foca uma area e busca bugs, gaps, specs e melhorias. |
| 4 | Area Stewardship Layer | Assume responsabilidade continua por uma area. |
| 5 | Portfolio Stewardship Layer | Coordena multiplas areas e dependencias. |
| 6 | Autonomous Executive Layer | Recomenda estrategia, alocacao e tradeoffs. |
| 7 | Self-Expanding Software Company | Propoe novas areas sob gates e operador. |

### Prioridade Atual

```text
Implementar Area Focus Loop para area_id: agentic_engineering_os.
```

Tudo acima disso e futuro ate existir evidence.

## Fluxo

```text
operator selects scope
-> stack resolves current level
-> child doc owns behavior
-> Area Focus Loop scans
-> Self-Directed Evolution drafts specs
-> Dev/Forge route work
-> Evidence proves claims
-> Product Mode/Morning Inbox asks operator decisions
-> outcomes feed Area Stewardship
```

## Regras Para IA

Se a tarefa mencionar qualquer item desta familia, faca:

1. Leia este doc.
2. Leia o child doc especifico.
3. Confirme que nao esta criando OS/runtime paralelo.
4. Confirme se o nivel e atual ou futuro.
5. Preserve operator review, evidence, branch isolation, WIP, budget e kill
   switch.

Mapa rapido:

| Se a tarefa mencionar | Leia depois deste doc |
|---|---|
| Night Shift | `atlas-autonomous-software-company-night-shift.md` |
| Product Mode | `atlas-autonomous-software-company-night-shift-product-mode.md` |
| Area Focus Loop | `atlas-autonomous-software-company-night-shift-product-mode.md` + AP-712 |
| Area Stewardship | `atlas-area-stewardship-layer.md` |
| Portfolio/Executive/Self-Expanding | `atlas-stewardship-evolution-ladder.md` |

## Escopo De Implementacao

Ordem obrigatoria:

1. Area Focus Loop read-only para `agentic_engineering_os`.
2. Area Focus Loop com findings e Morning Inbox.
3. Area Focus Loop com spec drafts.
4. Area Focus Loop com Dev/Forge routing.
5. Branch sandbox guardado.
6. Area Stewardship read-only.
7. Area Stewardship active.
8. Portfolio/Executive/Self-Expanding proposal-only.

## Dependencias

| Componente | Owner |
|---|---|
| Organizacao de software | Autonomous Software Company Runtime |
| Ciclos | Night Shift |
| Produto/cockpit | Night Shift Product Mode |
| Foco por area | Area Focus Loop |
| Stewardship por area | Area Stewardship Layer |
| Futuro portfolio/executivo | Stewardship Evolution Ladder |
| Specs | Self-Directed Evolution |
| Execucao | Atlas Dev / Forge |
| Prova | Evidence Certification Runtime |

## Evidencias

Claims aceitos exigem:

- doc/AP owner;
- runtime ou read-model quando claimar implementacao;
- evidence pack;
- operator inbox;
- tests/docs-health/architecture validation;
- prova de que nao criou OS/runtime paralelo.

## Riscos

| Risco | Mitigacao |
|---|---|
| IA inventa nome novo | Este doc e o guarda-chuva canonico. |
| IA pula para Executive cedo demais | Prioridade atual fixa: Area Focus Loop. |
| Product Mode vira bot perigoso | Budget, WIP, kill switch, inbox e no-merge/no-deploy. |
| Portfolio duplica Company Runtime | Portfolio decide entre areas; Company Runtime coordena organizacao. |

## Exemplos

Prompt correto:

```text
Implementar Area Focus Loop dentro do Atlas Software Company Stewardship Stack
para area_id: agentic_engineering_os.
```

Resposta esperada da IA:

```text
Vou ler atlas-software-company-stewardship-stack.md, depois Product Mode,
AP-712 e Area Stewardship. Nao vou criar OS novo; vou implementar o nivel atual:
Area Focus Loop.
```

## Proximas Acoes

1. Criar runtime/read-model do Area Focus Loop.
2. Criar Area Focus Inbox.
3. Conectar Self-Directed Evolution para spec drafts.
4. Conectar Dev/Forge routing.
5. Expor no Product Mode.
