---
id: atlas-dev-flow-map-and-product-options-v1-part-07
type: engineering_knowledge
title: Atlas Dev Flow Map And Product Options v1 · Parte 7
status: active
category: programming
priority: 104
summary: Recorte focado de Atlas Dev Flow Map And Product Options v1: Fatia 7: entrada no Rivals ate Regra Final.
tags:
  - atlas-dev
  - product-options
  - split-doc
  - cartography-readable
capabilities:
  - atlas_dev_product_flow_map
  - atlas_documentation_split
decisions:
  - Este recorte preserva uma parte do mapa de fluxo/produto sem ampliar responsabilidade do índice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md quando o mapa de produto mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-flow-map-and-product-options-v1-part-07
graph_title: Atlas Dev Flow Map And Product Options v1 Parte 7
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-dev-flow-map-and-product-options-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Flow Map And Product Options v1 Parte 7
canonical_name: Atlas Dev Flow Map And Product Options v1 Parte 7
technical_name: atlas-dev-flow-map-and-product-options-v1-part-07
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-07.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-07.md
allowed_changes:
  - Atualizar somente a parte descrita neste recorte.
forbidden_changes:
  - Transformar opção de produto, diário ou hipótese em contrato runtime sem evidência.
depends_on:
  - atlas-dev-flow-map-and-product-options-v1
flows_to:
  - atlas-dev-flow-map-and-product-options-v1
unlocks:
  - atlas_cartography_readable_documentation
governs:
  - atlas_dev.product_options
evidence:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao contrato de documentação.
---
# Atlas Dev Flow Map And Product Options v1 · Parte 7

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Flow Map And Product Options v1: Fatia 7: entrada no Rivals ate Regra Final.

## Papel no Atlas

Mantém diário, opções, entrypoints ou matriz fora do índice principal para que a cartografia continue legível.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md` e deve ser lido quando a pessoa precisar do detalhe desta decisão de produto/fluxo.

## Contratos

Segue o documento dono, o glossário canônico e o modelo obrigatório de documentação do Atlas.

## Fluxo

Índice canônico → recorte focado → decisão de produto, execução ou revisão correspondente.

## Regras para IA

Não transformar hipótese, diário, opção futura ou comparação em runtime pronto. Não misturar patamar, versão, fonte, risco, regra ou prova.

## Escopo de Implementacao

Este arquivo só guarda o detalhe extraído do documento maior.

## Dependencias

Depende do índice `atlas-dev-flow-map-and-product-options-v1` e da documentação canônica relacionada.

## Evidencias

A evidência de origem é o documento principal e o `docs-health` verde depois da divisão.

## Riscos

Risco principal: alguém confundir opção/produto futuro com contrato implementado.

## Exemplos

Os exemplos abaixo são o conteúdo extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar este recorte quando a parte correspondente mudar e rodar docs-health.

## Conteudo Extraido
### Fatia 7: entrada no Rivals

- so depois dos resultados locais;
- criar/ativar arm real;
- congelar contrato;
- rodar contra Sonnet puro e Opus puro;
- registrar custo, tempo, qualidade e falhas.

## Comandos De Auditoria

Plan-only:

```bash
php artisan atlas:cli:dev "implemente getter" --workspace=/path/repo --plan-only --json
```

Repair plan:

```bash
php artisan atlas:cli:fix "teste X falhando" --workspace=/path/repo --plan-only --json
```

Forge plan:

```bash
php artisan atlas:cli:dev "mudanca critica" --forge --plan-only --json
```

Fair Claude:

```bash
php artisan atlas:cli:dev "tarefa" --claude-only --plan-only --json
```

Dev -> Forge preview:

```bash
GET /atlas-code/dev-to-forge/threads/{thread}/promotion-preview?workspace=atlas
```

Rivals arms:

```bash
php artisan atlas:forge:rivals arms --json
```

## Regra Final

Atlas Dev e o modo diario eficiente. Forge e o modo de governanca maxima.
Rivals deve medir os dois como produtos diferentes.

O criterio de sucesso do Atlas Dev Light nao e "fazer tudo que Forge faz mais
barato". E:

```text
resolver muito bem o trabalho comum,
detectar cedo quando nao deve continuar,
e escalar para Forge antes de virar risco.
```

