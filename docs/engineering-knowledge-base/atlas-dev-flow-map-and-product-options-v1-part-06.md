---
id: atlas-dev-flow-map-and-product-options-v1-part-06
type: engineering_knowledge
title: Atlas Dev Flow Map And Product Options v1 · Parte 6
status: active
category: programming
priority: 104
summary: Recorte focado de Atlas Dev Flow Map And Product Options v1: Frontend/UI ate Fatia 6: promotion loop.
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
graph_id: atlas-dev-flow-map-and-product-options-v1-part-06
graph_title: Atlas Dev Flow Map And Product Options v1 Parte 6
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-dev-flow-map-and-product-options-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Flow Map And Product Options v1 Parte 6
canonical_name: Atlas Dev Flow Map And Product Options v1 Parte 6
technical_name: atlas-dev-flow-map-and-product-options-v1-part-06
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-06.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-06.md
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
# Atlas Dev Flow Map And Product Options v1 · Parte 6

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Flow Map And Product Options v1: Frontend/UI ate Fatia 6: promotion loop.

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
### Frontend/UI

Exemplo: "ajuste esse componente pela screenshot".

Fluxo:

```text
programming.dev ou programming.frontend
imagem anexada
frontend_design_harness_contract se detectado
patch
visual smoke / screenshot or reason
no text overlap
```

### Refactor medio

Exemplo: "extraia service e atualize dois callers".

Fluxo:

```text
programming.dev
agentic RAG
plano curto
patch escopado
test impact
quality gate
se multi-subsistema/risk -> obra_candidate ou Forge
```

### Mudanca de banco/migration/security

Fluxo recomendado:

```text
detectar harness signals
se simples: plan-only + pedir confirmacao
se risco medio/alto: Forge/Obra
rollback obrigatorio
```

### Tarefa longa/ambigua

Fluxo:

```text
Atlas Dev para discovery
promotion-preview
obra_candidate
humano refina objective/criteria
Forge quando aprovado
```

### Benchmark Fair Claude

Fluxo:

```text
--claude-only --model=opus --single-provider --no-decide --fallback-disabled
sem Codex/Gemini/council/fallback
quality gate deterministico
unverified != passed
```

Uso: comparar harness+Claude contra Claude Code. Nao e UX diaria.

## Matriz De Escalada

| sinal | Atlas Dev | Dev Light | Forge |
| --- | --- | --- | --- |
| pergunta/codigo read-only | sim | sim | nao |
| bug pequeno 1-2 arquivos | sim | sim | nao |
| teste falhando localizado | sim | sim | talvez se recorrente |
| frontend com screenshot | sim | sim | se visual amplo |
| refactor 3-5 arquivos | sim com cuidado | sim com gates | se arquitetura |
| migration/producao/security | plan/review | escalar cedo | sim |
| multi-provider/council | opcional | nao default | sim |
| evidence/replay/auditoria | parcial | simples | forte |
| tarefa longa/enterprise | nao ideal | nao ideal | sim |

## Produto Futuro: Atlas Dev Sonnet

Antes de existir como arm confiavel no Rivals, Atlas Dev + Sonnet precisa virar
um runner real com contrato proprio. O nome de produto pode ser
`atlas_dev_light`, `atlas_dev_sonnet`, `sonnet_killer_mode` ou outro; o nome
importa menos que o comportamento.

```text
1. Intake
   - normalizar tarefa
   - classificar kind: question | patch | repair | review | frontend | risky
   - detectar risco e criterios de escalada

2. Context
   - workspace summary
   - git status
   - arquivos provaveis
   - Open Brain compacto
   - semantic code graph quando barato

3. Short Plan
   - 3-6 passos max
   - file scope provavel
   - teste focado provavel
   - escalation check

4. Provider Call
   - Sonnet ou Codex
   - prompt curto, provider-safe
   - sem council por padrao
   - low call budget

5. Patch
   - aplicar diff
   - preservar mudancas do usuario
   - bloquear out-of-scope obvio

6. Verification
   - teste focado
   - lint/typecheck quando barato
   - no-test reason aceito para docs/read-only

7. Repair
   - no max 1-2 tentativas por default
   - repair capsule com erro real
   - sem trocar provider sem politica

8. Finish
   - summary
   - files changed
   - tests run
   - risks
   - escalation recommendation
```

### Nao Objetivos

- nao deve ter replay/evidence pesado como Forge;
- nao deve executar topology multi-provider por padrao;
- nao deve virar Obra automaticamente;
- nao deve esconder falha de teste;
- nao deve declarar sucesso sem patch/teste/motivo.

### Escalada automatica para Forge

Escalar ou recomendar Forge quando:

- risco alto (`production`, `security`, `migration`, `billing`, auth);
- camada >= 3 (db + API + UI, por exemplo);
- mais de 5-6 arquivos esperados;
- qualidade falha apos repair;
- contexto necessario excede budget;
- operador pede "forge", "obra", "arquitetura", "RFC";
- precisa evidence/replay/auditoria;
- precisa multi-provider/council.

## Preparacao Futura Para Rivals

Rivals e a prova final. Nao e o primeiro lugar onde vamos desenvolver a
maquina. Esta secao existe para nao perder de vista como a competicao sera
medida depois que Atlas Dev + Sonnet estiver pronto.

### Regra De Entrada No Rivals

Nao criar ou alterar arm de Rivals ate existir:

- driver real do Atlas Dev + Sonnet;
- bateria local reproduzivel;
- resultados locais salvos;
- contrato de output estavel;
- custo e numero de chamadas registrados;
- politica de escalada congelada;
- criterio explicito de comparacao com Opus puro.

### Bracos Que Vamos Querer Comparar Depois

Bracos sugeridos:

```text
claude_sonnet_puro
claude_opus_puro
codex_puro
atlas_dev_light_sonnet
atlas_dev_light_codex
atlas_forge_sonnet
atlas_forge_opus
```

Categorias:

- human-normal;
- messy-real;
- frontend/UI;
- backend/logica;
- bugfix;
- refactor;
- review;
- repair;
- architecture/risk.

Metricas:

- qualidade;
- conclusao sem humano;
- custo;
- tempo;
- numero de chamadas;
- patch size;
- files changed vs expected;
- teste certo rodado;
- erro de escopo;
- repair success;
- escalation accuracy;
- false escalation para Forge;
- missed escalation para Forge.

Tese:

```text
Atlas Dev + Sonnet
≈ custo Sonnet puro
> qualidade Sonnet puro
≈ ou > Opus puro em tarefas praticas
< custo Forge
```

## Decisoes A Tomar

1. Runner real do Atlas Dev + Sonnet: chamar CLI existente ou criar service
   dedicado?
2. Default provider: Sonnet, Codex ou Atlas Decide?
3. Quantas chamadas max por task leve: 1, 2 ou 3?
4. Test policy: sempre teste focado quando patch, ou somente quando detectado?
5. Open Brain budget default: 6k, 12k, 20k chars?
6. Quando acionar `code-reviewer`?
7. Quando bloquear write e pedir Forge?
8. Como registrar custo por chamada no Rivals?
9. Como diferenciar "no patch needed" de falha?
10. Como evitar que Dev Light use Forge indiretamente e contamine benchmark?
11. Qual e a bateria local minima para declarar "pronto para Rivals"?
12. Qual score minimo contra Opus puro justifica ativar arm real?
13. Quais tarefas representam uso diario, e quais sao apenas show-off?
14. Como salvar aprendizado de falhas para melhorar o proximo run?

## Implementacao Recomendada Em Fatias

### Fatia 0: caderno de campanha

- manter este doc atualizado por toda a sessao;
- registrar todo contexto recebido do usuario;
- transformar contexto em hipoteses/backlog;
- nao alterar Rivals.

### Fatia 1: contrato local e dry-run

- definir contrato local do Atlas Dev + Sonnet;
- montar plano sem provider;
- evidenciar context/plan/test policy;
- definir score local antes de benchmark externo.

### Fatia 2: provider real de uma chamada

- Sonnet apenas;
- contexto compacto;
- patch + teste focado;
- sem repair automatico;
- status `passed|needs_review|failed|escalate_forge`.

### Fatia 3: repair leve

- uma tentativa de repair com erro real;
- manter mesmo provider/model;
- registrar failure capsule;
- escalar se falhar.

### Fatia 4: Codex variant

- variante Atlas Dev + Codex;
- mesma pipeline, outro provider;
- comparar por categoria.

### Fatia 5: Decide integration

- escolher Sonnet/Codex por categoria/ranking;
- registrar motivo;
- preservar modo benchmark deterministico.

### Fatia 6: promotion loop

- quando `escalate_forge`, gerar promotion preview;
- nao criar Obra sem confirmacao;
- permitir Attention mostrar candidato.

