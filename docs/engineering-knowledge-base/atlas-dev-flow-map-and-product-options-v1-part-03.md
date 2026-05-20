---
id: atlas-dev-flow-map-and-product-options-v1-part-03
type: engineering_knowledge
title: Atlas Dev Flow Map And Product Options v1 · Parte 3
status: active
category: programming
priority: 104
summary: Recorte focado de Atlas Dev Flow Map And Product Options v1: Contexto 4: Opiniao Externa Sobre Atlas Dev Efficient Programming Flow ate O que ainda nao existe como driver proprio.
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
graph_id: atlas-dev-flow-map-and-product-options-v1-part-03
graph_title: Atlas Dev Flow Map And Product Options v1 Parte 3
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-dev-flow-map-and-product-options-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-03.md
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
# Atlas Dev Flow Map And Product Options v1 · Parte 3

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Flow Map And Product Options v1: Contexto 4: Opiniao Externa Sobre Atlas Dev Efficient Programming Flow ate O que ainda nao existe como driver proprio.

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
### Contexto 4: Opiniao Externa Sobre Atlas Dev Efficient Programming Flow

Fonte: opiniao de outro Codex trazida pelo usuario.

Texto/ideia recebida:

```text
A tese nao e "Sonnet virar Opus por magica".
A tese e Atlas Dev + Sonnet vencer Sonnet puro porque Atlas fornece contexto,
spec, triagem, gates, memoria operacional e feedback.

Atlas Dev + Sonnet pode bater de frente com Opus em tarefas reais onde o
gargalo nao e so inteligencia bruta, mas processo.

Forge perde por custo quando o benchmark ja vem limpo, porque entra com
blindagem demais. Atlas Dev deve ser fast path eficiente; Forge deve ser heavy
path robusto; Rivals deve medir quando cada um vence.

Proximo documento/fluxo:
Atlas Dev Efficient Programming Flow
com fast path, compact SDD, context budget, adaptive gates, escalation to Forge,
human messy benchmark e cost-normalized rivals scoring.
```

Avaliacao desta sessao:

- concordo com a direcao;
- a frase "nao e Sonnet virar Opus por magica" e fundamental para manter rigor;
- o diagnostico sobre Forge e correto: Forge pode parecer pior em benchmark
  limpo porque paga custo de governanca que a tarefa nao exige;
- a peca nova e transformar Atlas Dev em fast path com governanca adaptativa;
- ainda falta transformar a tese em contrato: estados, budgets, gates,
  criterios de escalada e score local antes de Rivals.

Valor estrategico:

- protege a campanha contra hype de modelo;
- posiciona Atlas Dev como maquina de processo, nao como modelo alternativo;
- explica por que Forge nao deve ser julgado como default diario;
- cria uma arquitetura em tres velocidades:
  provider puro, Atlas Dev eficiente, Forge robusto;
- introduz o nome operacional `Atlas Dev Efficient Programming Flow`.

O que muda na tese Atlas Dev + Sonnet:

- a meta primaria imediata e vencer Sonnet puro de forma consistente;
- bater Opus puro e uma consequencia esperada em tarefas reais onde processo
  pesa mais que raciocinio bruto;
- tarefas limpas e bem especificadas nao sao o melhor campo de batalha;
- a suite de avaliacao precisa conter prompts humanos baguncados, nao apenas
  tickets perfeitos;
- custo normalizado importa tanto quanto qualidade.

Fluxos Atlas Dev afetados:

- intake deve distinguir `clean_task` de `messy_human_task`;
- planner deve gerar `compact_sdd`, nao spec longa por default;
- context builder deve respeitar `context_budget`;
- quality gate deve ser adaptativo por risco;
- repair deve ser barato e limitado;
- escalation deve ir para Forge quando o fast path deixa de ser seguro.

Hipoteses geradas:

- Em tarefas humanas baguncadas, Atlas Dev + Sonnet vence Sonnet puro por margem
  clara.
- Em tarefas humanas baguncadas, Atlas Dev + Sonnet empata ou vence Opus puro em
  qualidade/custo quando verificacao e repair contam no score.
- Em tarefas limpas, Opus puro pode vencer ou empatar, e isso nao invalida a
  tese.
- Forge deve vencer em tarefas longas/criticas, mas perder em custo no fast
  path; isso e esperado.

Experimentos derivados:

- criar bateria `human_messy_local` com prompts naturais, incompletos e
  multiinterpretaveis;
- criar bateria `clean_ticket_local` para medir onde Opus direto continua
  forte;
- medir score normalizado por custo e tempo;
- comparar quatro modos locais antes de Rivals: Sonnet puro, Opus puro, Atlas
  Dev + Sonnet, Forge + Sonnet;
- registrar quando Atlas Dev escalaria para Forge e se essa escalada foi
  correta.

Decisoes ou nao-decisoes:

- decisao: a proxima arquitetura a desenhar e
  `Atlas Dev Efficient Programming Flow`.
- decisao: fast path e heavy path devem ter contratos diferentes.
- decisao: benchmark deve incluir tarefa humana baguncada.
- nao-decisao: nao prometer vitoria universal contra Opus.
- nao-decisao: nao mudar Rivals ainda.

Riscos:

- otimizar Atlas Dev para ticket limpo e perder o diferencial real;
- comparar Forge contra provider puro em tarefa pequena e concluir errado;
- criar gates adaptativos tao flexiveis que virem ausencia de governanca;
- normalizar custo de forma injusta e mascarar baixa qualidade;
- declarar vitoria contra Opus sem separar clean vs messy.

Backlog candidato:

- criar doc/section `Atlas Dev Efficient Programming Flow`;
- definir `fast_path_contract`;
- definir `compact_sdd_schema`;
- definir `context_budget_policy`;
- definir `adaptive_gate_policy`;
- definir `messy_human_benchmark`;
- definir `cost_normalized_score`;
- definir `forge_escalation_thresholds`.

## Sintese Dos 5 Agentes Especializados

Documento filho consolidado:

- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md`.

Os cinco agentes convergiram em uma arquitetura unica:

```text
Surface / CLI / App
-> OperationEnvelope
-> Intake normalizado
-> Workspace + permission preflight
-> Classificacao question|patch|repair|review|frontend|risky
-> Risk level R0-R5
-> DocContextTierSelector
-> CodeDiscoveryManifest
-> Open Brain Programming Projection
-> CompactSDD
-> MiniProgrammingSpec
-> LightTaskContract
-> RoutingDecision
-> ProviderDecision
-> ScopedExecution
-> ScopeGuardReceipt
-> FocusedVerification
-> VerificationReceipt
-> CheapRepair se permitido
-> CompletionState ou Forge promotion preview
```

Decisoes consolidadas:

- Atlas Dev + Sonnet e fast path diario; Forge e heavy path robusto.
- Todo write precisa de mini-spec, task contract, scope guard e receipt.
- Contexto e selecionado por tiers, nao despejado inteiro no prompt.
- Code Intelligence e autoridade operacional para achar arquivos/simbolos.
- Repair e barato, limitado, mesma provider/model, baseado em erro real.
- `passed` so existe com evidence; `needs_review` nao conta como sucesso cheio.
- Risco R4/R5 gera plan-only + promotion preview, nao patch Dev.
- Rivals continua congelado ate prova local.

Artefatos alvo:

- `CompactSDD`;
- `MiniProgrammingSpec`;
- `LightTaskContract`;
- `CodeDiscoveryManifest`;
- `OpenBrainProgrammingProjection`;
- `ScopeGuardReceipt`;
- `VerificationReceipt`;
- `FailureCapsule`;
- `EscalationDecision`;
- `LocalBenchmarkScorecard`.

Decisao atual apos corte de escopo:

- foco exclusivo em criar o Atlas Dev robusto;
- sem Rivals;
- sem Opus challenge;
- sem benchmark competitivo;
- sem battery de prompts;
- sem score custo-normalizado;
- sem oracle privado de avaliacao;
- sem claim de vitoria.

O que permanece no fluxo de construcao:

- `ProviderPromptProjectionContract`;
- `FastPathTelemetrySchema`;
- `FastPathErrorLedger`;
- persistencia local de receipts;
- quality build gates;
- no-rivals-leakage tests;
- prompt/provider projection gerado de contratos fortes, nao de prompt solto.

## Tese Sonnet Contra Opus

### Formula De Vitoria

```text
Atlas Dev + Sonnet > Opus puro
quando:
  ganho_de_contexto
+ ganho_de_escopo
+ ganho_de_execucao
+ ganho_de_teste
+ ganho_de_reparo
+ ganho_de_memoria_local
>
  vantagem_bruta_de_modelo_do_Opus
```

### Onde Sonnet Pode Vencer

- bugfix com teste falhando;
- tarefa com prompt humano incompleto;
- mudanca pequena em repo grande;
- refactor localizado;
- frontend onde screenshot/verificacao visual guia a correcao;
- tarefa que exige achar os arquivos certos;
- tarefa que exige preservar mudancas do usuario;
- tarefa que exige rodar o teste certo, nao todos os testes;
- tarefa que exige corrigir um erro pequeno apos primeira tentativa.

### Onde Opus Pode Continuar Forte

- raciocinio arquitetural sem repo;
- design de sistema abstrato;
- refactor muito amplo sem limite de escopo;
- tarefas com requisitos ambiguidade alta e pouca verificacao;
- escrita de RFC conceitual.

### Como Atlas Deve Compensar

| vantagem do Opus puro | resposta Atlas Dev + Sonnet |
| --- | --- |
| raciocinio bruto melhor | decompor em plano curto e verificavel |
| entende prompt ambivalente | normalizar intencao e perguntar so quando necessario |
| aguenta contexto grande | escolher contexto certo e evitar ruido |
| melhor julgamento em abstrato | usar repo, testes e diffs como grounding |
| menos necessidade de repair | usar repair barato com erro real |
| solucao mais completa | limitar escopo e validar comportamento esperado |

## Criterios De Vitoria Antes De Rivals

Atlas Dev + Sonnet so esta pronto para entrar no Rivals quando passar por uma
bateria local com estes sinais:

- melhora clara sobre Sonnet puro em tarefas praticas;
- empate ou vitoria contra Opus puro em parte relevante das tarefas comuns;
- custo por tarefa perto de Sonnet puro;
- tempo aceitavel para uso diario;
- no maximo 1-2 repairs na maioria dos casos;
- baixa taxa de erro de escopo;
- alta taxa de teste focado correto;
- falhas declaradas honestamente;
- escalada para Forge quando risco e alto;
- logs suficientes para reproduzir comparacao.

### Definicao De "Destruir Opus"

Nesta campanha, "destruir Opus" nao significa vencer todos os tipos de tarefa.
Significa vencer onde importa para o produto diario:

```text
Em repos reais, com prompts humanos normais,
Atlas Dev + Sonnet entrega mais tarefas corretas,
com custo menor ou parecido,
com menos erro de escopo,
com teste/verificacao melhores,
do que Opus puro sem orquestracao.
```

## Estado Atual

### O que ja existe

Atlas Dev ja tem estas capacidades implementadas:

- comando `atlas:cli:dev` para one-shot, plan-only e cockpit interativo;
- delegacao para `atlas:ai:chat --dev --cockpit`;
- `atlas:cli:fix` como alias fino para `atlas:cli:dev --repair`;
- `atlas:cli:continue` para retomar plano anterior;
- workspace auto-detectado pelo git root;
- provider/model manual ou Atlas Decide;
- override de modelo por alias/id;
- modo Fair Claude com provider/model lock e sem fallback/council;
- Open Brain auto/required/off, refresh e budget;
- imagens por arquivo, clipboard e auto-image;
- skill bundles, incluindo `dev-quality-gate`;
- Kernel Pipeline scaffold e guard;
- Programming Orchestrator com agentic RAG, sandbox plan, test impact,
  patch verifier, stage receipts, repair contract e frontend design harness;
- quality gate pos-execucao no chat dev;
- escalada para Engineering Harness quando perfil e `forge` ou quando
  intencao/risco pedem harness;
- promocao Dev -> Forge por preview/candidate/Obra;
- surface adapters para CLI Dev, Desktop AI e App.

### O que ainda nao existe como driver proprio

O `atlas_dev_light` do Rivals esta declarado como arm, mas real-run ainda
bloqueia honestamente com `atlas_dev_light_driver_pending`. Hoje, o caminho
real do Atlas Dev passa por `atlas:cli:dev` -> `atlas:ai:chat` -> provider ou
Engineering Harness. O driver dedicado ainda precisa ser criado.

