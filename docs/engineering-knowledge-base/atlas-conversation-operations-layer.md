---
id: atlas-conversation-operations-layer
type: engineering_knowledge
title: Atlas Conversation Operations Layer
status: active
category: intelligence-runtime
priority: 95
summary: Especificacao canonica do Atlas Conversation Operations Layer (ACOL), a camada interna que mantem conversas longas limpas, orientadas a meta, auditaveis e prontas para handoff/subagentes sem poluir o contexto principal.
tags:
  - atlas-ai
  - acol
  - conversation-operations
  - meta-agents
  - handoff
  - subagents
  - memory
  - compaction
  - goal-management
capabilities:
  - conversation_health
  - goal_guardian
  - context_janitor
  - memory_curator
  - decision_ledger_agent
  - handoff_architect
  - compression_critic
  - contradiction_watcher
  - flow_optimizer
  - context_budget_manager
  - subagent_return_auditor
decisions:
  - ACOL e infraestrutura interna, nao uma tela ou modo separado.
  - ACOL nao executa a tarefa principal; ACOL mantem a conversa e os handoffs saudaveis.
  - ACOL complementa ACIE: ACIE cuida do contexto; ACOL cuida da conversa como processo operacional.
  - Meta-agentes so podem escrever memoria/decisao/evidence via contratos auditaveis.
  - Subagentes nao decidem arquitetura global sozinhos; retornam evidencia para o supervisor.
  - ACOL nao autoriza benchmark, rivals ou provider call por si mesmo.
maintenance:
  - Atualizar quando novos meta-agentes ou contratos de conversa forem implementados.
  - Sincronizar termos novos com atlas-canonical-glossary-and-naming.md antes de criar schemas.
  - Manter alinhado com ACIE, TEOS, Mission Mode, Hyperflow, Atlas Dev e Atlas Forge.
related_paths:
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md
  - docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md
  - docs/engineering-knowledge-base/atlas-local-agent-memory-ingestion.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-conversation-operations-layer
graph_title: Atlas Conversation Operations Layer
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-conversation-operations-layer.md
allowed_changes:
  - Manter status active enquanto service, command, tests e certification gate existirem.
  - Adicionar meta-agentes quando houver contrato, teste e evidence refs.
  - Atualizar regras de handoff quando ACIE ou subagent runtime mudarem.
forbidden_changes:
  - Expor ACOL como produto separado para o usuario escolher.
  - Permitir que meta-agente altere decisao critica sem receipt.
  - Jogar historico bruto inteiro em subagentes por padrao.
  - Aceitar resultado de subagente sem evidence refs.
  - Usar ACOL para declarar superioridade externa.
depends_on:
  - atlas-context-intelligence-engine
  - atlas-temporal-engineering-operating-system
  - atlas-hyperflow-operation
  - atlas-ai-session-bootstrap
  - atlas-ai-continuity-session-state
flows_to:
  - atlas-ai-router-runtime
  - atlas-dev
  - atlas-forge
  - atlas-research
  - atlas-strategy
unlocks:
  - clean_long_conversation_runtime
  - meta_agent_conversation_maintenance
  - subagent_handoff_without_context_pollution
  - goal_guarded_autonomous_work
governs:
  - conversation_health
  - goal_alignment
  - handoff_quality
  - subagent_return_quality
  - memory_promotion_from_conversation
evidence:
  - docs/engineering-knowledge-base/atlas-conversation-operations-layer.md
  - app/Services/Ai/ContextIntelligence/AtlasContextOperationsRuntimeService.php
  - app/Services/Ai/ConversationOps/AtlasConversationOperationsService.php
  - app/Services/Ai/ConversationOps/AtlasConversationOperationsCertificationService.php
  - app/Console/Commands/AtlasConversationOpsCertifyCommand.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:conversation-ops:certify --json --strict"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Manter certificacao ACOL sincronizada com Hyperflow, Atlas Dev direto e Forge intake.
  - Evoluir UX operacional leve para mostrar saude da conversa e handoffs.
  - Evoluir Flow Optimizer quando houver scheduler de agentes no runtime principal.
---

# Atlas Conversation Operations Layer

## Resumo

Atlas Conversation Operations Layer (ACOL) e a camada que mantem uma conversa
longa utilizavel. Ela limpa ruido, protege a meta, extrai decisoes, prepara
handoffs, audita retornos de subagentes e evita que o contexto principal vire
um deposito de hipoteses antigas.

ACIE responde "qual contexto usar?". ACOL responde "como manter esta conversa
saudavel enquanto o trabalho avanca?".

```text
Atlas AI -> Router -> ACIE -> ACOL -> Dev/Forge/Specialist Flow
```

ACOL nao deve aparecer como botao. Ele e infraestrutura invisivel para manter
conversas, goals, handoffs e subagentes sob controle.

## Papel no Atlas

ACOL transforma conversa em operacao. O agente principal deixa de carregar todo
o ruido e passa a carregar apenas estado limpo:

- objetivo ativo;
- decisoes validas;
- blockers;
- riscos;
- evidence refs;
- proxima acao;
- contexto minimo necessario.

O historico bruto, tentativas descartadas, investigacoes paralelas e outputs de
subagentes ficam em artefatos estruturados. O supervisor integra apenas
handoffs limpos.

## Onde Se Encaixa

ACOL fica acima dos flows e ao lado do ACIE:

- Hyperflow decide dominio/flow.
- ACIE monta contexto confiavel.
- ACOL mantem conversa/meta/handoff limpos.
- Dev/Forge executam.
- TEOS preserva continuidade temporal.
- Evidence Ledger registra provas.
- Compounding aprende com falhas e correcoes.

Em Atlas Dev, ACOL reduz sujeira em sessoes longas. Em Atlas Forge, ACOL ajuda
a manter Obra, milestones e handoffs por semanas/meses.

## Contratos

Contratos alvo:

- `atlas.conversation_ops.health_report.v1`
- `atlas.conversation_ops.goal_guardian_report.v1`
- `atlas.conversation_ops.context_janitor_receipt.v1`
- `atlas.conversation_ops.memory_curator_receipt.v1`
- `atlas.conversation_ops.decision_ledger_receipt.v1`
- `atlas.conversation_ops.handoff_packet.v1`
- `atlas.conversation_ops.subagent_work_contract.v1`
- `atlas.conversation_ops.subagent_return_packet.v1`
- `atlas.conversation_ops.compression_critic_report.v1`
- `atlas.conversation_ops.contradiction_report.v1`
- `atlas.conversation_ops.flow_optimization_report.v1`
- `atlas.conversation_ops.certification.v1`

Campos obrigatorios:

- `schema_version`
- `conversation_id` ou `thread_id`
- `goal_id`, quando houver meta
- `scope_type`
- `scope_id`
- `input_hash`
- `output_hash`
- `evidence_refs`
- `created_at`
- `claim_policy`

Invariantes:

- Meta-agente nao executa provider por padrao.
- Meta-agente nao altera memoria duravel sem receipt.
- Handoff para subagente deve ter escopo e retorno definidos.
- Subagente nao recebe historico bruto por padrao.
- Resultado de subagente sem evidencia nao entra no estado principal.

## Fluxo

Fluxo padrao:

1. Ler estado atual da conversa.
2. Detectar objetivo ativo e desvio de meta.
3. Separar fatos, decisoes, hipoteses e ruido.
4. Atualizar Must-Keep Ledger conversacional.
5. Decidir se precisa limpar, compactar, pedir contexto, delegar ou executar.
6. Se delegar, gerar handoff packet com contexto limitado.
7. Receber subagent return packet.
8. Auditar evidencia, conflitos e lacunas.
9. Integrar apenas estado limpo.
10. Emitir health report e proxima acao.

ACOL deve operar em modo leve para conversa curta e modo completo em metas,
Obras, pesquisas profundas, long-horizon e multi-subagent.

## Regras para IA

- Nao confundir historico com verdade atual.
- Nao manter hipotese descartada como decisao.
- Nao compactar decisao sem `decision_ledger_receipt`.
- Nao mandar contexto global para subagente se um slice basta.
- Nao aceitar subagente que nao retorna files/read refs/tests/blockers.
- Nao esconder incerteza; registre `uncertainty`.
- Nao criar memoria duravel a partir de ruido.
- Nao pedir humano se recovery automatico seguro for suficiente.
- Nao automatizar decisao critica sem policy/receipt.

## Escopo de Implementacao

### 1. Goal Guardian

Verifica se a conversa continua perseguindo a meta real. Detecta drift,
respostas fracas, loop repetido, escopo aumentado sem consentimento e tarefa
concluida sem evidencia.

### 2. Context Janitor

Remove ruido operacional do contexto principal: logs antigos, tentativas
falhas ja resolvidas, hipoteses descartadas, repeticoes, comandos irrelevantes
e mensagens que so servem como raw history.

### 3. Memory Curator

Decide o que vira memoria, o que fica temporario e o que deve expirar. Usa
promotion gates, privacy policy, evidence refs e source labels.

### 4. Decision Ledger Agent

Extrai decisoes reais, origem, motivo, data, validade, supersession e evidencia.
Decisao sem evidencia entra como assumption, nao como fato.

### 5. Handoff Architect

Gera handoff packets para subagentes. Cada pacote define role, objetivo, escopo,
arquivos permitidos, acoes proibidas, contexto minimo, must-keep items, formato
de retorno, TTL e criterio de pronto.

### 6. Compression Critic

Audita compactacoes. Compara original vs compactado, mede perda, verifica
must-keep coverage e abre repair quando algo critico sumiu.

### 7. Contradiction Watcher

Detecta contradicoes entre resposta nova, decisao antiga, doc canonica, teste,
receipt ou memoria. Contradicao vira blocker ou pergunta explicita.

### 8. Flow Optimizer

Decide se a proxima acao deve ser resposta direta, Dev, Forge, Research,
subagente, critic, teste, retrieval, repair ou pedir humano.

### 9. Context Budget Manager

Controla tokens por agente. O supervisor recebe estado limpo; subagentes
recebem slices pequenos; contextos grandes viram refs, packs e manifests.

### 10. Handoff Auditor

Valida retorno de subagente: evidence refs, comandos rodados, arquivos lidos,
arquivos alterados, incertezas, blockers, diff scope e claims.

## Dependencias

Dependencias tecnicas:

- Atlas AI Router / Hyperflow.
- ACIE context packs e handoff contracts.
- TEOS continuation/freshness/replay.
- Mission Mode / goal state.
- Evidence Ledger / receipts.
- Atlas Memory / Open Brain.
- Atlas Dev e Forge runtime.
- Control Plane / telemetry.

Dependencias documentais:

- `atlas-context-intelligence-engine.md`
- `atlas-temporal-engineering-operating-system.md`
- `atlas-ai-continuity-session-state.md`
- `atlas-local-agent-memory-ingestion.md`
- `atlas-canonical-glossary-and-naming.md`

## Evidencias

ACOL so pode ser declarado ativo quando houver:

- health report deterministico;
- goal guardian report;
- context janitor receipt;
- handoff packet;
- subagent return audit;
- contradiction report;
- certification command;
- teste Hyperflow provando `payload.hyperflow_runtime.conversation_ops`;
- control-plane runtime expondo `context_operations.conversation_ops`, handoff packets e blockers;
- testes provando handoff/health em `AtlasProgrammingOrchestrator` e `ForgeIntakeService`;
- testes de conversa longa, handoff, return audit, command e claim policy.

Evidence minima por ciclo:

- `conversation_health_hash`
- `goal_guardian_hash`
- `decision_ledger_hash`
- `handoff_packet_hash`, quando houver delegacao
- `subagent_return_hash`, quando houver subagente
- `compression_critic_hash`, quando houver compactacao
- `certification_hash`

## Riscos

Riscos principais:

- Meta-agentes criarem burocracia em tarefa simples.
- Limpeza remover nuance importante.
- Memory curator promover ruido como memoria.
- Goal Guardian bloquear exploracao legitima.
- Subagentes duplicarem trabalho.
- Handoff pequeno demais gerar resposta ruim.
- Handoff grande demais sujar contexto do subagente.
- Supervisor aceitar retorno sem evidencia.
- Contradiction Watcher gerar falso positivo.

Mitigacoes:

- Modo leve por padrao em conversa curta.
- Must-keep ledger antes de limpeza.
- Semantic diff em compactacao critica.
- Handoff packet com escopo e formato de retorno.
- Subagent return auditor obrigatorio.
- Human review quando decisao critica for ambigua.

## Exemplos

Exemplo de handoff:

```json
{
  "schema_version": "atlas.conversation_ops.handoff_packet.v1",
  "role": "explorer",
  "task": "mapear como rich input chega ao backend",
  "allowed_scope": ["atlas-app/lib/richInput", "atlas-server/app/Http"],
  "forbidden_actions": ["edit_files", "run_provider", "benchmark"],
  "must_return": ["files_read", "findings", "evidence_refs", "gaps", "confidence"],
  "context_budget": "small",
  "ttl_minutes": 20
}
```

Uso recomendado:

- conversa longa com muita decisao;
- Obra Forge;
- pesquisa profunda;
- multiplos subagentes;
- troca de provider ou surface;
- compactacao antes de retomar.

Uso nao recomendado:

- pergunta simples;
- patch pequeno;
- resposta curta;
- tarefa que o agente principal resolve em um ciclo.

## Proximas Acoes

Estado implementado e certificado nesta versao:

1. **ACOL-I1: Conversation Health Read Model** — health report, goal drift,
   ruido, pendencias, blockers e proxima acao.
2. **ACOL-I2: Meta-Agent Receipts** — Context Janitor, Memory Curator,
   Decision Ledger e Compression Critic como receipts read-only.
3. **ACOL-I3: Handoff/Subagent Protocol** — handoff packet, return auditor,
   evidencia obrigatoria e integration gate.
4. **Default runtime wiring** — ACOL entra por Hyperflow, Atlas Dev direto,
   Forge intake, ACIE operations runtime e Control Plane.

Evolucoes futuras, nao bloqueantes para o fluxo padrao atual:

- UX operacional leve para painel de saude da conversa.
- Scheduler automatico de subagentes no runtime principal, consumindo os
  contratos ACOL em vez de ficar dentro do ACOL.
- Flow Optimizer mais agressivo para escolher entre retrieval, critic, repair,
  subagente ou ask-human.

Definition of Done para ACOL no fluxo padrao:

- Conversas longas nao acumulam ruido no estado principal.
- Hyperflow injeta ACOL por padrao no envelope principal.
- Decisoes validas ficam separadas de hipoteses.
- Subagentes recebem contexto limitado e contrato claro.
- Retorno de subagente sem evidencia nao e integrado.
- Compactacao critica passa por critic.
- Goal drift vira aviso ou blocker.
- ACOL opera invisivel para usuario final.
- Benchmark externo continua bloqueado ate autorizacao explicita.
