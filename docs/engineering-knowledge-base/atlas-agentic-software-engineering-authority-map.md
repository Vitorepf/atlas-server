---
id: atlas-agentic-software-engineering-authority-map
type: engineering_knowledge
title: Atlas Agentic Software Engineering Authority Map
status: active
category: agentic-engineering
priority: 100
summary: Mapa canonico de autoridade para docs de Agentic Software Engineering no Atlas. Define a hierarquia entre Atlas Agentic Engineering OS, Autonomous Software Company Runtime, Autonomous Engineering OS, Programming Governance, Atlas Dev, Forge, Atlas Code, TEOS, Rivals, research docs e docs historicos para impedir duplicacao e confusao por IA.
tags:
  - atlas
  - agentic-engineering
  - authority-map
  - documentation-governance
  - atlas-dev
  - atlas-forge
  - atlas-code
capabilities:
  - agentic_engineering_authority_map
  - forge_dev_code_disambiguation
  - documentation_hierarchy_guard
  - ai_safe_doc_navigation
  - anti_duplicate_architecture_governance
decisions:
  - Agentic Software Engineering e a categoria de mercado; Atlas Agentic Engineering OS e o sistema-mae canonico do Atlas nessa categoria.
  - Atlas Code e surface/cockpit/produto visual; nunca e runtime-mae, dominio, Forge ou Atlas Dev.
  - Atlas Dev e fast path governado para programacao eficiente; Atlas Forge e fabrica pesada; eles sao irmaos sob governanca, nao pai/filho.
  - Atlas Programming Governance System define a lei de programacao; Forge, Dev e Atlas Code consomem essa lei.
  - Atlas Forge Continuum OS define continuidade, provider topology, fallback, review, evidence e Rivals para programacao pesada; Forge Operating System e a fabrica operacional dentro desse continuum.
  - TEOS e north-star temporal de continuidade; nao substitui Atlas Dev, Forge, Programming Governance ou Agentic Engineering OS.
  - Visual Canon Blocks sao projections derivadas de docs/schemas/evidence para acelerar leitura humana e IA; nunca substituem docs canonicos.
  - Rivals, Superiority, benchmark e dissection docs sao avaliacao/estrategia/pesquisa; eles nao viram autoridade de arquitetura sem promocao canonica.
  - Docs de handoff, parts, prompts e implementation contracts sao material operacional ou filho; nao podem competir com docs-mae.
maintenance:
  - Atualize este doc sempre que um novo doc de Atlas Dev, Forge, Atlas Code, Agentic Engineering, Rivals, TEOS ou Programming Governance for criado.
  - Use este doc antes de limpar, arquivar, renomear ou promover documentos nessa area.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-contracts.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-code-long-session-programming-cockpit.md
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-agentic-software-engineering-authority-map
graph_title: Atlas Agentic Software Engineering Authority Map
graph_world: atlas
graph_layer: system
graph_kind: index
graph_parent: atlas-agentic-engineering-os
graph_status: active
graph_source: repo
human_name: Atlas Agentic Software Engineering Authority Map
canonical_name: Atlas Agentic Software Engineering Authority Map
technical_name: atlas-agentic-software-engineering-authority-map
cartography_type: index
canonical_source: docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
allowed_changes:
  - Atualizar matriz de autoridade, docs filhos, nomes proibidos e ordem de leitura quando docs canonicos mudarem.
  - Promover doc filho para autoridade apenas quando ele for linkado por START_HERE, README, glossary e doc-mae correspondente.
forbidden_changes:
  - Criar outro mapa-mae paralelo para Dev/Forge/Atlas Code.
  - Chamar Atlas Code de OS inteiro, runtime-mae ou dominio de programacao.
  - Chamar programacao assistida por IA de nome canonico da area inteira.
  - Usar benchmark, Rivals ou doc de pesquisa como fonte de arquitetura sem promocao canonica.
depends_on:
  - atlas-agentic-engineering-os
  - atlas-canonical-glossary-and-naming
  - atlas-ai-knowledge-governance-system
  - atlas-programming-governance-system
flows_to:
  - atlas-programming-governance-system
  - atlas-dev-efficient-programming-flow-v1
  - atlas-programming-forge-flow
  - atlas-code
unlocks:
  - ai-safe-agentic-engineering-doc-navigation
  - forge-dev-code-hierarchy-cleanup
  - no-duplicate-engineering-os
governs:
  - agentic-engineering-docs
  - atlas-dev-doc-hierarchy
  - atlas-forge-doc-hierarchy
  - atlas-code-doc-hierarchy
  - programming-superiority-doc-hierarchy
evidence:
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:engineering:knowledge sync --prune --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - authority
  - agentic-engineering
  - dev
  - forge
  - code
ai_entrypoints:
  - Leia este doc antes de navegar, reorganizar, limpar ou implementar qualquer documentacao de Atlas Dev, Forge, Atlas Code, Agentic Engineering, Rivals, Superiority ou TEOS.
  - Depois deste doc, leia atlas-agentic-engineering-documentation-inventory.md para classificar familias espalhadas, parts, handoffs, research e benchmarks.
ai_usage_notes:
  - Este doc resolve autoridade documental. Ele nao substitui os contratos tecnicos dos docs filhos.
  - Se outro doc contradizer este mapa sobre hierarquia, trate como drift e atualize o doc filho ou o glossary.
quality_gates:
  - no-parallel-mother-doc
  - atlas-code-surface-only
  - dev-forge-boundary-preserved
  - benchmark-docs-not-authority
  - future-docs-not-runtime
  - docs-health-passes
failure_modes:
  - IA le um doc de Atlas Code e conclui que Atlas Code e o OS inteiro.
  - IA le Rivals e transforma estrategia de benchmark em arquitetura canonica.
  - IA funde Dev e Forge porque ambos fazem programacao.
  - IA usa TEOS como runtime atual e cria schema paralelo de continuidade.
  - IA copia uma ferramenta externa dissecada como se fosse decisao do Atlas.
observability_signals:
  - docs com graph_parent correto
  - related_paths apontando para docs-mae
  - glossary sem alias proibido novo
  - docs-health status
  - ausencia de termos proibidos em docs ativos
next_actions:
  - Usar este mapa como checklist ao criar novo doc de Agentic Software Engineering.
  - Auditar periodicamente docs de Atlas Code, Forge, Dev, Rivals e TEOS contra esta hierarquia.
line_limit: 520
---
# Atlas Agentic Software Engineering Authority Map

## Resumo
Este e o mapa de autoridade para toda a documentacao de Agentic Software
Engineering do Atlas.

Ele existe para impedir que uma IA leia documentos espalhados e conclua coisas
erradas como:

- Atlas Code e o sistema inteiro;
- Atlas Dev e um Forge pequeno;
- Forge e so um prompt grande;
- Rivals prova superioridade sem benchmark;
- TEOS substitui o fluxo de programacao;
- uma dissecacao externa virou arquitetura canonica;
- um handoff antigo vale mais que o doc-mae.

Nome da area:

```text
Agentic Software Engineering
```

Nome do sistema-mae do Atlas nessa area:

```text
Atlas Agentic Engineering OS
```

## Papel no Atlas
Este documento organiza a hierarquia documental da area onde o Atlas tenta
substituir uma organizacao de engenharia de software por um sistema agentico:
produto, arquitetura, spec, codigo, infra, qualidade, seguranca, release,
incidente, documentacao, evidence e learning.

Ele nao reexplica todos os modulos. Ele diz qual documento tem autoridade
quando nomes parecidos aparecem.

## Onde Se Encaixa
```text
Atlas AI / Autonomous Intelligence OS
-> Atlas Agentic Engineering OS
   -> Atlas Autonomous Software Company Runtime
   -> Atlas Autonomous Engineering OS
   -> Atlas Programming Governance System
      -> Atlas Dev Efficient Programming Flow
      -> Atlas Programming Forge Flow
         -> Atlas Forge Continuum OS
            -> Atlas Forge Operating System
            -> Forge Workspace / packets / provider topology / review / evidence
      -> Atlas Code surfaces
   -> TEOS / LHIL / Compounding support layers
   -> Rivals / Superiority / benchmarks
   -> External tool dissections / research
```

## Contratos
### 1. Ordem De Autoridade

| Ordem | Camada | Documento principal | Autoridade |
|---:|---|---|---|
| 1 | Fonte de verdade | `atlas-ai-knowledge-governance-system.md` | Diz qual fonte vence: repo docs, Postgres KB, Code Intelligence, Evidence, Obsidian, projections ou chat. |
| 2 | Nome da area | `atlas-agentic-engineering-os.md` | Define Agentic Software Engineering e o Atlas Agentic Engineering OS. |
| 3 | Contratos da area | `atlas-agentic-engineering-os-contracts.md` | Define departamentos, gates, objetos, autonomia e DoD da organizacao de engenharia agentica. |
| 4 | Empresa operacional | `atlas-autonomous-software-company-runtime.md` | Define a empresa de software autonoma como runtime organizacional. |
| 5 | Stack de stewardship | `atlas-software-company-stewardship-stack.md` | Nome canonico da familia Night Shift, Product Mode, Atlas Continuous Stewardship Loop, Area Focus Loop, Stewardship e evolucoes futuras; Continuous Loop e motor 24h, Self-Expanding e teto da stack; AP-739 expoe review, AP-740/AP-748 ligam outcomes, AP-741 cria handoff packet ao Domain Runtime Creation Gate, AP-742 torna AP-740/AP-741 visiveis no cockpit, AP-743 cria active handoff, AP-744 roda o primeiro active operating slice, AP-745 torna esse slice scheduler-safe, AP-746 adiciona runner recorrente seguro, AP-756 materializa branch/worktree isolado sob receipt, AP-757 vincula AP-749 ao sandbox materializado, AP-747 libera handoffs AP-726 para filas Dev/Forge, AP-749 gates consumo owner-specific, AP-758 adapta consumo pronto em resultado compativel com AP-750, AP-759 executa owner CLI allowlisted no sandbox AP-756 sob receipt, AP-760 expoe AP-759 no Product Mode/Cockpit, AP-761 renderiza o pipeline Product Mode end-to-end no Atlas Desktop, AP-762 certifica o ciclo vivo end-to-end antes de claim de 100%, AP-763 audita a lista pratica 29/29 antes de permitir claim de conclusao, AP-750 devolve resultado owner-runtime para Evidence/Morning Inbox/Portfolio, AP-751 alimenta Portfolio health/risk/rebalance, AP-752 transforma recomendacao executiva aceita em handoff ao owner correto, AP-753 expoe esse handoff no cockpit, AP-754 expoe controles operacionais de Product Mode sem executar e AP-755 grava esses controles como receipts AP-731. |
| 6 | Night Shift | `atlas-autonomous-software-company-night-shift.md` | Define ciclo noturno sandboxed; v1 roda no proprio Atlas antes de v2 em empresas externas. |
| 7 | Loop 24h governado | `atlas-autonomous-software-company-night-shift-product-mode.md` | Define Atlas Continuous Stewardship Loop como nome canonico do modo 24h/always-on; NS-v3 e alias historico. |
| 8 | Stewardship por area | `atlas-area-stewardship-layer.md` | Define saude, roadmap, priorizacao, routing Dev/Forge, evidence e inbox para uma area escolhida; AP-743 cria handoff ativo apos AP-732 readiness, AP-744 roda operacao ativa sem mutacao irreversivel, AP-745 adiciona tick scheduler-safe, AP-746 adiciona runner recorrente seguro, AP-756 materializa branch/worktree isolado sob receipt, AP-757 exige esse sandbox no AP-749, AP-747 libera Dev/Forge queue, AP-748 alimenta Evidence/Portfolio, AP-749 gates consumo owner-specific, AP-758 adapta consumo pronto para resultado AP-750-compatible, AP-759 executa owner CLI allowlisted no sandbox, AP-760 torna esse run visivel no Product Mode/Cockpit, AP-761 torna o pipeline visivel no Desktop, AP-750 bridges resultados reais de Dev/Forge, AP-751 projeta esses resultados no Portfolio e AP-752 pode devolver alocacao executiva aceita como pacote de review. |
| 9 | Ladder alem do loop 24h | `atlas-stewardship-evolution-ladder.md` | Define a evolucao de motor 24h para Area, Portfolio, Executive e Self-Expanding Software Company; AP-738 materializa v0, AP-739 renderiza review, AP-740/AP-748 registram outcomes, AP-741 entrega handoff sem criar dominio, AP-742 conecta esse historico ao cockpit, AP-743 adiciona active handoff, AP-744 adiciona active operation, AP-745 adiciona admission scheduler-safe, AP-746 adiciona admission recorrente, AP-756 adiciona branch sandbox materializer sob receipt, AP-757 adiciona binding do sandbox ao consumo owner, AP-747 adiciona release Dev/Forge operator-owned, AP-749 gates consumo owner-specific, AP-758 adapta consumo pronto em resultado owner-runtime AP-750-compatible, AP-759 executa owner CLI allowlisted no sandbox AP-756, AP-760 torna AP-759 visivel no cockpit, AP-761 mostra o pipeline completo no Desktop, AP-762 certifica o ciclo vivo antes de qualquer claim de 100%, AP-763 audita a lista pratica 29/29 e responde o numero atual, AP-750 fecha o feedback loop do resultado owner-runtime, AP-751 fecha o intake desse resultado no Portfolio, AP-752 gates a alocacao executiva aceita para owner handoff, AP-753 torna o handoff visivel no cockpit, AP-754 expoe controles operacionais read-only e AP-755 torna esses controles replayable via AP-731. |
| 10 | Loop autonomo | `atlas-autonomous-engineering-operating-system.md` | Define goal loop, planning, execution, review, learning e escalation. |
| 11 | Lei de programacao | `atlas-programming-governance-system.md` | Define placement, spec, plan, task contract, Code Intelligence, evidence, review e learning. |
| 12 | Fronteira Dev/Forge | `atlas-dual-core-engineering-system.md` | Define Dev e Forge como nucleos irmaos, nao hierarquia. |
| 13 | Fast path | `atlas-dev-efficient-programming-flow-v1.md` | Define Atlas Dev para trabalho eficiente, curto/medio e governado. |
| 14 | Fluxo pesado | `atlas-programming-forge-flow.md` | Define taxonomia do fluxo pesado `programming.forge`. |
| 15 | Continuum pesado | `atlas-forge-continuum-os.md` | Define continuidade, provider topology, fallback, review, evidence, Rivals e learning. |
| 16 | Fabrica Forge | `atlas-forge-operating-system.md` | Define packets, multiagente, integration queue, release gate e evidence normalization. |
| 17 | Surface | `atlas-code-*.md` | Define UX/cockpit/produto visual; nunca substitui runtime/governance. |
| 18 | Tempo/continuidade | `atlas-temporal-engineering-operating-system.md` | North-star de continuidade; nao e runtime atual nem doc-mae da area. |
| 19 | Rivals/Superiority | `atlas-programming-superiority-*.md`, `atlas-rivals-*.md` | Avaliacao, benchmark e estrategia; nao arquitetura primaria. |
| 20 | Research/disseccoes | `dissecar/spec/*` e docs de ferramentas | Material comparativo; so vira regra apos promocao canonica. |

### 2. Decisao Rapida Para IAs

| Pergunta | Leia primeiro | Nao leia como autoridade primaria |
|---|---|---|
| "Qual e a area que o Atlas esta construindo?" | `atlas-agentic-engineering-os.md` | Atlas Code docs, Rivals, Factory/Devin dissections |
| "Como uma empresa tech inteira vira sistema agentico?" | `atlas-agentic-engineering-os-contracts.md` | Prompt one-shot ou UI mock |
| "Como programar com IA sem improviso?" | `atlas-programming-governance-system.md` | Chat history ou provider docs |
| "Dev ou Forge?" | `atlas-dual-core-engineering-system.md` | Nome de arquivo, tela ou feeling do agente |
| "Fluxo rapido e eficiente?" | `atlas-dev-efficient-programming-flow-v1.md` | Forge docs |
| "Fluxo pesado, multiagente, multiprovider?" | `atlas-programming-forge-flow.md` e `atlas-forge-continuum-os.md` | Atlas Code surface docs |
| "Como a UI deve mostrar isso?" | `atlas-code-long-session-programming-cockpit.md` | Forge OS como se fosse tela |
| "Como manter semanas/meses de contexto?" | `atlas-temporal-engineering-operating-system.md` | Resumo textual de sessao |
| "Atlas vence rival?" | docs Rivals + evidence real | Claim narrativo, score sintetico, marketing |

### 3. Classes De Documento

Cada doc desta area deve cair em uma destas classes:

- **mother**: define nome, fronteira ou sistema-mae. Exige START_HERE,
  README, glossary e architecture index apontando para ele.
- **contract**: define objetos, invariantes, gates e DoD.
- **runbook**: define execucao operacional passo a passo.
- **surface**: define UX/cockpit/projecao visual. Nao manda no runtime.
- **benchmark**: define avaliacao, bateria, score ou Rivals. Nao manda na
  arquitetura sem promocao.
- **north-star**: define alvo futuro. Nao declara runtime pronto.
- **handoff/part/prompt**: material operacional ou fragmento. Nunca vence doc
  mother/contract.
- **research/dissection**: fonte externa estudada. Nunca vira canon sem decisao.

## Fluxo
Fluxo seguro para qualquer IA reorganizar ou usar docs desta area:

```text
1. Ler Knowledge Governance.
2. Ler este Authority Map.
3. Identificar se o pedido e area, lei, Dev, Forge, Code, TEOS, Rivals ou research.
4. Abrir o doc-mae da categoria correta.
5. Abrir contratos/runbooks filhos apenas depois.
6. Abrir `atlas-agentic-engineering-documentation-inventory.md` quando houver
   muitos docs parecidos ou docs `part`, `handoff`, `Rivals`, `provider` ou
   `OS` local.
7. Checar status: active, planned, future, historical ou source material.
8. Implementar ou documentar somente dentro da autoridade correta.
9. Atualizar START_HERE/README/glossary se a autoridade mudar.
10. Rodar docs-health e sync.
```

## Regras para IA
- Use **Agentic Software Engineering** como categoria da area.
- Use **Atlas Agentic Engineering OS** como sistema-mae do Atlas nessa area.
- Use **Atlas Programming Governance System** para leis de programacao.
- Use **Atlas Dev** para fast path eficiente.
- Use **Atlas Forge** para fabrica pesada, longa, multiagente ou multiprovider.
- Use **Atlas Code** apenas como surface/cockpit/produto visual.
- Use **TEOS** apenas como camada temporal/north-star de continuidade.
- Use **Rivals/Superiority** apenas como benchmark, avaliacao ou estrategia.
- Use disseccoes externas apenas como pesquisa.
- Use diagramas/visual specs como compressao operacional derivada de docs, nao como fonte primaria.

Nao faca:

- nao criar "novo OS de programacao" sem encaixar aqui;
- nao fundir Atlas Dev e Atlas Forge;
- nao transformar UI em runtime;
- nao transformar benchmark em arquitetura;
- nao transformar north-star em runtime atual;
- nao copiar ferramenta externa como se fosse decisao Atlas;
- nao usar "programacao assistida por IA" como nome da area inteira.
- nao desenhar imagem que contradiga frontmatter, contracts, schemas, receipts ou evidence.

## Escopo de Implementacao
Este doc deve ser consumido por:

- session bootstrap;
- future docs-health semantic checks;
- Atlas Code context panels;
- Knowledge Governance;
- Code Intelligence context packs;
- qualquer IA que faca limpeza documental;
- qualquer IA que implemente Atlas Dev, Forge, Atlas Code ou Agentic Engineering.

Implementacao ideal futura:

1. registrar `authority_class` em frontmatter;
2. adicionar gate que bloqueia novo doc sem parent canonico;
3. criar check para termos proibidos em docs ativos;
4. expor este mapa no Atlas Code quando a obra for de programacao;
5. ligar System Graph e Cartografia aos tiers deste doc.
6. exigir Visual Canon Block em docs-mae/surfaces criticas quando houver cartografia humana.

## Dependencias
- `atlas-ai-knowledge-governance-system.md`
- `atlas-canonical-glossary-and-naming.md`
- `atlas-agentic-engineering-os.md`
- `atlas-programming-governance-system.md`
- `atlas-dual-core-engineering-system.md`
- `atlas-programming-forge-flow.md`
- `atlas-forge-continuum-os.md`

## Evidencias
Este mapa foi criado apos auditoria de docs ativos que continham termos
sobrepostos: Agentic Engineering, Hyperflow, Atlas Dev, Atlas Forge, Atlas
Code, Forge Continuum, TEOS, Rivals, Programming Superiority e dissecacoes de
ferramentas externas.

Comandos esperados apos alteracao:

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:engineering:knowledge sync --prune --json
```

## Riscos
- IA usar docs de UI como docs de runtime.
- IA tratar "AI-assisted programming" como categoria final em vez de legado.
- IA escolher apenas Forge e ignorar Dev quando eficiencia importa.
- IA escolher Dev para trabalho que precisa de Obra, SDD pesado e evidence.
- IA criar doc duplicado para Factory/Devin/Stakpak sem promover pesquisa.
- IA declarar benchmark vencido sem bateria real.

## Exemplos
Exemplo correto:

```text
Pedido: "melhorar Atlas Code para sessoes longas".
Leitura: Authority Map -> Atlas Code SCOR-1 -> Programming Governance -> Forge Flow.
Conclusao: implementar surface ligada a objetos reais, nao criar novo runtime.
```

Exemplo correto:

```text
Pedido: "fazer Atlas Dev vencer Claude Opus com Sonnet".
Leitura: Authority Map -> Dual-Core -> Atlas Dev Efficient Flow -> Superiority/Rivals docs.
Conclusao: otimizar fast path e benchmark; nao declarar vitoria sem evidence.
```

Exemplo incorreto:

```text
Pedido: "Factory parece concorrente".
Erro: copiar conceitos da Factory como canon.
Correto: registrar dissecacao como research e promover apenas decisoes aprovadas.
```

## Proximas Acoes
- Adicionar `authority_class` em docs novos desta area.
- Corrigir frases antigas que chamam Atlas Code ou Hyperflow de OS inteiro sem
  mencionar a hierarquia.
- Usar este mapa em novas sessoes de limpeza documental.
- Criar check automatico para termos proibidos quando o docs-health evoluir.
