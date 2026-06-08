---
id: operator-intelligence-automation-context-and-safety
type: engineering_knowledge
title: Operator Intelligence Automation Context And Safety
status: active
category: learning-governance
priority: 98
summary: Contrato para automacao progressiva, context injection, privacy e safety do Operator Intelligence Layer.
tags:
  - atlas-ai
  - operator-intelligence
  - automation
  - context
  - privacy
capabilities:
  - operator_learning_automation
  - operator_context_composition
  - operator_profile_privacy
decisions:
  - Automacao deve comecar em shadow/observe e subir apenas com evidencia.
  - Context injection deve ser curto, relevante e provider-safe.
  - Itens sensiveis exigem review ou approval gate antes de aplicacao.
  - Auto-apply reversivel so pode ocorrer quando `auto_apply_enabled=true`, `shadow_mode=false`, privacidade normal, risco baixo e confidence suficiente.
maintenance:
  - Atualize este doc antes de mudar regras de auto-apply ou context injection.
  - Rode docs-health, sync e index-code depois de alterar este contrato.
related_paths:
  - docs/engineering-knowledge-base/atlas-operator-intelligence-layer.md
  - docs/engineering-knowledge-base/operator-intelligence/storage-and-data-model.md
  - docs/engineering-knowledge-base/atlas-ai-operator-review-approval-gates.md
  - app/Services/Ai/OperatorApproval/OperatorApprovalGateService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: operator-intelligence-automation-context-and-safety
graph_title: Operator Intelligence Automation Context And Safety
graph_world: atlas
graph_layer: module
graph_kind: policy
graph_parent: atlas-operator-intelligence-layer
graph_status: active
graph_source: repo
owner: learning-governance
repo_paths:
  - docs/engineering-knowledge-base/operator-intelligence/automation-context-and-safety.md
allowed_changes:
  - Ajustar automacao quando feedback e tests mostrarem seguranca maior.
forbidden_changes:
  - Auto-aplicar itens sensiveis sem approval gate.
  - Injetar raw private context em provider externo.
depends_on:
  - atlas-operator-intelligence-layer
  - atlas-ai-operator-review-approval-gates
flows_to:
  - atlas-context-injection
  - atlas-autonomous-learning
unlocks:
  - operator-learning-auto-apply
governs:
  - operator-learning-automation
  - operator-context-safety
evidence:
  - docs/engineering-knowledge-base/operator-intelligence/automation-context-and-safety.md
  - app/Services/Ai/OperatorIntelligence/OperatorLearningCandidateService.php
  - app/Services/Ai/OperatorIntelligence/OperatorLearningSignalDetector.php
  - app/Services/Ai/OperatorIntelligence/OperatorLearningRuntimeCaptureService.php
  - app/Services/Ai/OperatorIntelligence/OperatorContextComposer.php
  - app/Services/Ai/OperatorIntelligence/OperatorProfilePolicyCompiler.php
  - app/Services/Ai/OperatorIntelligence/OperatorProfileFeedbackService.php
  - app/Services/Ai/OperatorIntelligence/OperatorProfileDigestService.php
  - app/Services/Ai/OperatorIntelligence/OperatorProfileProjectionService.php
  - app/Services/Ai/AtlasOpenBrainContextInjectionService.php
  - app/Services/Ai/AiGatewayService.php
  - tests/Feature/Ai/OperatorIntelligence/OperatorLearningReviewCommandTest.php
  - tests/Feature/Ai/OperatorIntelligence/OperatorLearningGatewayCaptureTest.php
implementation_state: implemented_initial_runtime
required_tests:
  - "git diff --check"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - automation
  - context
  - safety
ai_entrypoints:
  - Leia este doc antes de ligar auto-apply ou inserir operator profile em contexto.
ai_usage_notes:
  - Este doc define a politica do runtime inicial; auto-apply existe, mas fica default-off e shadow-on.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Auto-apply de preferencia errada vira comportamento irritante.
  - Provider recebe informacao privada sem redacao.
observability_signals:
  - Context Composer reporta itens injetados, omitidos e motivo.
next_actions:
  - Medir capturas automaticas reais antes de ampliar auto-apply alem do nivel reversivel.
---
# Operator Intelligence Automation Context And Safety

## Resumo

Este doc define como o Operator Intelligence Layer aplica aprendizado, injeta
contexto e sobe automacao sem transformar preferencias em risco.

## Papel no Atlas

Ele e a ponte entre profile registry e comportamento real. Sem este contrato, o
Atlas pode aprender, mas aplicar errado. Com ele, o Atlas aprende em sombra,
sugere, aplica reversivel e so depois ganha autonomia gated.

## Onde Se Encaixa

Filho de `atlas-operator-intelligence-layer.md`. Depende de storage/profile e
approval gates.

## Contratos

- Automacao comeca em `observe`.
- Auto-apply exige reversibilidade e escopo claro.
- Context injection usa summaries provider-safe.
- Privacy class decide onde o item pode aparecer.
- Approval gate vence qualquer regra de conveniencia.

## Fluxo

1. Profile item aprovado recebe `automation_level`.
2. Policy compiler gera rule.
3. Context composer decide se regra entra no contexto.
4. Runtime aplica regra permitida.
5. Feedback event registra outcome.
6. Digest mostra aprendizado aplicado.

## Regras para IA

1. Nunca injete tudo.
2. Nunca mande `secret` a provider externo.
3. Nunca auto-aplique acao destrutiva.
4. Nunca trate uma correcao isolada como regra global.
5. Sempre registre quando profile item foi usado.

## Escopo de Implementacao

Servicos principais:

- `OperatorProfilePolicyCompiler`
- `OperatorContextComposer`
- `OperatorProfileFeedbackService`
- `OperatorProfileDigestService`
- `OperatorProfileProjectionService`

## Dependencias

- `OperatorApprovalGateService`
- `storage-and-data-model.md`
- `atlas-ai-operator-review-approval-gates.md`
- `open-brain-context-injection.md`

## Evidencias

Context preview, privacy gating, feedback events, digest e projection existem no
runtime inicial. Auto-apply reversivel tambem existe: `OperatorSignalCaptureService`
cria o candidato e `OperatorLearningCandidateService` promove automaticamente
apenas quando todos os gates passam, `auto_apply_enabled=true` e
`shadow_mode=false`. Auto-apply global permanece desligado por seguranca ate
haver evidence de uso real.

Captura automatica de conversa tambem existe no runtime inicial:
`AiGatewayService` chama `OperatorLearningRuntimeCaptureService` depois de criar
o trace. O detector so aceita sinais explicitos do operador, como preferencias,
limites, instrucoes recorrentes e pedidos de memoria. Fontes internas/sistema
ficam fora da lista permitida por default.

Open Brain injection tambem existe no runtime inicial:
`AtlasOpenBrainContextInjectionService` compoe Operator Intelligence com
`provider_external=true`, injeta apenas itens provider-safe e registra refs
`operator_profile_item` no hash/auditoria do contexto.

## Riscos

- Aplicar regra certa no escopo errado.
- Provider externo receber dado privado.
- Auto-apply esconder comportamento do operador.
- Digest ausente deixar aprendizado invisivel.

## Exemplos

Uma preferencia "responder curto para status" pode ser `auto_apply_reversible`
em tarefas de status, mas nao em arquitetura profunda.

## Proximas Acoes

- Expandir automacao gated por item com receipts.
- Medir utilidade por feedback events antes de subir nivel.

## Automacao Progressiva

### Nivel 0: observe

Captura sinais e cria candidatos. Nao muda comportamento.

### Nivel 1: suggest

Sugere aplicar e pede confirmacao. Bom para preferencias novas ou conflitantes.

### Nivel 2: auto_apply_reversible

Aplica quando o efeito e leve, reversivel e com escopo claro: formato de
resposta, idioma, nivel de detalhe, ordenacao de contexto e defaults internos.
No runtime inicial, este nivel e o unico auto-apply implementado. Ele exige:
privacidade `normal`, risco `low`, confidence acima do limiar configurado,
`requires_confirmation=false`, candidato em status `candidate`,
`auto_apply_enabled=true` e `shadow_mode=false`.

### Nivel 3: auto_apply_after_report

Aplica e reporta depois no digest ou receipt. Exige padrao confirmado e baixo
risco.

### Nivel 4: autonomous_gated

Aplica sem pergunta apenas com regra madura, evidence forte, escopo limitado,
rollback e approval policy permitindo.

## Proibido Automatizar Sem Approval

- delecao ou overwrite destrutivo;
- dinheiro, assinatura, compra ou venda;
- publicacao externa;
- acesso a segredo ou credencial;
- mudanca de autonomia global;
- identidade, saude, familia, relacao ou dado altamente pessoal;
- envio de dado privado a provider externo;
- conclusao falsa de tarefa;
- alteracao de policy de safety.

## Context Injection

`OperatorContextComposer` recebe task, flow, domain, workspace, provider, risk
level, allowed privacy, context budget e taxonomy targets.

No Open Brain, esse composer roda com `provider_external=true`. Em preview, ele
nao registra uso; em injecao real, registra `context_injected` como feedback
do profile item.

Saida:

- lista curta de profile items;
- motivo de inclusao;
- redaction status;
- rules aplicaveis;
- itens omitidos por privacidade ou irrelevancia.

Regras:

- preferir itens especificos ao flow atual;
- reduzir itens antigos sem feedback positivo;
- bloquear `secret` para provider externo;
- resumir, nao colar raw text;
- registrar feedback quando usado.

## Privacy Classes

- `normal`: pode entrar em contexto interno e externo se util.
- `private`: entra apenas se provider/policy permitir e houver redacao.
- `sensitive`: exige review e normalmente nao sai do ambiente local.
- `secret`: nao entra em provider externo.

## Relacao Com Hermes

Hermes pode ser referencia comparativa de capacidade, mas nao fonte de verdade.
Qualquer comportamento absorvido entra como candidato, passa por placement,
vira doc/AP e so depois runtime Atlas.
