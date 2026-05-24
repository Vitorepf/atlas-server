---
id: atlas-runtime-efficiency-governor
type: engineering_knowledge
title: Atlas Runtime Efficiency Governor
status: active
category: autonomous-intelligence
priority: 100
summary: Define o AREG, Atlas Runtime Efficiency Governor, a camada que governa orcamento cognitivo, contexto minimo, admissao de camadas, ferramentas, providers, subagentes, verificacao e aprendizado de eficiencia para manter o Atlas poderoso sem ficar pesado ou confuso.
implementation_state: runtime_implemented_l1_to_l5_primitives
macro_layer: true
product_name: Atlas Runtime Efficiency Governor
runtime_acronym: AREG
internal_product_name: Atlas Cognitive Budget Controller
technical_runtime: AtlasRuntimeEfficiencyGovernorService
tags:
  - atlas-ai
  - areg
  - runtime-efficiency
  - cognitive-budget
  - context-minimum
  - tool-budget
  - provider-routing
  - subagent-governance
  - adaptive-runtime
capabilities:
  - context_budgeting
  - layer_admission
  - tool_budgeting
  - provider_fit
  - subagent_roi
  - context_minimum_pack
  - fast_path
  - deep_path
  - efficiency_counterfactual_estimation
  - cognitive_policy_compiler
decisions:
  - O nome canonico/produto e Atlas Runtime Efficiency Governor.
  - O acronimo tecnico obrigatorio e AREG.
  - O nome interno de experiencia/superficie e Atlas Cognitive Budget Controller.
  - O runtime tecnico canonico e AtlasRuntimeEfficiencyGovernorService.
  - AREG nao responde ao usuario diretamente; governa quanto Atlas usar para cada tarefa.
  - AREG deve impedir tanto undercontext quanto overcontext.
  - AREG deve escolher fast path por padrao e deep path apenas com justificativa.
  - AREG aprende com AEMOR quais orcamentos, camadas, tools, providers e subagentes funcionaram.
maintenance:
  - Atualizar antes de implementar novas camadas de contexto, subagentes, tools ou provider routing.
  - Nao adicionar camada pesada ao Hyperflow sem regra AREG de admissao, custo e utilidade.
  - Nao confundir AREG com ASRE: ASRE decide estrategia; AREG decide eficiencia operacional.
related_paths:
  - docs/engineering-knowledge-base/atlas-quality-preserving-efficiency-system.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - docs/engineering-knowledge-base/atlas-conversation-operations-layer.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-intelligence-factory-os.md
  - docs/engineering-knowledge-base/atlas-strategic-reality-engine.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-runtime-efficiency-governor
graph_title: Atlas Runtime Efficiency Governor
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-ai
graph_status: active
graph_source: repo
human_name: Atlas Runtime Efficiency Governor
canonical_name: Atlas Runtime Efficiency Governor
technical_name: AtlasRuntimeEfficiencyGovernorService
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-runtime-efficiency-governor.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-runtime-efficiency-governor.md
allowed_changes:
  - Criar contracts, service, middleware Hyperflow, read models, commands e tests para AREG.
  - Adicionar metricas de eficiencia, budget, layer utility e context ROI.
forbidden_changes:
  - Usar AREG para remover evidence gates ou policy gates obrigatorios.
  - Permitir que AREG execute tools externas diretamente.
  - Declarar ganho de qualidade sem AEMOR outcome ou benchmark interno.
  - Carregar docs completas, MCPs, skills ou tool manuals por padrao sem admissao AREG.
depends_on:
  - atlas-hyperflow-operation
  - atlas-persistent-context-runtime
  - atlas-context-intelligence-engine
  - atlas-conversation-operations-layer
  - atlas-execution-memory-outcome-runtime
  - atlas-intelligence-factory-os
  - atlas-strategic-reality-engine
flows_to:
  - atlas-quality-preserving-efficiency-system
  - atlas_ai
  - atlas_dev
  - atlas_forge
  - atlas_research
  - atlas_finance
  - atlas_marketing
  - atlas_strategy
unlocks:
  - context_minimum_runtime
  - adaptive_cognitive_path_routing
  - layer_utility_ledger
  - provider_cognitive_fit_model
  - subagent_roi_controller
governs:
  - atlas.runtime_efficiency_governor.v1
  - atlas.context_minimum_pack.v1
  - atlas.layer_admission_decision.v1
  - atlas.cognitive_budget.v1
  - atlas.subagent_context_budget.v1
evidence:
  - docs/engineering-knowledge-base/atlas-runtime-efficiency-governor.md
  - app/Services/Ai/RuntimeEfficiency/AtlasRuntimeEfficiencyGovernorService.php
  - app/Services/Ai/RuntimeEfficiency/AtlasRuntimeEfficiencyGovernorCertificationService.php
  - database/migrations/2026_05_20_170000_create_atlas_runtime_efficiency_tables.php
  - app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php
  - app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php
required_tests:
  - php artisan test tests/Feature/Ai/RuntimeEfficiency
  - php artisan atlas:runtime-efficiency:certify --json --strict
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
line_limit: 520
ai_entrypoints:
  - Leia esta doc antes de adicionar contexto, tools, MCPs, skills, subagentes ou camadas pesadas ao fluxo padrao.
  - Se uma tarefa simples estiver ativando runtime pesado, use AREG como contrato de correcao.
quality_gates:
  - php artisan atlas:engineering:knowledge docs-health --json
failure_modes:
  - Atlas ficar mais lento, caro e confuso por excesso de contexto.
  - Atlas errar por falta de contexto minimo obrigatorio.
  - Subagentes receberem contexto demais ou de menos.
  - Tools especializadas demais confundirem o runtime.
observability_signals:
  - context_budget_used
  - context_roi_score
  - layer_utility_score
  - overcontext_events
  - undercontext_events
  - fast_path_ratio
  - deep_path_success_rate
  - subagent_roi
  - provider_fit_score
patamar_current: areg_l5_self_tuning_cognitive_os_primitives
patamar_next: production_outcome_calibration
version_family: atlas-runtime-efficiency-governor
versions:
  - AREG-L1 Budget Governor
  - AREG-L2 Cognitive Path Router
  - AREG-L3 Adaptive Runtime Optimizer
  - AREG-L4 Counterfactual Governor
  - AREG-L5 Self-Tuning Cognitive OS
version_note: AREG evolui de controlador de orcamento para governador cognitivo auto-otimizavel.
next_actions:
  - Endurecer enforcement progressivo para que camadas caras possam ser realmente puladas quando AREG provar baixo risco.
  - Expandir a conexao `recordOutcomeFeedback` do AAEQ para mais surfaces Dev/Forge.
  - Calibrar politicas aprendidas por flow com dados reais de producao.
---
# Atlas Runtime Efficiency Governor

## Resumo

AREG, Atlas Runtime Efficiency Governor, e a camada que decide quanto Atlas
usar para cada tarefa. O estado maximo dele e o **Atlas Cognitive Operating
Governor**: um governador cognitivo que aprende continuamente qual combinacao
de contexto, camadas, ferramentas, providers, subagentes e verificacao gera o
melhor resultado.

Ele existe para impedir dois erros simetricos:

- **undercontext**: responder ou agir sem contexto critico;
- **overcontext**: carregar contexto, tools, skills e camadas demais ate o
  modelo ficar lento, caro e menos preciso.

## Papel no Atlas

O Atlas nasceu para nao depender de sessoes zeradas como Claude Code/Codex
puro. Mas a solucao nao pode ser carregar tudo. AREG garante:

- contexto minimo suficiente;
- camadas usadas apenas quando agregam;
- tools simples e limitadas;
- subagentes com pacotes pequenos e auditaveis;
- provider escolhido por encaixe cognitivo;
- verificacao proporcional ao risco;
- aprendizado via AEMOR quando o caminho escolhido funcionou ou falhou.

## Onde Se Encaixa

Fluxo esperado:

```text
Prompt
-> Intent Kernel / Hyperflow
-> AREG Cognitive Profiler
-> Context Minimum Planner
-> Layer Admission Controller
-> Tool / Provider / Subagent Budget
-> Runtime selecionado
-> Verification
-> AEMOR Outcome
-> Counterfactual Replay
-> Cognitive Policy Update
```

ASRE decide prioridade estrategica. AEMOR aprende com outcomes. ASEIF cria ou
melhora capabilities. AREG governa eficiencia operacional de todos eles.

## Contratos

Contrato principal:

```json
{
  "schema_version": "atlas.runtime_efficiency_governor.v1",
  "mode": "fast|standard|deep|forge|blocked",
  "context_budget": {
    "max_tokens": 12000,
    "must_keep": ["goal", "constraints", "files", "tests"],
    "excluded": ["old_chat_noise", "irrelevant_docs"]
  },
  "layers_enabled": ["APCR", "ACIE", "AEMOR"],
  "layers_skipped": [
    {"name": "ASRE", "reason": "not_strategic"}
  ],
  "tools_allowed": ["rg", "read_file", "apply_patch", "phpunit"],
  "verification_plan": ["focused_tests"],
  "risk": "medium",
  "claim_policy": {
    "external_execution": false,
    "requires_receipts": true
  }
}
```

Invariantes:

- contexto e indice primeiro; conteudo completo somente sob demanda;
- tool manifest primeiro; manual completo somente se a tool for admitida;
- memoria must-know primeiro; historico bruto quase nunca;
- toda camada pesada precisa justificar entrada;
- toda decisao AREG precisa virar telemetria e outcome AEMOR.

## Fluxo

AREG roda antes das camadas caras. Ele mede:

1. complexidade do pedido;
2. risco operacional;
3. necessidade de memoria;
4. necessidade de retrieval;
5. necessidade de tool;
6. necessidade de subagente;
7. necessidade de verificacao;
8. custo/latencia aceitavel;
9. risco de contexto insuficiente;
10. risco de contexto excessivo.

Depois emite um envelope de execucao que limita o que o runtime pode carregar.

## Regras para IA

1. Nao carregue todas as docs, MCPs, skills ou tools por padrao.
2. Para pergunta simples, use fast path.
3. Para tarefa longa, arriscada ou multi-step, use deep/forge path com
   evidence e verification budget.
4. Subagente nunca recebe historico bruto; recebe Context Minimum Pack.
5. Se faltar contexto critico, bloqueie ou busque mais antes de responder.
6. Se houver contexto demais, resuma, ranqueie e corte ruido antes de executar.
7. Toda camada usada precisa ter razao, custo e utilidade esperada.
8. Toda execucao deve registrar resultado para AEMOR calibrar o proximo budget.

## Escopo de Implementacao

### Nivel 1: Budget Governor

Controla tokens, contexto, tools e camadas.

- `ContextBudgetAllocator`
- `LayerAdmissionController`
- `ToolBudgetController`
- `VerificationBudgetPlanner`

### Nivel 2: Cognitive Path Router

Escolhe a forma mental do Atlas:

- resposta direta;
- analise curta;
- pesquisa profunda;
- debug com hipoteses;
- patch com teste;
- estrategia com ASRE;
- Forge long-horizon;
- multiagente;
- critic/adjudicator.

### Nivel 3: Adaptive Runtime Optimizer

Aprende com AEMOR qual caminho funciona melhor por dominio e flow:

- Laravel bug: contexto curto + `rg` + teste focado;
- estrategia: ASRE + freshness + assumptions;
- Forge longo: TEOS + APCR + AEMOR obrigatorios;
- research: retrieval profundo + source quality.

### Nivel 4: Counterfactual Governor

Compara caminhos alternativos:

- e se tivesse usado menos contexto?
- e se tivesse usado outro provider?
- e se tivesse chamado Forge antes?
- e se tivesse usado subagente?
- e se tivesse exigido critic?

Nao precisa executar tudo sempre; pode usar replay, outcomes e simulacao.

### Nivel 5: Self-Tuning Cognitive OS

Estado maximo. AREG ajusta automaticamente:

- thresholds de fast/deep/forge;
- budgets por flow;
- camadas padrao;
- tools permitidas;
- uso de subagentes;
- exigencia de critic;
- bloqueios de execucao;
- provider fit;
- policies operacionais compiladas.

## Dependencias

| Sistema | Uso pelo AREG |
| --- | --- |
| Hyperflow | ponto de entrada e flow/domain routing |
| APCR | contexto persistente e must-know ledger |
| ACIE | context pack, retrieval e sufficiency |
| ACOL | higiene de conversa e handoff |
| TEOS | continuidade temporal e freshness |
| AEMOR | outcome, eficiencia, replay e aprendizado |
| ASEIF | capability gaps e tools novas |
| ASRE | decisoes estrategicas quando o dominio exige |
| Control Plane | visibilidade de custo, qualidade e blockers |

## Evidencias

Estado atual: AREG-L1 a AREG-L5 estao implementados como primitivas
deterministicas locais. Isso significa que o runtime ja governa budgets,
calcula path, gera context minimum, admite camadas, registra outcomes, compila
politica por flow, executa replay contrafactual e aplica politica adaptativa em
chamadas futuras. Ele ainda nao faz benchmark externo nem invoca provider.

Evidencia implementada:

- `AtlasRuntimeEfficiencyGovernorService`;
- `AtlasRuntimeEfficiencyGovernorCertificationService`;
- schemas `atlas.runtime_efficiency_governor.v1`,
  `atlas.context_minimum_pack.v1`, `atlas.layer_admission_decision.v1`,
  `atlas.runtime_efficiency_policy.v1` e
  `atlas.runtime_efficiency_counterfactual_replay.v1`;
- tabelas `atlas_runtime_efficiency_decisions` e
  `atlas_runtime_efficiency_outcomes`;
- tabelas `atlas_runtime_efficiency_policies` e
  `atlas_runtime_efficiency_replays`;
- envelope `runtime_efficiency` no Hyperflow antes das camadas pesadas;
- Control Plane agregando budget, path, flow e blockers;
- comandos `atlas:runtime-efficiency` e
  `atlas:runtime-efficiency:certify`;
- actions `compile-policy` e `replay` no comando AREG;
- testes de fast path, standard/deep path, forge path, blocked path,
  undercontext, overcontext, outcome learning, policy learning,
  replay contrafactual e sanitizacao;
- AAEQ `recordOutcomeFeedback`, que fecha feedback pos-execucao em AREG e
  AEMOR com `persist=false` por padrao e `persist=true` explicito para gravar
  outcome local com evidencia.

Limite honesto: o enforcement e progressivo e conservador. AREG aplica
politicas aprendidas somente quando a amostra minima e os gates de risco
permitem. Ele nao desliga camadas criticas em tarefa de alto risco e nao usa
aprendizado como desculpa para burlar evidencia, policy ou revisao humana.

## Riscos

| Risco | Mitigacao |
| --- | --- |
| Burocracia lenta | Fast path obrigatorio |
| Contexto insuficiente | Undercontext Detector |
| Contexto excessivo | Overcontext Detector |
| Camadas demais | Layer Admission Controller |
| Tools demais | Tool Budget Controller |
| Subagentes ineficientes | Subagent ROI Controller |
| Provider errado | Provider Cognitive Fit Model |
| Aprendizado falso | AEMOR Judgment Guard |
| Otimizacao prematura | Rollout por niveis L1-L5 |

## Exemplos

Pergunta simples:

```text
Usuario: oi Atlas
AREG: fast path, sem APCR profundo, sem AEMOR, sem ASRE, sem subagente.
```

Bug pequeno:

```text
Usuario: corrija esse erro no controller
AREG: Atlas Dev, contexto de arquivos/testes relevantes, tools rg/read/edit/test.
```

Decisao estrategica:

```text
Usuario: qual foco maximiza o Atlas esta semana?
AREG: ASRE admitido, APCR e AEMOR usados, ASEIF opcional se houver gap.
```

Obra longa:

```text
Usuario: implemente este runtime enterprise completo
AREG: Forge, TEOS/APCR/AEMOR obrigatorios, context budget maior,
milestones, receipts e verification budget alto.
```

## Proximas Acoes

1. Alimentar `recordOutcomeFeedback` com mais sinais reais de AEMOR em todos os flows.
2. Calibrar `context_budget_multiplier` com dados reais de custo e qualidade.
3. Expor AREG no Control Plane visual sem permitir override silencioso.
4. Usar AREG para governar admissao de novos MCPs, skills e subagentes.
5. Rodar auditoria periodica de falsa aprendizagem antes de enforcement amplo.
