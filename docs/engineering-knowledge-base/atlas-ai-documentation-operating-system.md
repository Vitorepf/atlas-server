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
  - ai_readability_contract
  - canonical_source_governance
  - documentation_health
  - session_bootstrap
decisions:
  - Documentacao e parte do produto Atlas, nao tarefa secundaria.
  - Toda sessao nova deve conseguir descobrir status real sem depender de memoria de chat.
  - Docs ativos devem ser curtos, indexaveis, com ownership claro e links para implementacao.
  - Docs longos devem ser divididos em specs menores antes de receber novas responsabilidades.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar sempre que mudar limite de tamanho, ownership documental ou processo de promocao.
  - Rodar sync e index-code depois de alterar docs canonicos.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md
  - docs/engineering-knowledge-base/START_HERE.md
  - docs/engineering-knowledge-base/README.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
  - docs/engineering-knowledge-base/archive/README.md
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
---

# Atlas AI Documentation Operating System

Este documento define como a documentacao do Atlas deve funcionar para humanos,
IAs, CLI, app, context packs e futuras sessoes.

O objetivo e eliminar perda de contexto, duplicacao, docs concorrentes e falso
negativo do tipo "achei que nao existia".

## Principio Central

Documentacao e infraestrutura de inteligencia.

Se o Atlas nao consegue explicar com precisao o que ele e, o que existe, o que
falta e como evoluir, entao o sistema ainda nao esta enterprise.

Governanca documental e lei do kernel: `atlas ai architecture-validate` deve
falhar quando bootstrap, frontmatter ou docs obrigatorios estiverem quebrados.

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
| Domain spec | 260 linhas | dividir flows/gates/runtime |
| AP / phase spec | 300 linhas | dividir por AP ou fase |
| Runbook operacional | 220 linhas | mover exemplos longos para appendix |
| Audit report | 350 linhas | dividir findings por area |
| Source material / archive | sem limite rigido | nao usar como doc ativo direto |

Documento acima do limite nao e automaticamente errado, mas entra em estado
`split_required` para novas expansoes.

## Docs Atualmente Acima Do Alvo

| Doc | Situacao | Proxima acao |
|---|---|---|
| `atlas-ai-kernel-architecture.md` | grande demais para bootstrap | extrair specs por Kernel AP: envelope, receipt, ledger, provider, surface, SLO |
| `atlas-ai-master-architecture.md` | grande demais para leitura inicial | manter como referencia Layer 2 e extrair domain playbooks |
| `atlas-ai-evolution-roadmap.md` | grande demais para execucao diaria | dividir em AP specs e fase atual |
| `START_HERE.md` | util, mas longo | manter por enquanto e criar bootstrap curto oficial |

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

Campos `summary`, `decisions`, `maintenance` e `related_paths` sao criticos para
context pack, retrieval, revisao e continuidade.

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
4. `START_HERE.md`
5. doc dono do assunto

Se a sessao nao leu isso, ela nao deve propor refactor estrutural.

## Validation Commands

Use:

```bash
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
