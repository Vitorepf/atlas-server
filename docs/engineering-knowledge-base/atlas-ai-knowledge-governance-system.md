---
id: atlas-ai-knowledge-governance-system
type: engineering_knowledge
title: Atlas AI Knowledge Governance System
status: active
category: knowledge-governance
priority: 100
summary: Contrato enterprise que conecta docs canonicos no repo, Postgres KB, Code Intelligence, Evidence Ledger, Obsidian/AtlasVault, AGENTS/CLAUDE e bootstrap de sessao para qualquer IA se orientar sem memoria de chat.
human_summary: Define quem manda quando repo docs, codigo, ledger, Postgres, Obsidian, AGENTS, CLAUDE ou chat discordam.
human_what: Hierarquia de autoridade do conhecimento do Atlas.
human_purpose: Fazer qualquer IA nascer orientada pela fonte certa, sem depender de memoria de chat ou resumo incompleto.
human_input: Recebe docs canonicos, read models, evidence ledger, vault humano, projections de provider e contexto de sessao.
human_output: Entrega regra de conflito, bootstrap, fonte de verdade e caminho seguro para promover conhecimento.
human_change_when: Mexa quando mudar a autoridade entre repo, ledger, KB, Obsidian, provider projection ou bootstrap.
human_block_when: Bloqueie quando uma IA quiser confiar em chat, Obsidian, Postgres ou projection contra uma doc canonica do repo.
tags:
  - atlas-ai
  - documentation
  - knowledge-governance
  - ai-bootstrap
  - anti-duplication
  - postgres
  - obsidian
  - agents
capabilities:
  - knowledge_governance_system
  - ai_session_orientation
  - documentation_source_of_truth
  - context_pack_contract
  - agent_projection_governance
  - provider_release_governance
decisions:
  - Documentacao e infraestrutura operacional do Atlas, nao material auxiliar.
  - `docs/engineering-knowledge-base` e a fonte canonica versionada de engenharia.
  - Postgres KB e Code Intelligence sao read models vivos; nao sao fonte autoral primaria.
  - Evidence Ledger e verdade runtime append-only; nao substitui specs canonicas.
  - Obsidian/AtlasVault e Human Knowledge Surface; ideias so governam runtime depois de promocao.
  - AGENTS.md e CLAUDE.md sao provider projections compactas; nao podem vencer docs canonicos.
  - Toda IA que iniciar trabalho estrutural deve executar bootstrap e localizar doc dono antes de codigo.
  - Toda feature nova deve declarar owner, layer, domain/surface/runtime e AP quando aplicavel.
  - Novidades de providers/labs devem entrar por Provider Evolution Intelligence antes de virar codigo, skill, connector ou policy.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar ao mudar fonte de verdade, provider projection, KB sync, bootstrap ou superficie de conhecimento.
  - Rodar docs-health, architecture-validate, sync e index-code apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md
  - docs/engineering-knowledge-base/START_HERE.md
  - docs/engineering-knowledge-base/README.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-next-patamar-operating-systems.md
  - docs/engineering-knowledge-base/atlas-sovereign-operating-system.md
  - docs/engineering-knowledge-base/atlas-epistemic-operating-system.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md
  - docs/engineering-knowledge-base/atlas-local-agent-memory-ingestion.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/memory-core-runbook.md
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
  - AGENTS.md
  - CLAUDE.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-knowledge-governance-system

graph_title: Atlas AI Knowledge Governance System

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Knowledge Governance System
canonical_name: Atlas AI Knowledge Governance System
technical_name: atlas-ai-knowledge-governance-system
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md

owner: knowledge-governance

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md

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
  - knowledge-governance

evidence:
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
implementation_state: partial
evidence_refs:
  - symbol: EngineeringDocumentationHealthService
  - command: atlas:engineering:knowledge
  - test: EngineeringDocumentationHealthServiceTest
  - receipt: docs/engineering-knowledge-base/.governance/docs-health-baseline.json

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - policy
  - knowledge-governance

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
# Atlas AI Knowledge Governance System

Este documento define como o Atlas preserva conhecimento para humanos e IAs.
Ele existe para impedir que uma sessao nova crie fluxo paralelo, duplique
capability, confunda planejamento com pronto ou declare inexistente algo que ja
foi implementado.

## Decisao Executiva

Atlas so escala se conhecimento virar infraestrutura.

O contrato e simples: decisao canonica nasce em doc versionado, e consultada por
Postgres/Context Pack, e provada por codigo/teste/ledger. Obsidian, chat e
arquivos de provider podem alimentar o sistema, mas nao governam implementacao
sem promocao.

`atlas-documentation-reality-system.md` e o doc mae que organiza a dependencia
entre documentacao canonica, ACRUI e Cartografia/AURC. Ele deve ser lido quando
a tarefa envolver documentacao real, codigo legado/planejamento, mapa visual
humano ou consumo de docs de outro projeto.

Proximo patamar canonico: `atlas-next-patamar-operating-systems.md` fixa o
conjunto soberano/epistemico/cartografico; `atlas-sovereign-operating-system.md`
decide direcao e limites; `atlas-epistemic-operating-system.md` transforma esta
governanca em confianca, drift, contradicao, maturidade e permissoes de IA; e
`atlas-cartographic-knowledge-os.md` transforma verdade e direcao em mapa visual
navegavel por humanos e IAs.

## Por Que IAs Se Perdem

| Falha | Causa | Correcao obrigatoria |
|---|---|---|
| "Nao existe" quando existe | busca so em chat ou codigo solto | `rg` em docs + codigo + KB status |
| fluxo duplicado | IA nao localizou owner | Feature Placement Protocol |
| doc fora do padrao | faltou Documentation OS | frontmatter + status + related_paths |
| Obsidian vira runtime | nota humana sem promocao | promote para doc/AP versionado |
| Postgres stale | sync/index nao rodou | `sync --prune` + `index-code --prune` |
| provider projection vira lei | AGENTS/CLAUDE envelhece | docs canonicos vencem projection |
| meio implementado esquecido | sem AP/status/DoD | AP ou doc dono com estado explicito |

## Camadas De Conhecimento

| Camada | Papel | Autoridade |
|---|---|---|
| Repo docs canonicos | fonte autoral de engenharia | alta |
| AP specs | contrato executavel por fase/capability | alta |
| Codigo, testes, migrations | prova mecanica de implementacao | alta para estado real |
| Evidence Ledger | verdade runtime append-only | alta para eventos |
| Postgres KB | indice vivo para recall/context pack | read model |
| Code Intelligence | links docs -> simbolos/rotas/comandos/testes | read model |
| Obsidian/AtlasVault | Human Knowledge Surface e workspace pessoal | entrada/promocao |
| AGENTS.md/CLAUDE.md | bootstrap provider-safe compacto | projection |
| Chat/conversa | source material temporario | baixa |

## Regra De Autoridade

Em conflito, use esta ordem:

1. `atlas-ai-thesis-multiplier-channel.md`, canonical index e doc dono.
2. Kernel, Pipeline, Core-vs-Domain, Domain Specs e APs aplicaveis.
3. Codigo, testes, migrations e comandos que provam estado real.
4. Evidence Ledger para historico runtime.
5. Postgres KB e Code Intelligence, validando freshness.
6. Obsidian managed notes e inbox humano.
7. AGENTS.md, CLAUDE.md e outros provider projections.
8. Chat, source material, print, ideia solta ou resposta de IA externa.

## AI Session Bootstrap Protocol

Antes de trabalho estrutural, qualquer IA deve:

1. Confirmar workspace e repo com `pwd` e `git rev-parse --show-toplevel`.
2. Rodar `php artisan atlas:ai:session-bootstrap --task="<task>" --json` e seguir o `docs_split_plan` filtrado.
3. Rodar `php artisan atlas:ai:place-feature "<feature>" --json` antes de feature nova.
4. Ler `atlas-ai-session-bootstrap.md`.
5. Ler `atlas-ai-canonical-architecture-index.md`.
6. Ler `atlas-ai-documentation-operating-system.md`.
7. Ler este documento.
8. Ler `START_HERE.md` quando a tarefa tocar memoria, KB ou onboarding.
9. Ler doc dono do assunto: domain, surface, runtime, AP ou runbook.
10. Buscar evidencias com `rg` em `docs`, `app`, `config`, `routes`, `database` e `tests`.
11. Rodar `php artisan atlas:ai:docs-authority-audit --json` antes de criar
    nome, runtime, OS, Engine, Factory, Layer ou doc macro novo.
12. Consultar `atlas-code-reality-usage-intelligence.md` antes de chamar codigo
    de morto, legado, duplicado, pronto ou seguro de apagar.
13. Consultar status quando disponivel: `atlas engineering knowledge status` e `code-status`.
14. Antes de editar, declarar mentalmente owner, layer, status e validacoes.

Se a IA nao sabe qual doc manda, ela ainda nao deve programar.

Se `docs-authority-audit` retornar `blocked`, a IA deve resolver ou declarar
supersede/reuse antes de criar qualquer documentacao ou runtime concorrente.

## Feature Placement Protocol

Toda feature nova precisa responder:

| Pergunta | Destino correto |
|---|---|
| Serve varias surfaces/domains? | Core |
| E especializada em uma vertical? | Domain |
| So coleta input ou apresenta output? | Surface |
| Executa provider, worker, tool ou harness? | Runtime/Executor |
| Gera prova, auditoria, replay ou metrica? | Evidence/Telemetry |
| Aprende, propõe melhoria ou calibra? | Learning/Self-Improvement |
| E ideia aprovada mas nao pronta? | AP/backlog governado |
| E pesquisa bruta ou conversa? | source material/archive |

Regra dura: surface nao decide, provider nao decide, tool nao decide, domain nao
burla policy, runtime nao executa sem Decision Receipt, e tudo repetido vira Core.

## Documentation Change Protocol

Fluxo obrigatorio para conhecimento novo:

1. Capturar como source material: chat, Obsidian, pesquisa, issue ou proposta.
2. Triar owner: Core, Domain, Surface, Runtime, Evidence, Learning, AP ou Archive.
3. Criar/atualizar doc canonico curto com frontmatter, status, DoD e related paths.
4. Implementar codigo, migration, config, tests ou comandos quando o status exigir.
5. Registrar evidence/ledger/read model quando houver comportamento runtime.
6. Atualizar indices: README, START_HERE, canonical index e doc dono.
7. Sincronizar Postgres KB e Code Intelligence.

Nada pula de conversa para runtime sem contrato.

## Provider Projection Contract

`AGENTS.md` e `CLAUDE.md` existem para orientar providers rapidamente, mas sao
projecoes compactas.

Eles devem:

1. apontar para este contrato e para o bootstrap canonico;
2. resumir regras provider-safe;
3. ser regeneraveis ou sincronizados;
4. preservar `atlas:manual` sem truncar notas humanas ou fences markdown;
5. nunca introduzir decisao que nao exista nos docs canonicos.

Se provider projection divergir de doc canonico, atualize o doc canonico primeiro
e depois a projection.

Comandos canonicos:

```bash
php artisan atlas:memory:projection status --target=all --workspace=<workspace> --json
php artisan atlas:memory:projection write --target=all --workspace=<workspace> --force --json
```

## Postgres Contract

Postgres KB, Code Intelligence e context packs sao aceleradores de recall.

Eles nao sao lugar para editar arquitetura manualmente. A fonte autoral e o repo.
Depois de mudanca relevante, rode:

```bash
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
atlas engineering knowledge docs-health
```

Uma IA deve desconfiar de read model sem `indexed_at`, `content_hash` ou status
compatível com o arquivo atual.

## Oversized Docs Contract

Documento `split_required` nao pode receber novas responsabilidades. Antes de
expandir doc grande, rode:

```bash
php artisan atlas:ai:docs-split-plan --owner=<owner_area> --json
```

O plano retorna backlog filtravel por `owner`, `severity` e `status`, owner
update e formato alvo. Divida em child specs ou APs antes de adicionar conteudo.

## Obsidian / AtlasVault Contract

AtlasVault e poderoso para pensamento humano: pesquisa, identidade, escrita,
curadoria, revisao e navegacao pessoal.

Mas uma nota do vault so governa runtime depois de promocao para:

1. doc canonico no repo;
2. AP executavel;
3. memory item governado;
4. evidence/read model quando aplicavel.

Obsidian pode ser inbox e espelho; nao e fonte operacional crua.

## Definition Of Done

Uma mudanca enterprise esta pronta quando:

1. possui owner doc/AP claro;
2. declara status correto e estado operacional verificavel;
3. nao cria fluxo paralelo;
4. atualiza indices canonicos relevantes;
5. liga docs a codigo/teste/comando quando afirma implementacao;
6. preserva Obsidian como human surface, nao runtime source;
7. atualiza provider projections se a regra impactar sessoes externas;
8. roda `docs-health`, `architecture-validate`, `sync` e `index-code`.

## Anti-Patterns

1. Codar antes de achar owner doc.
2. Criar doc longo novo porque o assunto parece grande.
3. Dizer "nao existe" sem busca em docs, codigo e status.
4. Tratar chat, print ou IA externa como decisao canonica.
5. Colocar decisao operacional so no Obsidian.
6. Atualizar Postgres manualmente em vez de versionar doc.
7. Chamar planejamento, backlog ou partial de implemented.
8. Duplicar capability entre `ask`, `dev`, `forge`, mobile ou voice.
9. Deixar feature parcial sem AP, status e DoD.
10. Permitir que AGENTS/CLAUDE contradigam a arquitetura.

## Resumo

Knowledge Governance define a ordem de autoridade entre docs canonicos, codigo,
Evidence Ledger, read models, vault humano, projections e chat.

## Papel no Atlas

Impede que IAs novas implementem por memoria de conversa, read model stale ou
projection curta quando existe doc dono no repo.

## Onde Se Encaixa

Governa session-bootstrap, feature placement, docs authority, provider
projections, KB sync, Code Intelligence, Obsidian/Vault e source material.

## Contratos

Docs do repo sao autoria; codigo/testes/migrations provam implementacao;
Evidence Ledger prova eventos runtime; KB, Code Intelligence, Vault e
projections sao leitura/projecao.

## Fluxo

```text
task -> bootstrap -> placement -> owner doc -> ACRUI/evidence
-> implementation/tests -> docs-health/sync/index-code -> projections
```

## Regras para IA

Nunca use chat, Obsidian, Postgres KB ou AGENTS/CLAUDE contra doc canonico.
Sem owner doc e placement, nao programe.

## Escopo de Implementacao

Este doc muda apenas quando muda autoridade, bootstrap, projection, KB sync,
Vault boundary ou regra de promocao de conhecimento.

## Dependencias

Depende de Documentation OS, ADRS, ACRUI, START_HERE, session bootstrap,
feature placement, docs authority audit, KB e Code Intelligence.

## Evidencias

Evidencias: docs-health, docs-authority-audit, architecture-validate, sync,
index-code, comandos bootstrap/placement, codigo e testes associados.

## Riscos

Riscos centrais: fonte falsa virar verdade, projection envelhecida governar
implementacao, source material competir com doc dono e IA duplicar runtime.

## Exemplos

Uma nota boa do Vault vira patch no owner doc ou AP; ela nao governa runtime
sozinha.

## Proximas Acoes

Manter este contrato curto, sincronizado com bootstrap, placement, docs-health,
authority audit, provider projections e read models.
