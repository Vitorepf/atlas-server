---
id: atlas-dev-policy
type: engineering_knowledge
title: Atlas Dev Policy
status: active
category: programming
priority: 100
summary: Regras invariaveis (policy) do Atlas Dev que nenhuma IA pode quebrar ao implementar, corrigir ou evoluir o fluxo. Define posicionamento de produto, fronteiras de equipe, governance proporcional, provider lock, surface-agnostic, plan-first, scope guard, verification honesta, escalation para Forge, Decision Receipt v2, persistencia atomic, handoff para Curator, mudanca de patamar e proibicoes anti-benchmark. Quando policy entra em conflito com pedido de operador ou outro doc, policy ganha (exceto override explicito do operador via Decision Receipt v2).
tags:
  - atlas-dev
  - policy
  - invariants
  - governance
  - forbidden-changes
capabilities:
  - atlas_dev_policy_invariants
  - team_boundary_enforcement
  - wrapper_governance_rules
  - anti_benchmark_enforcement
decisions:
  - Policy ganha em conflito com pedido implicito do operador. Operador pode fazer override SOMENTE via Decision Receipt v2 explicito e auditado.
  - Toda regra aqui e invariante. Mudanca exige bump major de patamar Atlas Dev + AP novo + revisao humana.
  - IA que mexer em Atlas Dev sem ler este policy faz merda. Sem excecao.
maintenance:
  - Atualizar quando mudanca de patamar Atlas Dev acontecer (raro).
  - Adicionar invariant nova quando confusao operacional repetida for detectada e exigir nova fronteira.
  - Manter abaixo de 260 linhas.
  - Rodar `php artisan atlas:engineering:knowledge docs-health --json` apos alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-index.md
  - docs/engineering-knowledge-base/atlas-dev-glossary.md
  - docs/engineering-knowledge-base/atlas-dev-patamares.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-router-flow-routing-contract-v1.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-policy
graph_title: Atlas Dev Policy
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-dev-index
graph_status: active
graph_source: repo
human_name: Atlas Dev Policy
canonical_name: Atlas Dev Policy
technical_name: atlas-dev-policy
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/atlas-dev-policy.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-policy.md
allowed_changes:
  - Adicionar invariant nova quando fronteira nova emerge (raro).
  - Refinar redacao de invariant existente sem mudar significado.
forbidden_changes:
  - Remover ou relaxar qualquer invariant sem AP novo e bump de patamar.
  - Adicionar invariant que contradiga Kernel hard invariants ou Documentation OS.
  - Inserir benchmark, Rivals, Opus challenge, medicao competitiva ou score em qualquer forma.
  - Tratar Atlas Dev como produto-substituto, concorrente ou alternativa a Claude Code/Cursor/Codex.
depends_on:
  - atlas-dev-index
  - atlas-dev-glossary
  - atlas-ai-kernel-architecture
  - atlas-ai-documentation-operating-system
flows_to:
  - atlas-dev-efficient-programming-flow-v1
  - atlas-dev-patamares
unlocks:
  - atlas_dev_invariant_enforcement
  - ai_safe_atlas_dev_implementation
governs:
  - atlas_dev.invariants
  - atlas_dev.forbidden_patterns
evidence:
  - docs/engineering-knowledge-base/atlas-dev-policy.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - policy
  - invariants
  - atlas-dev
ai_entrypoints:
  - Leia este policy DEPOIS do glossary e ANTES de qualquer mudanca em codigo, schema, runtime ou doc do Atlas Dev.
ai_usage_notes:
  - Quando uma instrucao do operador parece contradizer um invariant aqui, PAUSAR e perguntar antes de prosseguir. Override exige Decision Receipt v2.
  - Toda decisao operacional sobre Atlas Dev deve ser revisavel via lista de invariants abaixo.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - IA pula este policy e implementa algo que quebra invariant. Resultado: rollback obrigatorio.
  - IA confunde override pontual (com receipt) com mudanca permanente (precisa AP + bump de patamar).
observability_signals:
  - docs-health status ok
  - invariant violations no codigo (deve ser zero)
next_actions:
  - Manter policy alinhado quando patamar mudar.
line_limit: 260
---
# Atlas Dev Policy

Regras invariaveis. Conflitam com outras instrucoes? Policy ganha (exceto Decision Receipt v2 explicito).

## P1 — Posicionamento De Produto (Invariante)

1. **Atlas AI e o produto**. Atlas Dev e fluxo dentro do Atlas AI.
2. Atlas Dev NUNCA e "substituto", "concorrente" ou "alternativa" a Claude Code, Cursor, Codex CLI ou outro engine externo. Esse papel pertence ao Atlas AI inteiro.
3. Atlas Dev cobre APENAS desenvolvimento em workspace. Pesquisa conceitual, explicacao sem patch, debug standalone, review de PR fora de workspace, conversa exploratoria e Obra-driven pertencem a OUTROS fluxos.
4. Quando pedido cair fora do escopo Atlas Dev, retornar `routing_decision=delegate_to_other_flow` com `suggested_flow`. NAO tentar atender fora de escopo.

## P2 — Fronteira De Equipe (Invariante)

1. **Equipe Criacao** (dona deste conjunto canonico) constroi Atlas Dev: pipeline, contratos, schemas, gates, scope guard, verification, repair, escalada, drivers, surfaces.
2. **Equipe Medicao / Rivals** desenha benchmark, oraculos, baterias, scoring. Vive em `atlas-forge-rivals-*`, NUNCA neste conjunto.
3. Qualquer mencao a benchmark, Rivals, Opus challenge, bateria de prompts, score competitivo, arms ou claim de vitoria em doc/codigo Atlas Dev e BUG. Reportar.
4. Numero observado de bateria (custos, tempos, hits) NAO pode entrar como insumo arquitetural nesta equipe. Boundary segura mesmo contra pedido implicito do operador.

## P3 — Wrapper Governance Multiplicador (Invariante)

1. Atlas Dev usa engines (Claude Code CLI, Codex CLI, Cursor, Gemini, futuros) como motores INTERNOS via Atlas Decide. Engines ficam invisiveis ao operador.
2. Equacao operacional: `resultado_atlas_dev = melhor_engine_disponivel * governance_atlas_dev`.
3. Quando engine externo salta N vezes, Atlas Dev herda automaticamente via Atlas Decide. NAO ha refatoracao manual obrigatoria.
4. Multiplicador proprio do Atlas Dev vem de Code Intelligence + Open Brain + MiniSpec + TaskContract + ScopeGuard + Verification + Repair + Receipt + Plan-first + Memory.

## P4 — Surface-Agnostic Core (Invariante)

1. Core Atlas Dev (Schemas, Discovery, PromptProjection, Pipeline, Provider, Gate, Repair, Escalation, Persistence, Telemetry) NUNCA conhece Desktop, CLI, App ou API.
2. Conhecimento de surface vive APENAS em `app/Services/Ai/Programming/AtlasDev/Surface/<X>Adapter.php`. Cada adapter < 200 LOC.
3. Surface adapter traduz payload nativo -> `OperationEnvelope`; orchestrator retorna `PlanOnlyResult | PatchResult`; adapter formata para a surface.
4. Desktop-first e estrategia de entrega, NAO acoplamento. Marcos 1-4 entregam visivel no Desktop; Marco 5 destrava CLI/App/API com adapters thin.

## P5 — Plan-First (Invariante)

1. Endpoint `POST /ai/interactions/atlas-dev/plan` NUNCA chama provider. NUNCA aplica patch. Custo de token = 0.
2. Endpoint `POST /ai/interactions/atlas-dev/run` EXIGE `operator_confirmed=true` + `task_contract_hash` valido referenciando plan persistido + `confirmation_token` single-use emitido pelo plan.
3. Run sem confirmacao explicita rejeita 400. Token invalido/expirado/reutilizado rejeita 403. Hash invalido rejeita 422.
4. Operador SEMPRE ve plano completo (CompactSDD, MiniProgrammingSpec, LightTaskContract, ProviderPromptProjection) antes de Run.

## P6 — Scope Guard (Invariante)

1. Toda chamada provider tem `LightTaskContract.allowed_files` e `forbidden_files` declarados ANTES.
2. Diff que toca `forbidden_files` bloqueia completion (`scope_guard.status=failed`).
3. Diff que toca arquivo nao previsto mas defensavel = `needs_review`. Diff que excede `expected_max_files` = `failed`.
4. Mudancas pre-existentes do usuario no worktree SAO preservadas e marcadas no receipt.

## P7 — Verification Honesta (Invariante)

1. `unverified` NUNCA vira `passed`. Enforced em `completion_state_gate`.
2. `completion.status = passed` EXIGE: scope_guard passed + todos gates required passed + tests com ok=true OU no_test_reason explicito.
3. `honesty_flags` nao vazio bloqueia `passed`. Vira `needs_review` no maximo.
4. Tests rodam com timeout, output_hash persistido. Sem teste real, `no_test_reason` obrigatorio.

## P8 — Repair Barato (Invariante)

1. Repair usa MESMO provider/model do run original. NUNCA fallback escondido.
2. Repair limitado por R-level: R0=0, R1=0-1, R2=1, R3=1-2, R4+=0 (escala Forge).
3. Mesma `failure_signature` falhando 2x = abort + escalate. Diff growing sem progresso = abort + escalate.
4. `FailureCapsule` carrega erro real (primary_error_excerpt + exit_code + failing_test), NUNCA "tente de novo" generico.

## P9 — Forge Escalation (Invariante)

1. R4-R5 NUNCA fazem patch Dev. Geram `EscalationDecision` + Forge promotion preview.
2. Operador (humano) decide promover para Forge. Atlas Dev NUNCA cria Obra automaticamente.
3. Sinais de escalacao: file_count > 5-6, layers >=3, contexto >40k chars, thread >=24 msgs, security/auth/billing/migration keywords, falha recorrente >=2.
4. `EscalationDecision.target=forge` exige `score >= 7` OR `risk_level >= R4`.

## P10 — Decision Receipt v2 (Invariante)

1. Nenhum runtime executa sem Decision Receipt v2 = `(envelope_hash, prompt_projection_hash, task_contract_hash)` co-validados.
2. Modelo manual e override AUDITADO, nunca bypass. Registrado em `decision_mode=manual_override`.
3. Atlas Dev hoje opera em `manual_override` (provider_lock fixo). Migra para `auto_best_allowed` quando Atlas Decide ativar Programming.

## P11 — Provider Lock (Invariante)

1. `provider_lock` e FIXO por run. `fallback_allowed = false`. Sem council. Sem topology multi-provider.
2. Lock atual: `claude_cli + Sonnet`. Lock pode mudar entre runs via Atlas Decide; nunca dentro de um run.
3. `gemini_cli` PROIBIDO em write.
4. Variante futura `atlas_dev_codex` reutiliza o mesmo contrato; nao cria pipeline paralelo.

## P12 — Persistencia Atomic (Invariante)

1. Receipts vivem em `atlas-server/storage/atlas-dev/receipts/<run_id>/`. JSON pretty-print.
2. Write e atomic: tmpfile + fsync + rename. Crash mid-write nao deixa parcial.
3. Permissoes: `0640` arquivos, `0750` diretorios. `storage/atlas-dev/` em `.gitignore`.
4. Migracao para Postgres/Obra e futura; mantem schemas.

## P13 — Self-Improvement Handoff (Invariante)

1. Atlas Dev produz `FastPathTelemetry` + `FastPathErrorLedgerEntry`. NUNCA auto-aplica mudancas operacionais com base nesses dados.
2. Dados alimentam Programming Curator -> Proposal Inbox -> Human Review. Mudanca em thresholds/heuristicas passa por revisao humana.
3. Estagio 16 do Kernel Pipeline: "Learning nao altera comportamento critico sem proposal/review".

## P14 — Mudanca De Patamar (Invariante)

1. Patamar tem identidade conceitual + frase de capability. NAO usa `-v1/-v2`.
2. Mudanca de patamar cria NOVO doc com identidade propria. Doc antigo nao e deletado; vira referencia historica.
3. Sufixo `-v1` no nome de arquivo e convencao tecnica de snapshot dentro do MESMO patamar; nao indica patamar.
4. Definicao de patamares (atual + futuros) vive em `atlas-dev-patamares.md`.

## P15 — Hard Invariants Do Kernel Aplicados (Invariante)

Os 10 hard invariants do Kernel sao TODOS enforced no Atlas Dev. Nenhum pode ser contradito:

1. Surface nao decide.
2. Provider nao decide.
3. Tool nao decide.
4. Domain nao burla Policy/Profile.
5. Runtime nao executa sem Decision Receipt.
6. Repair retorna via Policy/Receipt/Decide.
7. Todos eventos relevantes viram Evidence.
8. Curator propoe mudancas criticas; nao auto-aplica.
9. Capability repetida vira Core.
10. Modelo manual e override auditado, nao bypass.

## P16 — Documentation Compliance (Invariante)

1. Todo doc Atlas Dev usa `doc_schema: atlas_canonical_module_doc.v1` com 12 secoes obrigatorias preenchidas (nao placeholder generico).
2. Frontmatter rico: id, title, status, summary, decisions, maintenance, related_paths, depends_on, flows_to, governs, evidence, required_tests, ai_entrypoints, ai_usage_notes, forbidden_changes, observability_signals.
3. Cross-refs entre docs do conjunto Atlas Dev mantidas atualizadas.
4. Doc acima do limite de tamanho entra em `split_required`. Divide em pai + filhos.

## P17 — Anti-Padroes Detectaveis Em Codigo (Invariante)

1. Zero strings `rivals`, `benchmark`, `opus_challenge`, `messy_human_local`, `cost_normalized_score`, `arm_a`, `arm_b` em `app/Services/Ai/Programming/AtlasDev/**`. Gate: `no_rivals_leakage_tests`.
2. Zero strings de surface (`Desktop`, `CLI`, `App`, `API_interaction`) em `Schemas`, `Discovery`, `PromptProjection`, `Pipeline`, `Provider`, `Gate`, `Repair`, `Escalation`, `Persistence`, `Telemetry`. Gate: `no_surface_leakage_tests`.
3. Adapter em `AtlasDev/Surface/` excedendo 200 LOC. Gate: line count em CI.
4. Prompt artesanal em vez de `ProviderPromptProjection`. `SonnetClaudeCliAdapter` aceita apenas `ProviderPromptProjection`, nunca `string`.

## Resumo

Regras invariaveis do Atlas Dev. 17 invariants cobrindo posicionamento, fronteira de equipe, wrapper governance, surface-agnostic, plan-first, scope guard, verification, repair, escalation, Decision Receipt v2, provider lock, persistencia, handoff Curator, patamar, hard invariants do Kernel, documentation compliance, anti-padroes detectaveis.

## Papel no Atlas

Garante que toda IA que mexer em Atlas Dev (entender, implementar, corrigir, evoluir) opere dentro de fronteira invariante. Quebra de invariant = rollback obrigatorio.

## Onde Se Encaixa

Filho de `atlas-dev-index`. Irmao de `atlas-dev-glossary` e `atlas-dev-patamares`. Lei aplicavel a todo doc/codigo do conjunto Atlas Dev.

## Contratos

- Invariants nao podem ser contraditas por outro doc do conjunto.
- Override pontual exige Decision Receipt v2 auditado.
- Mudanca permanente exige AP novo + bump de patamar.

## Fluxo

IA recebe instrucao -> verifica conflito com invariants aqui -> se conflita, pausa e pergunta -> override via Decision Receipt v2 OU recusa.

## Regras para IA

- Ler este policy DEPOIS do glossary e ANTES de mexer em Atlas Dev.
- Verificar cada acao contra os 17 invariants.
- Pausar e perguntar quando instrucao parece contradizer invariant.

## Escopo de Implementacao

Aplicavel a todo doc/codigo Atlas Dev. Cumprimento verificado por gates em CI (no_rivals_leakage_tests, no_surface_leakage_tests, line count adapters, docs-health).

## Dependencias

- `atlas-dev-index` (entrypoint)
- `atlas-dev-glossary` (termos)
- `atlas-ai-kernel-architecture` (10 hard invariants do Kernel)
- `atlas-ai-documentation-operating-system` (P16)

## Evidencias

- Existencia deste doc.
- Gates de CI implementados (futuro Fatia 0).
- Receipts persistidos provando cumprimento dos invariants em runs reais.

## Riscos

- IA pular este policy e quebrar invariant silenciosamente.
- Operador pedir override implicito sem Decision Receipt v2 -> Atlas Dev recusa.
- Mudanca de patamar sem AP novo -> rollback.

## Exemplos

Valido: IA recebe "implemente verification gate". Verifica P7. Implementa enforcing `unverified != passed`.

Invalido: IA recebe "deixa o provider escolher fallback". Verifica P11. Recusa. Sugere `Decision Receipt v2 com decision_mode=manual_override` se operador insistir.

## Proximas Acoes

- Implementar gates de CI (no_rivals_leakage_tests, no_surface_leakage_tests, adapter line count) na Fatia 0 do runbook.
- Adicionar invariant nova quando confusao recorrente exigir nova fronteira.
- Atualizar quando mudanca de patamar Atlas Dev acontecer.
