---
id: atlas-ai-documentation-operating-system
type: engineering_knowledge
title: Atlas AI Documentation Operating System
status: active
category: documentation-governance
priority: 100
summary: Contrato canonico para documentacao de alta performance do Atlas AI, com limites de tamanho, autoridade, bootstrap, promocao, arquivo, verificacao e anti-duplicacao.
tags:
  - atlas-ai
  - documentation
  - governance
  - performance
  - anti-duplication
capabilities:
  - documentation_operating_system
  - knowledge_governance_system
  - ai_readability_contract
  - canonical_source_governance
  - documentation_health
  - documentation_creation_gate
  - session_bootstrap
  - canonical_module_doc_v1
decisions:
  - Documentacao e parte do produto Atlas, nao tarefa secundaria.
  - Toda sessao nova deve conseguir descobrir status real sem depender de memoria de chat.
  - A hierarquia entre repo docs, Postgres, Obsidian, provider projections e chat e definida por `atlas-ai-knowledge-governance-system.md`.
  - Docs ativos devem ser curtos, indexaveis, com ownership claro e links para implementacao.
  - Docs tecnicos que alimentam Cartografia ou Atlas Code devem usar `doc_schema: atlas_canonical_module_doc.v1`.
  - Docs novos, migrados ou promovidos que aparecem na Cartografia devem passar pelo `atlas-documentation-creation-gate.md`.
  - Docs longos devem ser divididos em specs menores antes de receber novas responsabilidades.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar sempre que mudar limite de tamanho, ownership documental ou processo de promocao.
  - Rodar sync e index-code depois de alterar docs canonicos.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md
  - docs/engineering-knowledge-base/atlas-documentation-creation-gate.md
  - docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md
  - docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/START_HERE.md
  - docs/engineering-knowledge-base/README.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
  - docs/engineering-knowledge-base/archive/README.md
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-documentation-operating-system

graph_title: Atlas AI Documentation Operating System

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: documentation-governance

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md

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
  - documentation-governance

evidence:
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - policy
  - documentation-governance

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
# Atlas AI Documentation Operating System

Este documento define como a documentacao do Atlas deve funcionar para humanos,
IAs, CLI, app, context packs e futuras sessoes, eliminando perda de contexto,
duplicacao, docs concorrentes e falso negativo do tipo "achei que nao existia".

## Principio Central

Documentacao e infraestrutura de inteligencia. Se o Atlas nao consegue explicar
com precisao o que ele e, o que existe, o que falta e como evoluir, entao o
sistema ainda nao esta enterprise.

Governanca documental e lei do kernel: `atlas ai architecture-validate` deve falhar quando bootstrap, frontmatter ou docs obrigatorios estiverem quebrados.

## Perguntas Canonicas

Toda organizacao documental deve permitir responder rapidamente:

1. O que e Atlas AI?
2. O que ja esta implementado?
3. O que e scaffold, future, source material ou legacy?
4. O que falta evoluir?
5. Como evoluir sem duplicar fluxo?
6. Qual documento manda em caso de conflito?
7. Qual codigo, teste, migration ou comando prova a implementacao?
8. O que uma nova sessao precisa ler primeiro?

## Camadas Documentais

| Camada | Papel | Doc dono |
|---|---|---|
| Bootstrap | resposta curta para nova sessao | `atlas-ai-session-bootstrap.md` |
| Authority | hierarquia e conflito entre docs | `atlas-ai-canonical-architecture-index.md` |
| Documentation OS | regras de documentacao | este documento |
| Creation Gate | condicao obrigatoria para criar, migrar ou promover docs navegaveis | `atlas-documentation-creation-gate.md` |
| Canonical Module Doc | formato forte para docs tecnicos navegaveis e seguros para IA | `atlas-canonical-module-doc-v1.md` |
| Knowledge Governance | fonte de verdade entre repo docs, Postgres, Obsidian, provider projections e chat | `atlas-ai-knowledge-governance-system.md` |
| Kernel | contratos executaveis | `atlas-ai-kernel-architecture.md` e specs fatiadas |
| Master | produto, planes, dominios e estrategia | `atlas-ai-master-architecture.md` |
| Domain Specs | comportamento por dominio | `domains/*.md` |
| Roadmap | evolucao governada | `atlas-ai-evolution-roadmap.md` e AP specs |
| Archive | source material preservado | `archive/README.md` |

## Limites De Tamanho

Docs ativos precisam caber bem em context packs e leitura de IA.

| Tipo | Limite alvo | Acao ao exceder |
|---|---:|---|
| Bootstrap / index | 180 linhas | dividir em indice + detalhe |
| Contrato canonico | 260 linhas | extrair sub-spec por assunto |
| Canonical Module Doc v1 | 520 linhas | dividir em contrato pai + filhos de fluxo/modulo |
| Domain spec | 260 linhas | dividir flows/gates/runtime |
| AP / phase spec | 300 linhas | dividir por AP ou fase |
| Runbook operacional | 220 linhas | mover exemplos longos para appendix |
| Audit report | 350 linhas | dividir findings por area |
| Source material / archive | sem limite rigido | nao usar como doc ativo direto |

Documento acima do limite entra em `split_required` para novas expansoes.

## Frontmatter Obrigatorio

Todo doc canonico deve declarar:

```yaml
id:
type:
title:
status:
category:
priority:
summary:
tags:
capabilities:
decisions:
maintenance:
related_paths:
```

Campos `summary`, `decisions`, `maintenance` e `related_paths` sao criticos para context pack, retrieval, revisao e continuidade.

Quando o doc governa uma peca navegavel da Cartografia ou uma area executada por IA, ele tambem deve declarar `doc_schema: atlas_canonical_module_doc.v1` e os campos de grafo, escopo, proibicoes, evidencias e testes definidos em `atlas-canonical-module-doc-v1.md`.

Antes de criar, migrar ou promover qualquer doc navegavel, aplique
`atlas-documentation-creation-gate.md`. O gate obriga identidade visual, fluxo
real ou lacuna explicita, fonte canonica, modal humano, prova, governanca,
nomenclatura e testes. Sem isso, a Cartografia pode ficar bonita e falsa.

## Cartografia Como Gate Obrigatorio

Todo doc ativo/building que governa uma peca navegavel precisa conseguir
alimentar a Cartografia sem interpretacao livre da IA. Isso significa:

| Necessidade | Campo / secao obrigatoria | Falha se faltar |
|---|---|---|
| Identidade visual | `graph_id`, `graph_title`, `graph_world`, `graph_layer`, `graph_kind`, `graph_parent`, `graph_status` | a peca nao aparece ou aparece no lugar errado |
| Fonte real | `repo_paths`, `graph_source: repo`, `source_path` derivado pelo scanner | a Cartografia pode apontar para verdade falsa |
| Fluxo | `depends_on`, `flows_to`, `unlocks`, `governs`, `gear_flow` quando houver subfluxo interno | tap abre fluxo vazio ou inventado |
| Modal humano | `summary`, `decisions`, `capabilities`, `allowed_changes`, `forbidden_changes`, `risk_level`, `next_actions` | longa pressao vira texto pobre ou confuso |
| Prova | `evidence`, `required_tests`, `quality_gates`, `observability_signals` | IA nao sabe validar antes de mexer |
| Nomenclatura | `patamar_*` apenas quando houver salto de maturidade; `version_*` apenas quando houver versao/degrau | patamar, versao, fonte e camada viram bagunca |

Regra operacional: doc novo de sistema/fluxo/modulo que nao declara fluxo real
deve declarar a lacuna em `next_actions` e `failure_modes`. A Cartografia deve
mostrar lacuna documental explicita, nunca preencher com chute.

Antes de promover doc para `active` ou `building`, rode:

```bash
php artisan atlas:engineering:knowledge docs-health --json
npm run test:cartografia
```

Se o doc criar nova engrenagem, novo fluxo, novo parent ou novo patamar, tambem
deve atualizar a visualizacao mobile/desktop ou garantir que o grafo semantico
exponha esse caminho para busca e drilldown.

Definition of Ready para Cartografia:

1. Tap tem destino visual real ou declara peca terminal/lacuna documental.
2. Longa pressao tem modal humano com resumo, fluxo, prova, riscos, fontes,
   governanca, patamares e versoes separados.
3. `repo_paths`, `related_paths`, `evidence` e `required_tests` aparecem em
   categorias diferentes.
4. `patamar_*` nunca e inferido por camada, versao, arquivo, fluxo ou unlock.
5. `docs-health` e `npm run test:cartografia` passam antes da promocao.

## Status Permitidos

| Status | Significado |
|---|---|
| `active` | fonte canonica em uso |
| `implemented` | especificacao ja representada em codigo/teste |
| `implemented_ready` | runtime pronto para uso assistido |
| `scaffold` | catalogado, mas nao deve ser vendido como pronto |
| `future` | intencao aprovada, sem execucao atual |
| `source_material` | material preservado para extracao |
| `archived` | historico, nao governa implementacao |
| `split_required` | doc ativo acima do tamanho saudavel |

## Template De Doc Ativo

Docs novos devem conter, quando aplicavel:

1. Scope
2. Authority
3. What Exists
4. What Is Missing
5. Runtime / Flow
6. Evidence / Tests
7. Anti-Duplication Rules
8. Evolution Path
9. Validation Commands

Nao use narrativa longa quando tabela, checklist ou contrato curto resolver.

## Regra Anti-Halucinacao

Antes de afirmar que algo nao existe:

1. Buscar em `docs/engineering-knowledge-base`.
2. Buscar em `app`, `config`, `database`, `routes` e `tests`.
3. Consultar `atlas engineering knowledge status`.
4. Consultar `atlas engineering knowledge code-status`.
5. Diferenciar `implemented`, `scaffold`, `future` e `archive`.

O erro "achei que nao tinha, mas tinha" e falha documental e operacional.

## Regra Anti-Duplicacao

Se uma capability aparece em duas surfaces, ela deve virar Core ou Domain.

Exemplos:

| Sinal | Acao correta |
|---|---|
| paste image so funciona no ask | promover para Atlas Input |
| repair em dev, forge e worker | consolidar em repair policy + receipt limits |
| provider routing duplicado | mover para Decide + Provider Driver |
| tool gate duplicado | mover para Super Tool Runtime + Authority Matrix |
| doc legado contradiz atual | marcar archive/source e apontar doc dono |

## Processo De Promocao

Ideias novas seguem este funil:

1. `source_material`: conversa, vault, backlog, EVOLUCAO_ATLAS, proposta de agente.
2. `triage`: vale core, domain, surface, runtime, evidence, learning ou archive?
3. `canonical_spec`: doc curto com autoridade, status, DoD e links nos indices certos
   (`START_HERE`, canonical index e doc dono/AP equivalente), sem colar texto longo de AP em docs mae.
4. `implementation`: codigo, migration, config, tests, scanner e commands.
5. `evidence`: ledger, reports, health, docs links e code index.
6. `sync`: Postgres KB e Code Intelligence atualizados.

Nada pula de ideia para runtime sem contrato.

## AtlasVault / Obsidian

AtlasVault e muito poderoso como Human Knowledge Surface:

1. escrita e revisao humana rica;
2. pesquisa, identidade, memoria narrativa e organizacao pessoal;
3. espelho gerenciado de docs canonicos;
4. inbox de ideias antes de promocao;
5. leitura humana fora do app.

Mas ele nao e fonte operacional primaria crua. A fonte que governa runtime deve
ser versionada no repo, indexada no Postgres e ligada a codigo/teste.

## Session Bootstrap Contract

Toda nova sessao deve comecar por:

1. `atlas-ai-session-bootstrap.md`
2. `atlas-ai-canonical-architecture-index.md`
3. este documento
4. `atlas-ai-knowledge-governance-system.md`
5. `START_HERE.md`
6. doc dono do assunto

Se a sessao nao leu isso, ela nao deve propor refactor estrutural.

## Validation Commands

Use:

```bash
php artisan atlas:ai:session-bootstrap --task="<task>" --json
php artisan atlas:ai:place-feature "<feature>" --json
php artisan atlas:ai:docs-split-plan --owner=<owner_area> --json
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
atlas engineering knowledge docs-health
atlas engineering knowledge status
atlas engineering knowledge code-status
atlas ai architecture-validate --json
```

`docs-health` e o scanner documental direto. `architecture-validate` consome
esse scanner como gate de arquitetura, e Documentation Health Curator Review
converte `split_required` em `atlas.self_improvement.documentation_health_gap.v1`
com `split_oversized_active_docs`.

## Definition Of Done

Uma mudanca documental enterprise esta pronta quando:

1. tem doc dono;
2. cabe no limite ou marca split required;
3. aponta para codigo/teste/comando quando disser implemented;
4. nao contradiz o canonical architecture index;
5. diferencia ready, scaffold, future e archive;
6. foi sincronizada para KB;
7. foi reindexada no Code Intelligence quando altera links ou codigo;
8. `docs-health` e `architecture-validate` continuam verdes;
9. uma sessao nova consegue descobrir a decisao sem ler chat antigo.

## Resumo

Contrato canonico para documentacao de alta performance do Atlas AI, com limites de tamanho, autoridade, bootstrap, promocao, arquivo, verificacao e anti-duplicacao.

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
