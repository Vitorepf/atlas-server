---
id: atlas-agentic-engineering-documentation-inventory
type: engineering_knowledge
title: Atlas Agentic Engineering Documentation Inventory
status: active
category: agentic-engineering
priority: 99
summary: Inventario canonico por familias de documentos para Atlas Agentic Engineering, Atlas Dev, Atlas Forge, Atlas Code, TEOS, Rivals, provider dossiers, research e docs part/handoff. Classifica o que e autoridade, filho, surface, benchmark, north-star, research ou historico para evitar leitura caotica por IA.
tags:
  - atlas
  - agentic-engineering
  - documentation-inventory
  - atlas-dev
  - atlas-forge
  - atlas-code
  - rivals
capabilities:
  - agentic_engineering_doc_inventory
  - scattered_doc_classification
  - ai_safe_reading_order
  - anti_duplicate_doc_cleanup
decisions:
  - Este inventario classifica familias de documentos; o Authority Map decide a hierarquia.
  - Docs `part-*`, `session-handoff-*`, prompts one-shot e reports nao sao autoridade primaria.
  - Docs Rivals/Superiority sao benchmark/estrategia; nao governam runtime sem contrato canonico.
  - Provider dossiers e disseccoes externas sao research/advisory; nao viram drivers, defaults ou arquitetura sem promocao.
  - Atlas Code docs com OS no nome sao escopo local/surface-bound; Atlas Code continua surface.
maintenance:
  - Atualize quando nova familia de docs Dev, Forge, Atlas Code, Rivals, TEOS, provider ou research for criada.
  - Se um doc novo nao encaixar em nenhuma familia, registre como gap antes de usa-lo para implementacao.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-dev-index.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-desktop-code-surface.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-agentic-engineering-documentation-inventory
graph_title: Atlas Agentic Engineering Documentation Inventory
graph_world: atlas
graph_layer: system
graph_kind: index
graph_parent: atlas-agentic-software-engineering-authority-map
graph_status: active
graph_source: repo
human_name: Atlas Agentic Engineering Documentation Inventory
canonical_name: Atlas Agentic Engineering Documentation Inventory
technical_name: atlas-agentic-engineering-documentation-inventory
cartography_type: index
canonical_source: docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md
allowed_changes:
  - Adicionar familia, regex, classe, autoridade e doc dono quando novos docs surgirem.
  - Rebaixar familia para historical/source_material quando doc-mae canonico a substituir.
forbidden_changes:
  - Usar este inventario como substituto do Authority Map.
  - Promover doc research, benchmark, handoff ou prompt a autoridade sem atualizar Authority Map, START_HERE, README e glossary.
  - Apagar familia historica sem plano de archive e evidence de substituicao.
depends_on:
  - atlas-agentic-software-engineering-authority-map
  - atlas-ai-documentation-operating-system
  - atlas-canonical-glossary-and-naming
flows_to:
  - atlas-dev-index
  - atlas-programming-forge-flow
  - atlas-desktop-code-surface
  - atlas-programming-superiority-architecture
unlocks:
  - doc-family-navigation
  - safe-cleanup-of-scattered-agentic-engineering-docs
governs:
  - agentic_engineering_documentation_inventory
  - dev_forge_code_doc_classification
  - rivals_research_doc_classification
evidence:
  - docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "rg --files docs/engineering-knowledge-base | rg -i 'atlas-code|atlas-dev|forge|rivals|superiority|temporal|teos|agentic'"
requires_evidence: true
risk_level: high
visual_tags:
  - inventory
  - documentation
  - agentic-engineering
ai_entrypoints:
  - Leia este inventario depois do Authority Map quando encontrar muitos docs Dev, Forge, Atlas Code, TEOS, Rivals, provider ou research parecidos.
ai_usage_notes:
  - Use a coluna Classe para saber se um doc pode governar implementacao ou se e apenas contexto.
  - Se um arquivo `part-*` contradiz o doc-mae, o doc-mae vence.
quality_gates:
  - docs-health
  - all-new-docs-classified
  - no-unclassified-os-name
  - no-research-as-authority
failure_modes:
  - IA le um handoff antigo e ignora doc-mae.
  - IA usa Rivals como prova de superioridade sem evidence real.
  - IA usa provider dossier como autorizacao de driver automatico.
  - IA cria outro index porque nao achou a familia correta.
observability_signals:
  - familias classificadas
  - docs-health status
  - authority map links
  - unclassified docs found by rg
next_actions:
  - Evoluir docs-health para exigir `authority_class` em novos docs desta area.
  - Criar script de inventario automatico quando a quantidade de docs crescer.
line_limit: 520
---
# Atlas Agentic Engineering Documentation Inventory

## Resumo
Este inventario organiza a documentacao espalhada de Agentic Engineering,
Atlas Dev, Forge, Atlas Code, TEOS, Rivals, providers e research por familias.

Use junto com:

```text
1. atlas-ai-knowledge-governance-system.md
2. atlas-agentic-software-engineering-authority-map.md
3. este inventario
```

O Authority Map responde **quem manda**. Este inventario responde **como ler as
familias de arquivos sem se perder**.

## Papel no Atlas
O Atlas tem muitos documentos validos, mas nem todos tem o mesmo nivel de
autoridade. Este inventario impede que uma IA trate documento auxiliar como
lei, ou que duplique arquitetura porque encontrou nomes parecidos.

## Onde Se Encaixa
```text
Knowledge Governance
-> Agentic Software Engineering Authority Map
   -> Documentation Inventory
      -> doc family -> doc owner -> implementation context
```

## Contratos
### Classes

| Classe | Pode governar implementacao? | Regra |
|---|---:|---|
| `mother` | Sim | Define nome, area ou sistema-mae. |
| `authority-map` | Sim | Resolve hierarquia entre docs. |
| `index` | Parcial | Navega para docs donos; nao duplica conteudo. |
| `contract` | Sim | Define invariantes, schemas, gates e DoD. |
| `runbook` | Sim, dentro do contrato | Define execucao operacional. |
| `surface` | Sim para UX | Nao governa runtime-mae. |
| `benchmark` | Nao para arquitetura | Mede, compara, reporta ou desenha bateria. |
| `north-star` | Nao como runtime atual | Define alvo futuro. |
| `research` | Nao | Fonte externa/advisory ate promocao canonica. |
| `handoff` | Nao | Snapshot de sessao; contexto historico. |
| `part` | Nao sozinho | Fragmento de doc-mae; leia o doc-mae primeiro. |
| `prompt` | Nao | Instrucao operacional descartavel/versionada. |

### Familias Canonicas

| Familia / Padrao | Classe | Doc dono / entrada correta | Observacao |
|---|---|---|---|
| `atlas-agentic-engineering-os*.md` | mother/contract | `atlas-agentic-engineering-os.md` | Nome e camada-mae da area. |
| `atlas-agentic-software-engineering-authority-map.md` | authority-map | ele mesmo | Hierarquia obrigatoria. |
| `atlas-agentic-engineering-documentation-inventory.md` | index | este doc | Classificacao de familias. |
| `atlas-autonomous-software-company-runtime.md` | contract | ele mesmo | Empresa de software autonoma como runtime organizacional. |
| `atlas-autonomous-engineering-operating-system.md` | contract | ele mesmo | Loop autonomo de engenharia. |
| `atlas-real-engineering-execution-kernel.md` | contract | ele mesmo | Execucao real, nao claim narrativo. |
| `atlas-programming-governance-system*.md` | mother/contract/runbook | `atlas-programming-governance-system.md` | Lei da programacao por IA. |
| `atlas-dual-core-engineering-system.md` | contract | ele mesmo | Fronteira Dev/Forge. |
| `atlas-dev-index.md` | index | ele mesmo | Entrada obrigatoria de Atlas Dev. |
| `atlas-dev-glossary.md`, `atlas-dev-policy.md`, `atlas-dev-patamares.md` | contract/index | `atlas-dev-index.md` | Termos, policy e maturidade Dev. |
| `atlas-dev-efficient-programming-flow-v1*.md` | contract/runbook/part | `atlas-dev-efficient-programming-flow-v1.md` | Partes sao fragmentos; doc-mae vence. |
| `atlas-dev-flow-map-and-product-options-v1*.md` | planning/part | `atlas-dev-index.md` | Caderno de campanha; nao substitui policy/contract. |
| `atlas-dev-native-capabilities-completion-plan.md` | planning | `atlas-dev-index.md` | Backlog/closure de capacidades Dev. |
| `atlas-dev-forge-*` | audit/plan | `atlas-dual-core-engineering-system.md` | Historico e consolidacao; boundary doc vence. |
| `atlas-programming-forge-flow.md` | mother/flow | ele mesmo | Entrada do fluxo pesado. |
| `atlas-forge-continuum-os.md` | mother | ele mesmo | Continuum pesado: Obra, providers, fallback, review, evidence, Rivals. |
| `atlas-forge-operating-system*.md` | contract/runbook | `atlas-forge-operating-system.md` | Fabrica Forge dentro do Continuum. |
| `atlas-forge-obra-enterprise-loop-upgrade.md` | contract | Forge Continuum | Upgrade operacional de Obra enterprise. |
| `atlas-forge-provider-*`, `atlas-forge-governed-provider-*` | contract/module | Forge Continuum | Provider topology/capacity/invocation; nao e OS separado. |
| `atlas-forge-live-execution-*`, `atlas-forge-runtime-certification-*` | runtime/certification | Forge Continuum | Runtime/evidence/certification do Forge. |
| `atlas-forge-work-packet-*` | contract | Forge OS | Capabilities e packets internos. |
| `atlas-forge-continuum-os-session-handoff-*` | handoff/part | Forge Continuum | Snapshot historico; nunca fonte primaria. |
| `atlas-code-*.md` | surface/contract | `atlas-desktop-code-surface.md` | Atlas Code e surface/cockpit. |
| `atlas-code-category-evolution.md` | surface-category | Atlas Code + Authority Map | Define categoria de produto/surface EOS; nao nomeia a area inteira. |
| `atlas-code-*operating-system*.md`, `atlas-code-*workspace-os*.md` | surface-local | Authority Map + doc local | `OS` e escopo local/surface-bound. |
| `atlas-code-scor-1*.md` | surface/implementation-contract | `atlas-code-long-session-programming-cockpit.md` | Proximo nivel da surface Code. |
| `atlas-code-forge-*` | surface/forge-ux | Atlas Code + Forge Flow | UX do Forge no Code; nao Forge OS. |
| `atlas-code-enterprise-certification.md` | certification | Atlas Code surface | Certifica surface; nao benchmark Rivals. |
| `atlas-temporal-engineering-operating-system*.md`, `atlas-teos-*` | north-star/plan | `atlas-temporal-engineering-operating-system.md` | Camada temporal; nao runtime-mae de programming. |
| `atlas-programming-superiority-*.md` | strategy/benchmark-architecture | `atlas-programming-superiority-architecture.md` | Arquitetura de vantagem; sem claim externo sem benchmark. |
| `atlas-rivals-*`, `atlas-forge-rivals-*`, `thesis/rivals-*` | benchmark | docs Rivals especificos | Bateria, scoring, report, harness; nao produto. |
| `atlas-cursor-*`, `atlas-antigravity-*`, provider dossiers | research/provider-contract | Provider Evolution + Rivals | Advisory ate smoke, evidence, license/security e promocao. |
| `atlas-claude-code-subscription-governance-v1.md` | provider-governance | ele mesmo | Uso seguro de Claude Code subscription. |
| `atlas-agentic-workcell-runtime.md` | cross-cutting runtime | ele mesmo + Authority Map | Organiza trabalho; nao substitui Dev/Forge. |
| `atlas-intelligence-factory-os.md` | cross-cutting factory | ele mesmo + Authority Map | Cria capabilities; nao substitui Agentic Engineering OS. |
| docs em `dissecar/spec/*` | research/dissection | research docs mae | Material externo; promocao exige decisao canonica. |
| prompts `*one-shot-prompt*`, `*goal-prompt*` | prompt | doc owner citado no prompt | Instrucao para ferramenta; nao arquitetura. |

## Fluxo
Quando uma IA encontra um documento desta area:

```text
1. Identificar familia pelo nome do arquivo.
2. Consultar a tabela acima.
3. Abrir o doc dono indicado.
4. Checar status e implementation_state.
5. Usar docs part/handoff/research apenas como contexto.
6. Implementar somente se doc mother/contract/runbook autorizar.
```

## Regras para IA
- Nunca comece por `part-*`.
- Nunca comece por `session-handoff-*`.
- Nunca use Rivals como arquitetura primaria.
- Nunca use provider dossier como default automatico.
- Nunca trate Atlas Code como runtime-mae.
- Nunca trate TEOS como runtime atual se `status`/`implementation_state` disser north-star/planned.
- Sempre suba conflito para o Authority Map.

## Escopo de Implementacao
Este doc nao exige mover arquivos agora. Ele cria a camada de leitura segura.

Implementacoes futuras recomendadas:

1. campo `authority_class` em frontmatter;
2. comando `atlas:docs:agentic-engineering-inventory --json`;
3. docs-health semantico para bloquear novo `*os*.md` sem parent claro;
4. Cartografia mostrando classe documental no inspector;
5. Atlas Code exibindo doc dono antes de iniciar Obra de programacao.

## Dependencias
- `atlas-agentic-software-engineering-authority-map.md`
- `atlas-ai-documentation-operating-system.md`
- `atlas-canonical-glossary-and-naming.md`
- `atlas-dev-index.md`
- `atlas-programming-forge-flow.md`

## Evidencias
Este inventario foi derivado de busca nos docs canonicos por:

```bash
rg --files docs/engineering-knowledge-base | rg -i 'atlas-code|atlas-dev|forge|rivals|superiority|temporal|teos|agentic'
```

E de leitura dos docs donos principais: Agentic Engineering OS, Programming
Governance, Dev Index, Forge Flow, Forge Continuum, Forge OS, Atlas Code
Surface, TEOS e Programming Superiority.

## Riscos
- Familia nova nascer fora da tabela.
- Doc com `OS` no nome parecer autoridade-mae.
- Part doc ficar mais atualizado que doc-mae.
- Research externo virar decisao por entusiasmo.
- Benchmark virar claim de superioridade.

## Exemplos
Arquivo encontrado: `atlas-forge-continuum-os-session-handoff-2026-05-16-part-03.md`.

Uso correto:

```text
class = handoff/part
ler apenas como contexto historico
autoridade = atlas-forge-continuum-os.md
```

Arquivo encontrado: `atlas-code-multi-project-workspace-os.md`.

Uso correto:

```text
class = surface-local
autoridade de escopo de project/workspace dentro do Atlas Code
nao e sistema-mae da area
```

## Proximas Acoes
- Adicionar `authority_class` nos novos docs desta area.
- Considerar migrar handoffs antigos para archive quando a equipe decidir.
- Criar check automatizado para docs com `OS` no titulo exigirem parent claro.
