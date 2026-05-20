---
id: atlas-dev-flow-map-and-product-options-v1-part-05
type: engineering_knowledge
title: Atlas Dev Flow Map And Product Options v1 · Parte 5
status: active
category: programming
priority: 104
summary: Recorte focado de Atlas Dev Flow Map And Product Options v1: Open Brain E Contexto ate Review.
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
graph_id: atlas-dev-flow-map-and-product-options-v1-part-05
graph_title: Atlas Dev Flow Map And Product Options v1 Parte 5
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-dev-flow-map-and-product-options-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-05.md
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
# Atlas Dev Flow Map And Product Options v1 · Parte 5

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Flow Map And Product Options v1: Open Brain E Contexto ate Review.

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
## Open Brain E Contexto

Atlas Dev injeta ou previewa Open Brain por padrao:

- modo normal: `auto`;
- `--forge`: `required`;
- `--no-open-brain`: desliga;
- `--require-open-brain`: falha fechado se nao houver contexto;
- `--open-brain-refresh`: forca refresh;
- `--open-brain-budget=<chars>`: limita budget.

No plan-only, `open_brain_preview` mostra:

- status;
- surface (`cli_dev` ou `cli_continue`);
- context readiness;
- provider_execution_allowed;
- context_pack_hash;
- audit_id;
- summary compacta;
- warnings e next actions.

Para Atlas Dev Light, esse e um dos maiores diferenciais contra provider puro:
contexto selecionado e auditavel antes da chamada.

## Kernel Pipeline

`KernelPipelineDevPlanBuilder` anexa um scaffold de pipeline a todo plano Dev:

```text
schema_version = atlas.kernel.pipeline.scaffold.v1
surface_id = atlas_cli_dev | atlas_ai_chat | atlas_cli_forge
flow = programming.dev | programming.repair | programming.forge
runtime = dev_repair_executor | engineering_harness | ...
input_mode = one_shot | interactive | chat_dev_auto_plan | declared_dev_plan
provider_execution_allowed = false no scaffold inicial
kernel_pipeline_contract.required = true
```

O guard valida planos declarados por `--dev-plan`. Planos aceitos/rejeitados
sao auditados pelo Kernel Pipeline Audit Service.

## Programming Orchestrator

Arquivo: `app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php`.

O `sessionPlan()` monta o contrato de programacao:

- `plan_id`;
- `programming_profile`: `dev` ou `forge`;
- `programming_flow`;
- `executor_decision`;
- `execution_profile`;
- `policy_profile`;
- `policy_contracts`;
- `agent_behavior_contract`;
- `operational_decision`;
- `agentic_rag_plan`;
- `stage_receipt_plan`;
- `resume_state`;
- `test_impact_plan`;
- `sandbox_plan`;
- `patch_verifier_gate`;
- `learning_candidate_policy`;
- `programming_orchestration_contract`;
- `frontend_design_harness_contract` quando aplicavel;
- `repair_execution_contract`.

Executores:

| executor | quando |
| --- | --- |
| `simple_provider_execution` | fluxo leve sem repair/harness |
| `dev_repair_executor` | Dev completo com repair/quality loop |
| `engineering_harness` | Forge ou intencao/risco que exige harness |

## Quality Gate E Repair

### Quality gate

O `dev-quality-gate` e anexado quando:

- complete mode esta ativo;
- ou max iterations > 1.

Policy:

```text
procedure = plan_validate_execute
required_final_status = passed em complete/fair mode
required_final_status = not_failed em modo leve/single-shot
```

No chat dev, `maybeRunDevQualityGate()` roda depois da interacao quando:

- modo e `dev`;
- nao esta `--json`;
- nao esta `--no-run`;
- nao esta `--no-quality-gate`;
- sem imagem, ou verbose quando ha imagem.

### Repair

Repair e ativado por:

- `--repair`;
- `atlas:cli:fix`;
- `/fix` no REPL;
- sinais no texto;
- gate failed/needs_review.

Contrato:

- max iterations normalizado;
- stop quando passed;
- stop se qualidade piora;
- repair pesado exige evidencia;
- fallback nao e permitido dentro do repair capsule;
- Kernel repair decision e obrigatoria antes de enfileirar repair estrutural.

## Permissoes E Sandbox

Flags principais:

| flag | efeito |
| --- | --- |
| `--permission=read|write|danger` | modo de permissao |
| `--allow-write` | confirma writes no workspace |
| `--operator` | promove para danger local operator |
| `--allow-unsandboxed` | permite write/danger com provider sem sandbox direto |
| `--dangerously-allow-all` | confirma danger-full-access |
| `--sandbox` | override para Forge/Harness |
| `--provider-runtime` | host/docker/auto em Forge/Harness |

Default atual do comando tende a `write` quando permitido pela config. Isso e
conveniente para CLI, mas para Atlas Dev Light em Rivals precisamos separar:

- Dev Light plan-only/read;
- Dev Light patch/write;
- Dev Light harness-escalated.

## Imagens E UI

Atlas Dev aceita:

- `--image=<path>`;
- `--clipboard-image`;
- auto-attach quando prompt menciona screenshot/imagem;
- `--no-auto-image`.

Surface CLI Dev declara `IMAGE_PASTE`. Desktop/App declaram image uploads ou
attachments. Para UI/frontend, o Orchestrator pode anexar
`frontend_design_harness_contract`, exigindo evidencias como screenshots,
visual smoke multi-viewport, no text overlap, a11y/perf or reason.

## Skills

Flags:

```text
--skill=dev-quality-gate
--skill=code-reviewer
--skill=engineering-blueprint
```

Regras atuais:

- `dev-quality-gate` entra automaticamente em complete/multi-iteration;
- `engineering-blueprint` entra quando existe engineering contract/task;
- workspace skills podem ser confiadas/ignoradas no chat;
- skill trace e esperado pelos flows de programacao.

Para Atlas Dev Light, as skills default provavelmente devem ser:

- `dev-quality-gate` sempre que houver patch;
- `code-reviewer` apenas em risco medio ou diff amplo;
- `engineering-blueprint` apenas quando houver task contract.

## Task ID E Engineering Contract

`--task-id` carrega `AtlasTask`, gera:

- `atlas_task`;
- `engineering_contract`;
- `engineering_blueprint`;
- `engineering_blueprint_snapshot`;
- prompt enriquecido com escopo, acceptance, likely files, tests e refs.

Isso permite Atlas Dev operar como camada diaria sobre tasks existentes sem
virar Obra/Forge automaticamente.

## Dev -> Forge Promotion

Arquivos:

- `DevToForgePromotionService`;
- `PromotionSignalDetector`;
- `AtlasCodeDevToForgePromotionController`.

Endpoints:

```text
GET  /atlas-code/dev-to-forge/threads/{thread}/promotion-preview
POST /atlas-code/dev-to-forge/threads/{thread}/promote
GET  /atlas-code/dev-to-forge/candidates
GET  /atlas-code/dev-to-forge/candidates/{candidate}
POST /atlas-code/dev-to-forge/candidates/{candidate}/dismiss
```

Targets:

| target | uso |
| --- | --- |
| `none` | continue em Atlas Dev |
| `quick_intervention` | pequeno, claro, reversivel |
| `obra_candidate` | precisa descoberta/decisao humana |
| `forge_obra` | trabalho pesado/governado |

Sinais usados:

- densidade de mensagens;
- tamanho do contexto;
- quantidade de arquivos/subsistemas;
- arquitetura/spec/refactor;
- risco/producao/migration/security;
- falha recorrente;
- pedido explicito do operador;
- veto `thin_small_bug` para chat curto com <=1 arquivo e sem risco.

Regra: o servico nunca auto-cria Obra sem chamada de promocao. Preview e
read-only; humanos decidem via Attention/operador.

## Casos De Uso

### Pergunta tecnica com workspace

Exemplo: "onde fica a validacao de checkout?"

Fluxo ideal:

```text
programming.dev
read/context only
Open Brain auto
sem patch
resposta com refs
sem quality gate obrigatorio
```

### Bug pequeno

Exemplo: "corrija typo/variavel/condicao simples".

Fluxo ideal:

```text
programming.dev
Atlas Decide ou manual provider
patch pequeno
teste focado ou motivo
quality gate simples
nao promover para Obra
```

### Debug / teste falhando

Exemplo: "o teste X esta falhando".

Fluxo:

```text
programming.repair
failure signal
repair capsule
patch minimo
teste que falhava
max_iterations 3
```

### Review

Exemplo: "revise este diff".

Fluxo:

```text
programming.review
read-only preferencial
sem patch por padrao
findings primeiro
promover se risco/arquitetura crescer
```

