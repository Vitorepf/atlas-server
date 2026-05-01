---
id: atlas-skill-engineering-blueprint
type: atlas_ai_skill
title: Engineering Blueprint
slug: engineering-blueprint
status: default
version: 1
owner: atlas
domain: development
ring: 2
risk_level: high
provider_neutral: true
surfaces:
  - mac_cli
  - api
summary: Usa contrato de engenharia para transformar tarefa Atlas em escopo, criterios, plano, validacao e risco residual.
aliases:
  - task-contract
  - engineering-contract
activation:
  primary_triggers:
    - engineering contract
    - task contract
    - blueprint
    - --task-id
  negative_triggers:
    - conversa sem implementacao
permissions:
  filesystem: read
  shell: read_only
  network: none
  memory_write: proposal
quality_gates:
  - scope_matches_contract
  - acceptance_criteria_checked
  - validation_recorded
evals:
  baseline: no_skill
  min_cases: 10
  promotion_metric: verified_completion_score
---

# Engineering Blueprint

## Missao

Executar tarefas tecnicas a partir de contrato explicito: objetivo, contexto, escopo, criterios de aceite, arquivos provaveis, validacao e definition of done.

## Quando usar

- `atlas:cli:dev --task-id`.
- Implementacao com criterio de aceite.
- Bugfix, refatoracao, migracao, CLI, backend, frontend ou banco.
- Trabalho que precisa de handoff claro entre Atlas e provider.

## Processo

1. Ler o contrato antes de abrir arquivos.
2. Confirmar mentalmente o menor conjunto de arquivos provaveis.
3. Produzir plano curto conectado aos criterios de aceite.
4. Editar somente o escopo necessario.
5. Validar com teste, typecheck, smoke test ou QA manual justificada.
6. No resumo final, mapear criterio atendido, validacao executada e risco residual.

## Nao fazer

- Expandir produto alem do contrato sem registrar tradeoff.
- Declarar criterio atendido sem evidencia.
- Reverter mudancas existentes fora do escopo.
- Tratar ausencia de teste como sucesso automatico.

## Saida padrao

Resumo com objetivo, arquivos alterados, criterios cobertos, validacao, lacunas e risco residual.
