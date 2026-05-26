---
id: atlas-persistent-context-runtime
type: engineering_knowledge
title: Atlas Persistent Context Runtime
status: active
implementation_status: active_local_runtime_certified
implementation_boundary: APCR default context bootstrap, persistence, sufficiency gate, provider handoff, prompt projection, Hyperflow, Atlas Dev, Forge, Control Plane and memory-update guard are certified locally; external provider execution, benchmark/rivals, UI rendering and live production traffic are outside this certification.
category: intelligence-runtime
priority: 98
summary: Camada obrigatoria de contexto persistente do Atlas. Antes de Hyperflow, Atlas Dev, Atlas Forge ou qualquer provider executar, APCR recupera contexto, gera context pack, valida suficiencia, monta must-know ledger, cria handoff auditavel e registra candidatos de memoria pos-execucao com evidencia.
tags:
  - atlas-ai
  - persistent-context
  - context-pack
  - hyperflow
  - atlas-dev
  - atlas-forge
  - memory
  - evidence
capabilities:
  - persistent_context_pack
  - mandatory_context_bootstrap
  - must_know_ledger
  - provider_context_handoff
  - post_execution_memory_candidate
  - control_plane_observability
decisions:
  - APCR e infraestrutura interna, nao produto ou tela separada.
  - Nenhum provider deve iniciar sem context pack, sufficiency gate e must-know ledger.
  - APCR nunca chama provider, benchmark ou rivals.
  - Memoria duravel nao e atualizada automaticamente; APCR cria AiMemoryDelta pending com evidence refs.
  - Atlas Dev e Atlas Forge devem receber APCR como contrato padrao, nao como opcional manual.
maintenance:
  - Atualizar quando Hyperflow, Atlas Dev, Forge ou ACIE/ACOL mudarem contrato.
  - Manter comando de certificacao verde antes de declarar APCR pronto.
  - Nao criar store paralelo de memoria; usar AiMemoryDelta e AtlasMemoryEntry existentes.
related_paths:
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - docs/engineering-knowledge-base/atlas-conversation-operations-layer.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-contracts.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-persistent-context-runtime
graph_title: Atlas Persistent Context Runtime
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Persistent Context Runtime
canonical_name: Atlas Persistent Context Runtime
technical_name: atlas-persistent-context-runtime
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
owner: atlas-ai
repo_paths:
  - app/Services/Ai/PersistentContext/AtlasPersistentContextRuntimeService.php
  - app/Services/Ai/PersistentContext/AtlasPersistentContextCertificationService.php
  - app/Models/AtlasPersistentContextPack.php
  - app/Console/Commands/AtlasPersistentContextRuntimeCommand.php
  - app/Console/Commands/AtlasPersistentContextCertifyCommand.php
  - database/migrations/2026_05_20_120000_create_atlas_persistent_context_packs_table.php
allowed_changes:
  - Adicionar novos consumidores APCR em specialist flows.
  - Endurecer sufficiency gate conforme surgirem gaps reais.
  - Expandir observabilidade no Control Plane.
forbidden_changes:
  - Chamar provider, benchmark ou rivals dentro do APCR.
  - Permitir completed/handoff sem context pack e evidence refs.
  - Atualizar memoria duravel sem review/promotion pipeline.
  - Criar contexto paralelo ignorando AiContextPackBuilder, ACIE ou ACOL.
depends_on:
  - atlas-context-intelligence-engine
  - atlas-conversation-operations-layer
  - atlas-hyperflow-operation
  - atlas-dual-core-engineering-system
flows_to:
  - atlas-ai-router-runtime
  - atlas-dev
  - atlas-forge
  - atlas-research
  - atlas-finance
  - atlas-marketing
unlocks:
  - provider_independent_context_bootstrap
  - no_zero_context_sessions
  - auditavel_context_handoff
governs:
  - persistent_context_pack
  - provider_handoff_context
  - post_execution_memory_candidate
evidence:
  - app/Services/Ai/PersistentContext/AtlasPersistentContextRuntimeService.php
  - app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - app/Services/Ai/Programming/Forge/ForgeIntakeService.php
  - app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php
required_tests:
  - "php artisan test tests/Feature/Ai/PersistentContext"
  - "php artisan atlas:persistent-context:certify --json --strict"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Expor APCR no painel operacional do Atlas Desktop sem permitir edicao manual do contrato.
  - Adicionar linking direto trace_id -> persistent_context_pack_id quando o schema de trace permitir.
  - Usar outcomes APCR para melhorar ranking de retrieval e bootstrap docs.
---
# Atlas Persistent Context Runtime

## Resumo

APCR elimina o problema de cada sessao nascer zerada. Ele roda antes do provider e entrega um pacote canonico com contexto recuperado, decisoes que nao podem sumir, fontes usadas, suficiencia do contexto e regras de handoff.

O objetivo nao e colocar mais texto no prompt. O objetivo e impedir que Claude, Codex, Gemini ou qualquer executor trabalhe sem saber o estado real do Atlas.

## Boundary Atual

Em 2026-05-25, `php artisan atlas:persistent-context:certify --json --strict`
certifica APCR com 14/14 checks: runtime smoke, persistencia, Hyperflow,
Gateway, PromptBuilder, Atlas Dev, Forge, Control Plane, guard de memoria,
testes e claim policy. As proximas acoes abaixo sao manutencao e expansao de
observabilidade de runtime ativo, nao backlog paralelo nem indicacao de doc
antiga.

## Papel no Atlas

APCR fica acima dos providers e abaixo do Atlas AI surface:

1. usuario envia prompt;
2. APCR recupera contexto persistente e monta contract;
3. Hyperflow/Router decide dominio e flow;
4. Atlas Dev, Forge ou specialist flow executa consumindo esse contract;
5. APCR registra outcome como candidato de memoria com evidencia.

## Onde Se Encaixa

APCR usa:

- Session Bootstrap para docs e regras iniciais;
- AiContextPackBuilder para montar contexto operacional;
- ACIE para certificar qualidade do contexto;
- ACOL para saude de conversa, handoff e compaction;
- AtlasPersistentContextPack para persistencia auditavel;
- AiMemoryDelta para aprendizado pos-execucao com review.

## Contratos

Contratos principais:

- `atlas.persistent_context.runtime.v1`
- `atlas.persistent_context.sufficiency_gate.v1`
- `atlas.persistent_context.must_know_ledger.v1`
- `atlas.persistent_context.provider_handoff.v1`
- `atlas.persistent_context.post_execution_update.v1`
- `atlas.persistent_context.certification.v1`

Campos obrigatorios do runtime:

- `status`
- `scope`
- `prompt_hash`
- `context_pack_hash`
- `must_know_ledger_hash`
- `sufficiency`
- `retrieval_report`
- `must_know_ledger`
- `context_pack`
- `provider_handoff`
- `evidence_refs`
- `claim_policy`
- `persistent_context_hash`

## Fluxo

Fluxo padrao:

1. normalizar prompt, workspace, surface, dominio, flow e provider;
2. rodar `AtlasSessionBootstrapService`;
3. rodar `AiContextPackBuilder`;
4. extrair context refs de manifest, payload e rich input;
5. gerar must-know ledger;
6. rodar ACIE;
7. rodar ACOL operations runtime;
8. calcular sufficiency gate;
9. criar provider handoff;
10. persistir `AtlasPersistentContextPack`;
11. anexar APCR no envelope Hyperflow, gateway, plano Dev ou intake Forge;
12. projetar APCR no prompt real via `AiPromptBuilder`;
13. pos-execucao: criar `AiMemoryDelta` pending somente com evidence refs.

## Regras para IA

Uma IA implementando ou corrigindo APCR deve obedecer:

- Nunca chamar provider dentro de APCR.
- Nunca declarar benchmark ou superioridade externa.
- Nunca promover memoria diretamente.
- Nunca ignorar sufficiency blocked.
- Nunca remover must-know ledger do handoff.
- Sempre preservar hashes e evidence refs.
- Sempre diferenciar contexto recuperado de inferencia.
- Sempre manter APCR como infraestrutura interna.

## Escopo de Implementacao

Dentro do escopo:

- Hyperflow default entry.
- AiGateway default entry.
- Atlas Dev/Programming plan.
- Atlas Forge intake.
- Provider prompt projection.
- Control Plane read model.
- CLI build/outcome.
- CLI certify.
- Persistencia de context packs.
- Candidato de memoria pos-execucao.

Fora do escopo:

- UI pesada.
- Provider execution.
- Benchmark/rivals.
- Auto-promocao de memoria.
- Migracao de historico legado.

## Dependencias

Dependencias obrigatorias:

- ACIE ativo.
- ACOL ativo.
- Session Bootstrap existente.
- AiContextPackBuilder existente.
- Evidence refs nos fluxos que completam tarefas.
- AiMemoryDelta para aprendizagem governada.

## Evidencias

Evidencias de pronto:

- `AtlasPersistentContextRuntimeService` cria runtime com hash.
- `AtlasHyperflowEntryService` chama APCR antes de mission/intent.
- `AiGatewayService` cria APCR antes do `AiPromptBuilder` para cobrir CLI/direct gateway.
- `AiPromptBuilder` injeta provider handoff e must-know ledger no prompt.
- `AtlasProgrammingOrchestrator` inclui `persistent_context` no plano e dispatch.
- `ForgeIntakeService` persiste `persistent_context`.
- `AtlasAiControlPlaneService` agrega APCR.
- `atlas:persistent-context:certify --json --strict` passa.

## Riscos

Riscos principais:

- APCR virar resumo grande e caro em vez de contrato compacto.
- Sufficiency gate bloquear demais por falta de docs.
- Provider ignorar handoff.
- Memoria virar lixo se evidence refs forem fracas.
- Contexto antigo ser reutilizado sem freshness suficiente.

Mitigacoes:

- Hashes deterministas.
- Must-know ledger.
- Evidence refs obrigatorios para outcome.
- Pending memory delta com review.
- Control Plane com blockers.

## Exemplos

Prompt pequeno:

- Usuario: "corrija esse bug"
- APCR recupera workspace, docs canonicos, ultimas decisoes e regras de evidencia.
- Provider recebe o prompt ja com handoff: nao inventar fontes, preservar must-know e devolver evidence refs.

Obra Forge:

- Usuario pede uma Obra longa.
- Forge intake recebe APCR, ACIE/ACOL e contexto de rich input.
- Work packets e milestones nascem com contexto auditavel.

## Proximas Acoes

Proximas acoes canonicas:

1. Manter APCR obrigatorio no caminho default.
2. Criar linking direto entre trace e APCR quando o schema permitir.
3. Expor leitura APCR no Desktop/Mobile sem permitir edicao manual.
4. Usar outcomes para melhorar ranking de retrieval.
5. Rodar certificacao APCR sempre que Hyperflow, Dev ou Forge mudarem.
