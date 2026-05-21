---
id: atlas-dev-efficient-programming-flow-runbook-v1-part-11
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Runbook v1 · Parte 11
status: active
category: programming
priority: 104
summary: Recorte focado do runbook Atlas Dev Efficient Programming Flow v1: 15.6 Senior Engineer Loop ate 16. Sequencia De Trabalho Recomendada Por Agente IA.
tags:
  - atlas-dev
  - efficient-programming-flow
  - runbook
  - split-doc
capabilities:
  - atlas_dev_implementation_runbook
  - atlas_dev_efficient_programming_flow
decisions:
  - Este recorte preserva uma parte operacional do runbook sem ampliar responsabilidade do indice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md quando o runbook Atlas Dev mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-runbook-v1-part-11
graph_title: Atlas Dev Efficient Programming Flow Runbook v1 Parte 11
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-dev-efficient-programming-flow-runbook-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Efficient Programming Flow Runbook v1 Parte 11
canonical_name: Atlas Dev Efficient Programming Flow Runbook v1 Parte 11
technical_name: atlas-dev-efficient-programming-flow-runbook-v1-part-11
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-11.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-11.md
allowed_changes:
  - Atualizar somente a parte operacional descrita neste recorte.
forbidden_changes:
  - Adicionar nova responsabilidade que pertença ao índice ou a outro recorte.
depends_on:
  - atlas-dev-efficient-programming-flow-runbook-v1
flows_to:
  - atlas_dev_efficient_flow_runtime
unlocks:
  - atlas_dev_operational_execution
governs:
  - atlas_dev.implementation.slices
evidence:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao runbook operacional.
---
# Atlas Dev Efficient Programming Flow Runbook v1 · Parte 11

## Resumo

Este recorte preserva uma parte operacional do runbook Atlas Dev Efficient Programming Flow v1: 15.6 Senior Engineer Loop ate 16. Sequencia De Trabalho Recomendada Por Agente IA.

## Papel no Atlas

Mantém o detalhe executável fora do índice principal para que a cartografia e o modal humano continuem legíveis.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md` e deve ser lido apenas quando a pessoa precisar do detalhe desta fatia.

## Contratos

Segue o contrato do runbook principal, o glossário canônico e o modelo obrigatório de documentação do Atlas.

## Fluxo

Índice canônico → recorte focado → execução ou revisão da fatia correspondente.

## Regras para IA

Não inferir responsabilidade nova. Não misturar patamar, versão, fonte, risco, regra ou prova. Preservar backlink para o índice.

## Escopo de Implementacao

Este arquivo só guarda o detalhe operacional extraído do runbook maior.

## Dependencias

Depende do índice `atlas-dev-efficient-programming-flow-runbook-v1` e da documentação canônica relacionada.

## Evidencias

A evidência de origem é o runbook principal e o `docs-health` verde depois da divisão.

## Riscos

Risco principal: alguém editar este recorte como se fosse novo dono de fluxo, duplicando contrato.

## Exemplos

Os exemplos abaixo são o conteúdo operacional extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar este recorte quando a fatia correspondente do Atlas Dev mudar e rodar docs-health.

## Conteudo Extraido
## 15.6 Senior Engineer Loop

O patamar **Atlas Dev Senior Engineer Loop** adiciona uma camada de auditoria
operacional acima do executor eficiente. Essa camada nao substitui o provider
nem o `AtlasDevFastPathOrchestrator`; ela projeta, em um receipt canônico, se o
run atual possui os ingredientes de um engenheiro operacional autonomo:

- resolucao de ambiguidade baseada em discovery e hipoteses rastreaveis;
- plano multi-step com evidencia por etapa;
- debug/repair loop controlado por verificacao e stop signals;
- edicao architecture-aware com allowed/forbidden files e non-goals;
- cockpit Desktop com paineis de intent, ambiguity, plan, scope, verification,
  receipt e learning;
- handoff de learning para error ledger/curator sem auto-aplicar mudancas;
- hardening enterprise com provider-safe projection, provider lock sem
  fallback e receipts persistidos.

Artefato canônico:

```text
receipts/<run_id>/senior_engineer_loop_audit.json
schema_version = atlas.dev.senior_engineer_loop_audit.v1

receipts/<run_id>/senior_engineer_loop_execution.json
schema_version = atlas.dev.senior_engineer_loop_execution.v1

receipts/<run_id>/failure_capsule.<attempt>.json
schema_version = atlas.dev.failure_capsule.v1
```

O receipt de execucao e gravado depois do Run real e resume, com refs
provider-safe, o plano carregado, execucao provider/deterministica, scope guard,
verification, blockers, debug loop e learning handoff. Quando a completion
falha, bloqueia ou pede revisao, o handoff grava `error_ledger.vN.json` para o
Programming Curator e o debug loop grava `failure_capsule.<attempt>.json` com
`failure_signature`, `attempts_allowed` e stop signals como
`same_signature_twice`, `scope_violation`, `diff_growth`,
`max_attempts_reached` e `risk_level_forbids_repair`. Esse ledger e evidencia
de aprendizado, nao permissao para auto-aplicar mudancas. `ShowController` e
`StreamController` expoem esse receipt para Desktop/CLI sem paths absolutos do
servidor.

Comando de auditoria inicial:

```bash
php artisan atlas:dev:senior-loop:audit --json --strict
php artisan atlas:dev:senior-loop:run --json --strict
```

Os comandos devem falhar em `--strict` se qualquer capability ou etapa
operacional bloquear. Historico invalido ou partial proof nao basta para
completar a meta Senior Engineer Loop. A conclusao final desse patamar exige
o audit inicial e a execução operacional com receipt final, integrados ao Plan,
Run, worker, Show, Stream, cockpit Desktop e learning handoff.

## 16. Sequencia De Trabalho Recomendada Por Agente IA

Quando uma IA implementadora pegar este runbook:

1. Ler `atlas-dev-efficient-programming-flow-v1.md` (contrato principal) para tese.
2. Ler `atlas-dev-efficient-programming-flow-contracts-v1.md` (schemas) para artefatos.
3. Voltar a este runbook para sequencia.
4. Comecar pela **Fatia 0**, PR 0.1, sem pular nada.
5. Cada PR deve ter:
   - branch dedicada `atlas-dev-efficient-flow/fatia-<n>-pr-<x>`;
   - test-driven: escrever teste antes do codigo quando possivel;
   - DoD operacional do PR verde;
   - revisao humana antes do merge.
6. Marcar fatia como `completed` na memoria do Atlas apos DoD da fatia inteira.
7. Atualizar este runbook se alguma decisao mudar (sempre via PR).
8. **Nao** comecar Fatia 5 antes de Fatia 4 verde.
9. **Nao** introduzir benchmark, oraculos, Rivals ou Opus challenge em nenhum PR.

Apos Fatia 5 verde, esta equipe **transfere** o fluxo para a equipe de medicao com:

- doc atualizado;
- cert local `available`;
- conjunto de fixtures e smoke tests;
- runs de exemplo persistidos em `storage/atlas-dev/receipts/`.

A equipe de medicao desenha benchmark, oraculos e Rivals em outro contrato. Aqui termina.

