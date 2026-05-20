---
id: atlas-dev-flow-map-and-product-options-v1-part-02
type: engineering_knowledge
title: Atlas Dev Flow Map And Product Options v1 · Parte 2
status: active
category: programming
priority: 104
summary: Recorte focado de Atlas Dev Flow Map And Product Options v1: Contexto 3: Pacote De Leitura Enterprise Para Nao Perder Pecas.
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
graph_id: atlas-dev-flow-map-and-product-options-v1-part-02
graph_title: Atlas Dev Flow Map And Product Options v1 Parte 2
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-dev-flow-map-and-product-options-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-02.md
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
# Atlas Dev Flow Map And Product Options v1 · Parte 2

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Flow Map And Product Options v1: Contexto 3: Pacote De Leitura Enterprise Para Nao Perder Pecas.

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
### Contexto 3: Pacote De Leitura Enterprise Para Nao Perder Pecas

Fonte: segundo contexto do usuario sobre docs que outro Codex deveria ler para
estruturar o fluxo completo.

Texto/ideia recebida:

```text
Nao e so a lista anterior. A lista anterior e o mapa principal.
Para estruturar o fluxo sem perder nada, outro Codex precisa ler pelo menos:
Nucleo Obrigatorio + Specs/SDD + Atlas Code/Interface.

Forge, Code Intelligence e Obras sao a camada de profundidade para nao
transformar o fluxo em uma UI bonita sem engenharia real.
```

Frase operacional recebida:

```text
Estruture o fluxo de programacao assistida por IA do Atlas usando Spec OS como
cerebro de especificacao, Programming Governance como trilho de execucao
governada, Atlas Code SCOR-1 como cockpit visual, Forge OS como patamar
multiagente, Code Intelligence como mapa real do codigo, Evidence Ledger como
prova e Obras/Forge Workspace como unidade de producao persistente.
```

Valor estrategico:

- define o pacote minimo de contexto para qualquer IA entender o fluxo sem
  achatar o Atlas em "chat + UI";
- separa espinha dorsal de profundidade enterprise;
- mostra quais documentos devem alimentar Open Brain/context packs quando Atlas
  Dev estiver trabalhando em programacao;
- previne implementacoes bonitas, mas sem spec, evidence, workspace persistente
  ou code intelligence;
- cria uma ontologia clara para a maquina Sonnet: Spec OS pensa, Governance
  governa, Code Intelligence localiza, Evidence prova, Obras persiste, Forge
  escala.

#### Pacote A: Nucleo Obrigatorio

Estes docs sao leitura obrigatoria para qualquer mudanca estrutural no Atlas
Dev ou para qualquer IA que va redesenhar o fluxo:

- `docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md`;
- `docs/engineering-knowledge-base/atlas-programming-governance-system.md`;
- `docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md`;
- `docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md`;
- `docs/engineering-knowledge-base/atlas-forge-operating-system.md`;
- `docs/engineering-knowledge-base/atlas-forge-operating-system-contracts.md`;
- `docs/engineering-knowledge-base/atlas-forge-operating-system-runbook.md`.

Papel para Atlas Dev + Sonnet:

- extrair leis e invariantes;
- decidir quando spec leve basta e quando Forge e necessario;
- impedir que Sonnet implemente fora da governanca canonica.

#### Pacote B: Specs/SDD Detalhado

Estes docs definem o cerebro de especificacao:

- `docs/engineering-knowledge-base/spec-operating-system/context-discovery-and-business-context.md`;
- `docs/engineering-knowledge-base/spec-operating-system/spec-compiler-and-critic.md`;
- `docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md`;
- `docs/engineering-knowledge-base/spec-operating-system/spec-graph-and-traceability.md`;
- `docs/engineering-knowledge-base/spec-operating-system/templates-and-schemas.md`;
- `docs/engineering-knowledge-base/spec-operating-system/data-model-and-services.md`;
- `docs/engineering-knowledge-base/spec-operating-system/context-packages-and-projections.md`;
- `docs/engineering-knowledge-base/spec-operating-system/drift-detector-and-learning.md`.

Papel para Atlas Dev + Sonnet:

- transformar prompt humano em mini-spec;
- gerar plano e task contract compacto;
- manter rastreabilidade entre intencao, arquivos, testes e evidence;
- detectar drift quando codigo, doc e spec se afastam.

#### Pacote C: Atlas Code / Interface

Estes docs definem o cockpit e a projecao visual do trabalho:

- `docs/engineering-knowledge-base/atlas-desktop-code-surface.md`;
- `docs/engineering-knowledge-base/atlas-desktop-backend-contract.md`;
- `docs/engineering-knowledge-base/atlas-code-long-session-programming-cockpit.md`;
- `docs/engineering-knowledge-base/atlas-code-scor-1-implementation-contract.md`;
- `docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md`;
- `docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md`;
- `docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md`.

Lacuna verificada:

- `docs/engineering-knowledge-base/atlas-code-work-intake-spec-governance-v1.md`
  foi citado no contexto, mas nao existe neste repo neste caminho.
- O arquivo existente relacionado e
  `docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md`.

Papel para Atlas Dev + Sonnet:

- garantir que o modo CLI/runtime tenha artefatos que depois possam aparecer no
  cockpit;
- preservar estados honestos em vez de mock;
- modelar checkpoint/resume, gates, evidence e scope guard como dados reais.

#### Pacote D: Forge / Execucao Avancada

Estes docs sao profundidade para escalada, nao default diario:

- `docs/engineering-knowledge-base/atlas-programming-forge-flow.md`;
- `docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md`;
- `docs/engineering-knowledge-base/atlas-code-forge-live-execution-surface-contract.md`;
- `docs/engineering-knowledge-base/atlas-code-forge-operator-cockpit-v1.md`;
- `docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md`;
- `docs/engineering-knowledge-base/atlas-forge-runtime-certification-one-shot.md`;
- `docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md`.

Papel para Atlas Dev + Sonnet:

- definir criterio de escalada;
- evitar recriar multiagente/topology no modo leve;
- reaproveitar padroes de completion/review quando risco justificar.

#### Pacote E: Code Intelligence / Ferramentas

Estes docs impedem que Atlas Dev trabalhe cego:

- `docs/engineering-knowledge-base/code-intelligence.md`;
- `docs/engineering-knowledge-base/code-intelligence/README.md`;
- `docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md`;
- `docs/engineering-knowledge-base/programming-power-tools-catalog.md`;
- `docs/engineering-knowledge-base/tool-runtime/programming-tool-families.md`;
- `docs/engineering-knowledge-base/tool-runtime/evidence-gates.md`.

Papel para Atlas Dev + Sonnet:

- achar arquivos/simbolos certos antes da chamada;
- escolher testes e comandos provaveis;
- gerar evidence verificavel;
- reduzir a vantagem de contexto bruto do Opus.

#### Pacote F: Obras / Workspace Compartilhado

Estes docs definem unidade persistente de producao:

- `docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md`;
- `docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md`.

Papel para Atlas Dev + Sonnet:

- garantir que sessao longa nao dependa de memoria de chat;
- preparar handoff para SCOR/Forge quando a tarefa crescer;
- registrar contexto, decisoes, artifacts e evidence em unidade persistente.

O que muda na tese Atlas Dev + Sonnet:

- a maquina precisa de "context package" governado por camadas, nao RAG solto;
- cada run deve saber qual pacote de docs e necessario pelo risco da tarefa;
- para tarefas simples, carregar apenas Nucleo minimo + Code Intelligence
  compacto;
- para tarefas estruturais, incluir Spec/SDD detalhado;
- para tarefas longas/UI/Obra, incluir Atlas Code/Interface;
- para risco alto, preparar escalada para Forge.

Hipoteses geradas:

- Um Sonnet alimentado com pacote de leitura correto vence Opus puro alimentado
  apenas por prompt humano em tarefas de repo.
- A selecao de pacote por risco melhora qualidade sem explodir contexto.
- Code Intelligence + Spec OS e o combo mais importante para reduzir erro de
  arquivo e erro de escopo.
- Obras/Workspace melhora continuidade em sessoes longas mais do que aumentar
  tamanho de contexto.

Experimentos derivados:

- criar `doc_context_tiers` para Atlas Dev: core, sdd, interface, forge,
  code_intelligence, obras;
- medir run com prompt humano bruto vs run com pacote core+sdd compacto;
- medir se Code Intelligence reduz arquivos tocados fora de escopo;
- medir se context tiers menores preservam custo perto de Sonnet puro;
- testar tarefa longa com resumo persistente de decisoes/arquivos/evidence.

Decisoes ou nao-decisoes:

- decisao: este pacote vira referencia de leitura enterprise da campanha.
- decisao: Atlas Dev nao deve carregar tudo sempre; deve selecionar por risco.
- decisao: `atlas-code-work-intake-spec-governance-v1.md` e lacuna ou nome
  antigo, nao deve ser tratado como arquivo existente.
- nao-decisao: nao transformar essa lista em dependencia obrigatoria para toda
  tarefa simples.

Riscos:

- carregar docs demais e perder a tese de custo;
- carregar docs de menos e construir UI rasa sem engenharia real;
- usar Forge docs como desculpa para deixar Atlas Dev pesado;
- deixar lacunas de arquivo virarem referencias quebradas em prompts;
- confundir unidade persistente de producao com conversa solta.

Backlog candidato:

- implementar `DocContextTierSelector` para Atlas Dev;
- adicionar manifest `atlas_dev_enterprise_reading_pack`;
- criar projection compacta desses docs para Open Brain;
- adicionar checker de paths de docs antes de montar prompt;
- registrar no receipt quais docs foram usados e por qual motivo;
- criar fallback quando doc citado nao existe: related existing path + gap.

