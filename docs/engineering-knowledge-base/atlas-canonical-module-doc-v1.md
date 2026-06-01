---
id: atlas-canonical-module-doc-v1
type: engineering_knowledge
title: Atlas Canonical Module Doc v1
status: active
category: documentation
priority: 96
summary: Contrato canonico para docs tecnicos que servem ao mesmo tempo humanos, Cartografia Semantica, Atlas Code e IAs implementadoras.
tags:
  - atlas
  - documentation
  - cartography
  - ai-execution
  - canon
capabilities:
  - canonical_module_doc_v1
  - canonical_module_semantic_graph_contract
  - canonical_module_cartography_contract
  - ai_implementation_context
  - scope_validation
  - evidence_governance
decisions:
  - Docs tecnicos canonicos podem carregar metadados de grafo, escopo, evidencia e seguranca no frontmatter, sem separar a verdade em outra projecao.
  - A Cartografia le a propria documentacao oficial e o AtlasVault como fontes distintas, mas apresenta uma navegacao unificada para o humano.
  - IAs devem usar este schema para entender papel, encaixe, fronteiras, testes e evidencias antes de alterar codigo.
- O limite de tamanho para docs neste schema e maior que o limite comum porque os metadados tambem servem como contrato operacional.
maintenance:
  - Use este contrato antes de criar ou migrar qualquer doc tecnica que deva aparecer na Cartografia.
  - Rode `php artisan atlas:engineering:knowledge docs-health --json` depois de criar ou alterar docs com `doc_schema: atlas_canonical_module_doc.v1`.
  - Nao marque um modulo como implemented sem evidencia real, comandos ou paths verificaveis.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-documentation-creation-gate.md
  - docs/engineering-knowledge-base/atlas-system-graph.md
  - docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-contracts.md
  - docs/engineering-knowledge-base/templates/atlas-canonical-module-doc-v1-template.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-canonical-module-doc-v1
graph_title: Atlas Canonical Module Doc v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-documentation-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Canonical Module Doc v1
canonical_name: Atlas Canonical Module Doc v1
technical_name: atlas-canonical-module-doc-v1
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md
owner: documentation-operating-system
repo_paths:
  - docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md
  - app/Services/Engineering/EngineeringDocumentationHealthService.php
allowed_changes:
  - Evoluir campos obrigatorios do schema quando houver necessidade real da Cartografia ou das IAs.
  - Ajustar validacoes do docs-health para reduzir ambiguidade operacional.
forbidden_changes:
  - Criar uma projecao paralela da documentacao tecnica como fonte de verdade.
  - Exigir que AtlasVault duplique manualmente docs oficiais.
  - Remover evidencia obrigatoria de modulos ativos ou building.
depends_on:
  - atlas-ai-documentation-operating-system
  - atlas-system-graph
flows_to:
  - atlas-cartography
  - atlas-code
unlocks:
  - atlas-semantic-graph
  - ai-safe-implementation-context
governs:
  - engineering-knowledge-base
  - atlas-cartography
evidence:
  - docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md
  - app/Services/Engineering/EngineeringDocumentationHealthService.php
evidence_refs:
  - symbol: EngineeringDocumentationHealthService
  - command: atlas:engineering:knowledge
  - test: EngineeringDocumentationHealthServiceTest
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Migrar primeiro os docs centrais do Kernel, Cartografia e Atlas Code para este schema.
visual_tags:
  - module
  - module
  - documentation

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
---
# Atlas Canonical Module Doc v1

## Resumo

Este contrato define como uma documentacao tecnica oficial do Atlas deve ser escrita quando ela precisa servir tres usos ao mesmo tempo: leitura humana, navegacao visual pela Cartografia e execucao segura por IAs. A fonte de verdade continua sendo o arquivo `.md` versionado no repo. A Cartografia nao cria outra verdade: ela le esse arquivo e transforma seus metadados e corpo em mapa visual.

## Papel no Atlas

O Atlas sera desenvolvido principalmente por IAs. Por isso, a documentacao nao pode ser apenas texto explicativo. Ela precisa carregar contratos operacionais: onde o modulo vive, o que pode ser alterado, o que nao pode, quais testes provam mudanca, quais evidencias sao aceitas, quais dependencias existem e como a peca aparece no mapa vivo.

## Onde Se Encaixa

Este schema fica acima dos docs comuns da Engineering Knowledge Base. Ele nao substitui `atlas-ai-documentation-operating-system.md`; ele especializa esse sistema para docs que precisam ser consumidos por Atlas Code, Cartografia e agentes implementadores.

Hierarquia:

- `atlas-ai-documentation-operating-system.md`: regras gerais de documentacao.
- `atlas-documentation-creation-gate.md`: gate obrigatorio antes de criar,
  migrar ou promover doc navegavel.
- `atlas-canonical-module-doc-v1.md`: contrato forte para modulo tecnico navegavel e executavel por IA.
- `templates/atlas-canonical-module-doc-v1-template.md`: ponto de partida para novos docs nesse formato.
- `EngineeringDocumentationHealthService`: validacao automatica inicial.

## Contratos

Todo doc tecnico que usa este schema deve declarar no frontmatter:

```yaml
doc_schema: atlas_canonical_module_doc.v1
graph_id: stable-ascii-slug
graph_title: Nome Humano
graph_world: atlas
graph_layer: world|system|flow|module|gear|subcomponent
graph_kind: contract|system|flow|module|policy|runbook|adr|index|surface|screen|step
graph_parent: parent-graph-id
graph_status: planned|future|building|active|deprecated
graph_source: repo
macro_layer: false
owner: owner-area
repo_paths:
  - app/Services/Example.php
allowed_changes:
  - Mudancas permitidas.
forbidden_changes:
  - Mudancas proibidas.
depends_on:
  - other-graph-id
flows_to:
  - next-graph-id
unlocks:
  - unlocked-graph-id
governs:
  - governed-area
evidence:
  - docs/or/commands/or/paths
required_tests:
  - php artisan test --filter=ExampleTest
requires_evidence: true
risk_level: low|medium|high|critical
next_actions:
  - Proxima acao concreta.
```

Campos opcionais podem ser usados quando aumentarem clareza:

- `macro_layer`: quando `true`, declara que a doc e uma camada macro estrutural
  e ativa o gate obrigatorio dos quatro nomes abaixo.
- `product_name`: obrigatorio para camada macro; nome canonico/produto.
- `runtime_acronym`: obrigatorio para camada macro; acronimo tecnico.
- `internal_product_name`: obrigatorio para camada macro; nome de experiencia/superficie.
- `technical_runtime`: obrigatorio para camada macro; nome tecnico de implementacao/runtime.
- `graph_order`: ordem visual dentro de um fluxo.
- `graph_position`: posicao manual da Cartografia quando existir.
- `related_to`: conexoes laterais nao hierarquicas.
- `influenced_by`: livros, ideias, notas ou decisoes que influenciam a peca.
- `implements`: AP, ADR, contrato ou decisao que o modulo implementa.
- `blocked_by`: bloqueios reais.
- `runtime_surfaces`: CLI, app, MCP, mobile, scheduler ou terminal.
- `mcp_tools`: ferramentas MCP relacionadas.
- `decision_receipts`: receipts relevantes.
- `visual_tags`: etiquetas curtas para agrupamento, filtro e destaque visual.
- `ai_entrypoints`: ordem de leitura recomendada para IAs antes de implementar.
- `ai_usage_notes`: instrucoes praticas de uso do doc por agentes implementadores.
- `quality_gates`: gates adicionais de qualidade alem de `required_tests`.
- `failure_modes`: modos de falha conhecidos que a IA deve evitar.
- `observability_signals`: sinais, reports ou metricas que indicam saude real.
- `related_paths`: documentacao auxiliar que deve ser lida antes de mexer, sem ser confundida com fonte principal, prova, patamar ou versao.
- `patamar_current`: patamar de maturidade/capacidade declarado para esta peca.
- `patamar_next_of`: quando esta peca e o proximo patamar de outra peca.
- `patamar_next`: proximo patamar esperado depois desta peca.
- `patamar_after`: outros patamares possiveis depois do proximo salto.
- `version_family`: familia de versoes/degraus da mesma superficie ou contrato.
- `versions`: versoes, releases ou degraus conhecidos da mesma coisa.
- `version_note`: nota explicita separando versao de patamar.

Esses campos avancados sao recomendados para docs de alto valor visual ou alto
risco operacional. Eles nao entram no obrigatorio v1 porque campo preenchido por
burocracia reduz qualidade de contexto. Quando presentes, `docs-health` valida
que sejam listas para a Cartografia e Atlas Code consumirem sem parsing ambiguo.

Patamar e versao seguem `atlas-cartography-nomenclature-contract.md`: patamar e
salto de maturidade/capacidade; versao e revisao ou degrau interno da mesma
peca. A Cartografia nao pode inferir patamar a partir de `graph_layer`,
`flows_to`, `unlocks`, path, nome de arquivo ou numero de versao.

Regra de bolso para humanos e IAs:

- Se a peca for motor, runtime, OS, engine, camada macro, produto interno ou
  sistema de nivel alto, declarar `macro_layer: true` e preencher
  `product_name`, `runtime_acronym`, `internal_product_name` e
  `technical_runtime`. Docs-health bloqueia macro marcada sem esses quatro
  nomes.
- `patamar_*` responde maturidade: "qual salto de capacidade isto representa?".
- `version_*`, `versions` e `schema_version` respondem versao: "qual release,
  fase, schema ou degrau da mesma coisa isto descreve?".
- `source_path` e `repo_paths` respondem fonte: "onde a verdade vive?".
- `related_paths` responde contexto: "o que precisa ser lido junto?".
- `flows_to`, `unlocks` e `gear_flow` respondem navegacao/operacao: "para onde
  isto vai?", nao "qual e o proximo patamar?".

Regra de nomenclatura para modais:

- Se a peca e o patamar atual, declarar `patamar_current`.
- Se a peca e o proximo patamar de outra, declarar `patamar_next_of`.
- Se esta peca aponta para um salto de maturidade posterior, declarar
  `patamar_next`.
- Se ha horizonte de maturidade depois do proximo salto, declarar
  `patamar_after`.
- Se a peca tem V0/V1/V4/V6, release, schema ou fase interna, declarar
  `version_family`, `versions`, `schema_version` ou `version_note`; nao usar
  esses campos como patamar.
- Exemplo canonico: `Self-Construction OS` tem `patamar_next:
  Self-Programming OS`. `Atlas Vox` pode ter varias versoes, mas essas versoes
  nao sao patamares sem campo `patamar_*` explicito.

## Fluxo

O fluxo esperado para criar ou atualizar um doc tecnico e:

1. Definir o modulo real que esta sendo documentado.
2. Aplicar `atlas-documentation-creation-gate.md`.
3. Criar ou migrar o arquivo `.md` na Engineering Knowledge Base.
4. Preencher frontmatter base da KB.
5. Preencher `doc_schema: atlas_canonical_module_doc.v1`.
6. Preencher campos `graph_*` para Cartografia.
7. Preencher campos operacionais para IA: escopo, proibicoes, evidencias e testes.
8. Declarar fluxo visual real ou lacuna documental explicita.
9. Escrever corpo com as secoes obrigatorias.
10. Rodar `php artisan atlas:engineering:knowledge docs-health --json`.
11. Rodar `npm run test:cartografia` quando mudar grafo, fluxo, parent, patamar ou modal.
12. Corrigir violacoes antes de usar o doc como contexto de implementacao.

## Regras para IA

Antes de implementar qualquer mudanca em uma area coberta por este schema, a IA deve:

- Ler o frontmatter antes do corpo.
- Respeitar `allowed_changes` e `forbidden_changes`.
- Tratar `repo_paths` como fronteira inicial de investigacao, nao como autorizacao automatica para editar tudo.
- Usar `depends_on`, `flows_to` e `governs` para montar contexto.
- Rodar ou declarar impossibilidade dos comandos em `required_tests`.
- Registrar evidencia concreta quando `requires_evidence` for `true`.
- Nunca promover `graph_status` para `active` ou `implemented` sem evidencias reais.
- Preferir atualizar o doc canonico quando descobrir drift entre arquitetura e codigo.

## Escopo de Implementacao

Este v1 implementa validacao estrutural, nao uma prova completa de verdade. O docs-health deve validar:

- Presenca dos campos obrigatorios.
- Status e risk level dentro da taxonomia permitida.
- `graph_layer` e `graph_kind` dentro da taxonomia permitida.
- `graph_id` unico entre docs canonicos v1.
- `graph_source: repo` para docs tecnicos oficiais.
- `repo_paths` existentes, exceto entradas explicitamente prefixadas com `external:` ou `future:`.
- Secoes obrigatorias no corpo.
- Limite de tamanho maior para docs canonicos v1.
- `requires_evidence` como booleano real.
- Campos avancados em formato de lista quando declarados.

O limite padrao deste schema e **520 linhas**. Esse numero e deliberado: ele
permite frontmatter rico, ficha operacional e corpo humano sem empurrar o doc
para um monolito ilegivel. Se passar disso, divida em doc pai + filhos de
fluxo, modulo ou runbook.

Validacoes futuras:

- Links `depends_on`, `flows_to`, `unlocks` e `governs` resolvidos no grafo.
- Comandos em `required_tests` classificados por custo e risco.
- Evidencia conectada ao Evidence Ledger.
- Leitura incremental pela Cartografia em tempo real.

## Dependencias

- `atlas-ai-documentation-operating-system.md`
- `atlas-documentation-creation-gate.md`
- `atlas-system-graph.md`
- `vault/atlas-vault-cartography-schema.md`
- `app/Services/Engineering/EngineeringDocumentationHealthService.php`

## Evidencias

Evidencia atual:

- Este contrato existe como doc versionado no repo.
- O docs-health do Atlas ja existe e foi escolhido como ponto unico de validacao.
- A Cartografia ja possui endpoints para grafo, nota por `graph_id` e mudancas recentes.

Este contrato ainda nao afirma que todos os docs tecnicos ja estao migrados.

## Riscos

- Excesso de campos pode virar burocracia se usado em docs pequenos.
- Campos sem validacao envelhecem silenciosamente.
- IAs podem tratar `repo_paths` como permissao ampla se o doc nao declarar proibicoes.
- Cartografia pode parecer correta mesmo quando o codigo real mudou sem atualizar a doc.

## Exemplos

Um doc pequeno pode continuar usando apenas o frontmatter base da KB. Use este schema quando a peca precisar aparecer como engrenagem navegavel ou orientar implementacao por IA.

Exemplos bons para migrar primeiro:

- Atlas Code backend contract.
- Atlas Cartography source authority.
- Atlas Decide.
- Evidence Ledger.
- Quality Gates.
- Runtime Executor.

## Proximas Acoes

- Migrar um primeiro doc real de alto valor para este schema.
- Migrar progressivamente docs ativos de alto valor para este schema; docs comuns continuam com frontmatter base.
- Fazer a Cartografia usar `visual_tags`, `quality_gates`, `failure_modes` e `observability_signals` para analise visual avancada.
- Fazer Atlas Code exibir `allowed_changes`, `forbidden_changes`, `ai_entrypoints`, `ai_usage_notes`, `required_tests` e `evidence` antes de executar mudancas.
