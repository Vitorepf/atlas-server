---
id: atlas-canonical-glossary-and-naming-teos-long-horizon
type: engineering_knowledge
title: Atlas Canonical Glossary - TEOS And Long-Horizon Terms
status: active
category: documentation
priority: 99
summary: Recorte canônico dos termos TEOS e Long-Horizon usados pelo Atlas: long_horizon, temporal_truth, event_sourced_timeline, continuation_pack, compaction_receipt, replay_manifest e continuity_certification.
tags:
  - atlas
  - glossary
  - naming
  - teos
  - long-horizon
capabilities:
  - canonical_glossary
  - naming_governance
  - teos_long_horizon_disambiguation
decisions:
  - Termos TEOS e Long-Horizon são recorte do glossário canônico, não glossário paralelo.
maintenance:
  - Atualizar junto com atlas-canonical-glossary-and-naming.md quando TEOS ou Long-Horizon mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-canonical-glossary-and-naming-teos-long-horizon
graph_title: Atlas Canonical Glossary - TEOS And Long-Horizon Terms
graph_world: atlas
graph_layer: module
graph_kind: contract
graph_parent: atlas-canonical-glossary-and-naming
graph_status: active
graph_source: repo
owner: documentation-operating-system
repo_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming-teos-long-horizon.md
allowed_changes:
  - Adicionar ou refinar termo TEOS/Long-Horizon com definição, uso e antiuso.
forbidden_changes:
  - Criar família paralela atlas.teos.* quando a regra manda atlas.long_horizon.*.
depends_on:
  - atlas-canonical-glossary-and-naming
flows_to:
  - atlas-cartography
unlocks:
  - ai-safe-naming-resolution
governs:
  - atlas.glossary
  - atlas.naming
evidence:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: medium
line_limit: 520
next_actions:
  - Manter termos TEOS e Long-Horizon alinhados ao glossário pai.
---
# Atlas Canonical Glossary - TEOS And Long-Horizon Terms

## Resumo

Recorte canônico dos termos TEOS e Long-Horizon usados pelo Atlas.

## Papel no Atlas

Evita que IA crie famílias paralelas, schemas duplicados ou nomes que confundam continuidade, replay, compactação e certificação temporal.

## Onde Se Encaixa

Filho do glossário canônico principal.

## Contratos

TEOS estende Long-Horizon quando possível; não cria namespace paralelo sem decisão explícita.

## Fluxo

Glossário principal → termos TEOS/Long-Horizon → implementação ou documentação correspondente.

## Regras Para IA

Não tratar TEOS como runtime pronto. Não inventar schema atlas.teos.* quando o contrato manda atlas.long_horizon.*.

## Escopo De Implementacao

Este arquivo documenta nomenclatura; não implementa runtime.

## Dependencias

Depende do glossário canônico e do contrato de nomenclatura da cartografia.

## Evidencias

A evidência é o glossário pai e docs-health verde.

## Riscos

Risco principal: naming errado gerar sistemas paralelos ou cartografia falsa.

## Exemplos

Os exemplos abaixo são o conteúdo extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar quando TEOS/Long-Horizon ganhar termo novo.

## Conteudo Extraido
## Termos TEOS / Long-Horizon

Termos canonicos da familia Long-Horizon Intelligence Layer (LHIL) e do
Atlas Temporal Engineering Operating System (TEOS). LHIL e camada inicial
parcialmente implementada; TEOS e north-star planned ampliando LHIL com
validade temporal, replay, recovery e continuity certification. As tres
familias de schema permitidas pelo TEOS-I1 (`atlas.long_horizon.continuation_pack.v2`,
`atlas.long_horizon.compaction_receipt.v1`,
`atlas.long_horizon.replay_manifest.v1`) sao a fronteira: outros conceitos
TEOS estendem componentes existentes sem nova familia. Ver
`atlas-temporal-engineering-operating-system.md`,
`atlas-long-horizon-intelligence-layer.md` e
`atlas-teos-increment-1-plan.md`.

### long_horizon

- `active` (LHIL parcial) / `planned` (TEOS amplia). Prefixo canonico para qualquer artefato que sustenta continuidade de Atlas Dev por semanas ou Atlas Forge por meses sem chat bruto, com hash determinístico, validade temporal e evidence.
- Use: como prefixo de schema (`atlas.long_horizon.*`), nome de tabela (`ai_long_horizon_*`), nome de servico/comando (`LongHorizon*`, `atlas:long-horizon:*`). Use quando o artefato cruza horizonte > 1 sessao.
- Nao use: para receipt de run unico Dev (use `atlas.programming.*`); para state intra-Obra Forge ja coberto por `atlas.forge.long_horizon_state.v1` (mantido sem renomeacao).
- Relacao com Atlas Dev: dev_session + dev_workstream sao escopos `long_horizon` validos; cobertura via `ProgrammingResumeService`.
- Relacao com Atlas Forge: forge_obra + milestone + work_packet sao escopos `long_horizon` validos; ja WIRED via `AiForgeLongHorizonState` + `ForgeLongHorizonStateService`.
- Relacao com LHIL/TEOS: LHIL e o lar canonico do termo; TEOS adiciona envelope temporal e certification. Nada novo no namespace `atlas.teos.*` cria familia paralela a `atlas.long_horizon.*` sem decisao explicita.
- ✓ "schema `atlas.long_horizon.continuation_pack.v2`". ✗ "schema `atlas.teos.continuation_pack.v1`" (TEOS-I1 estende, nao paraleliza).
- Aliases: ok={long_horizon, long-horizon}; proibido={multi_session_state, atlas.teos.long_horizon (paralelismo de familia), atlas.programming.long_horizon (Dev pode emitir mas reutiliza prefixo canonico).

### temporal_truth

- `planned` (TEOS north-star; sem schema dedicado no I1). Envelope canonico de validade temporal aplicado a decisoes, contextos, evidence, blockers e milestones: `valid_from`, `valid_until`, `observed_at`, `verified_at`, `stale_after`, `source_hash`, `confidence`, `superseded_by`, `evidence_refs`, `authority_level`.
- Use: como conjunto de campos adicionados a tabelas existentes durante TEOS-I1 M2 (`temporal_truth_field_rollout`). Use quando precisa saber se uma asserção ainda vale.
- Nao use: como nova familia de schema isolada — TEOS-I1 proibe `atlas.teos.temporal_truth_record.v1` enquanto rollout via extensao for suficiente.
- Relacao com Atlas Dev: campos aplicados a receipts Dev (verification, repair, review, debug) para detectar staleness.
- Relacao com Atlas Forge: campos aplicados a `AiForgeLongHorizonState`, `AiForgeMilestone`, `AiForgeWorkPacket`; alimentam Freshness Gate.
- Relacao com LHIL/TEOS: LHIL define `stale_after`; TEOS expande para o envelope completo de 10 campos. Authority chain: `operator > canonical_doc > evidence_runtime > code_intelligence > provider_output`.
- ✓ "adicionar coluna `valid_until` em `ai_audit_events` (TEOS-I1 M2)". ✗ "criar schema `atlas.teos.temporal_truth.v1`" (rollout-by-extension primeiro).
- Aliases: ok={temporal_truth, temporal_truth_envelope, validity_envelope}; proibido={atlas.teos.temporal_truth_record.v1 (sem schema novo no I1), truth_record (perde "temporal").

### event_sourced_timeline

- `partial` (via `AtlasLedgerEvent`/`AiAuditEvent`) / `planned` para deltas TEOS. Lista append-only de eventos canonicos que projetam estado: `decision.recorded`, `compaction.run`, `work_packet.done`, `milestone.advanced`, `gate.failed`, `repair.succeeded`, `certification.passed`, `blocker.opened/resolved`, etc.
- Use: para registrar mudancas que devem ser replayed ou auditadas. Use quando ha necessidade de reconstruir estado a partir de eventos.
- Nao use: para log operacional / debug livre (vai para `AiObservedEvent`); event-sourcing universal e anti-pattern declarado em TEOS §24.
- Relacao com Atlas Dev: cada gate Dev (verification, scope_guard, completion_state, senior_loop) emite um evento.
- Relacao com Atlas Forge: cada transicao de milestone, packet e cycle emite um evento; sem timeline, Forge perde causal graph e replay.
- Relacao com LHIL/TEOS: LHIL ja tem ledger; TEOS amplia adicionando eventos derivados de Long-Horizon State + Causal links (`caused_by`, `supersedes`, `verifies`, `repairs`). Lista de tipos e fechada em TEOS §6.
- ✓ "emitir `milestone.advanced` quando `advanceMilestone()` retorna `advanced=true`". ✗ "emitir `info.note` para cada log do worker" (vira ruido).
- Aliases: ok={event_sourced_timeline, event_timeline, ledger_timeline}; proibido={event_sourcing_universal, timeline_log (perde append-only invariant).

### continuation_pack

- `planned` (TEOS canon) / Dev `wired` (especializacao `atlas.programming.continuation_packet.v1`). Estrutura canonica para retomada inter-sessao: `decisions[]`, `open_tasks[]`, `blockers[]`, `evidence_refs[]`, `context_pack_hash`, `next_best_action`, `stale_after`, `confidence`, `pack_hash` deterministico. Schema target: `atlas.long_horizon.continuation_pack.v2`.
- Use: emitir ao final de cada run Dev / cada ciclo Forge, e como UNICA entrada valida para resume. v2 generaliza v1 Dev sem quebrar.
- Nao use: como resumo textual em chat; como wrapper para passar todo o transcript bruto a outro provider.
- Relacao com Atlas Dev: gerado por `ProgrammingResumeService::continuationPacket` (Dev v1). TEOS-I1 M5 promove para v2 mantendo compatibilidade.
- Relacao com Atlas Forge: gerado a partir de `AiForgeLongHorizonState` + `AiForgeWorkPacketExecutionCycle`; permite trocar provider mid-Obra sem chat bruto.
- Relacao com LHIL/TEOS: LHIL define o schema (M1); TEOS exige Continuity Certification antes de promover v2 como "execute". Sem pack valido, recovery mode != `execute`.
- ✓ "carregar `continuation_pack.v2` antes de continuar Obra". ✗ "continuar Obra usando ultimo transcript Claude Code" (continuidade nao depende de chat bruto).
- Aliases: ok={continuation_pack, continuation_packet (Dev v1 legado), long_horizon_continuation_pack}; proibido={resume_summary, atlas.teos.continuation_pack (TEOS-I1 manda v2 em `atlas.long_horizon.*`).

### compaction_receipt

- `planned` (TEOS-I1 M4). Receipt canonico de toda compactacao com loss accounting: `source_context_refs[]`, `retained_items[]` (cada com `must_keep:bool` + razao), `discarded_items[]`, `discarded_reason[]` ∈ {stale,low_signal,duplicate,out_of_scope,superseded}, **`must_keep_coverage=1.0`** invariante, `detected_contradictions[]`, `stale_risks[]`, `summary_hash`, `quality_score`. Schema target: `atlas.long_horizon.compaction_receipt.v1`.
- Use: emitido por `AiCompactionService` toda vez que comprime contexto/decisoes. Use para tornar perda auditavel.
- Nao use: como resumo livre — receipt sem `must_keep_coverage` ou com `< 1.0` e corrupcao silenciosa proibida.
- Relacao com Atlas Dev: compaction de contexto Dev (workspace + thread + cross-run) emite receipt; Continuity Cert exige `must_keep_coverage=1.0`.
- Relacao com Atlas Forge: compaction de Obra longa preserva decisoes, blockers e evidence (todos `must_keep`); compaction nunca descarta milestone, work packet ou certification.
- Relacao com LHIL/TEOS: LHIL define o schema (M1); TEOS adiciona enforcement de `must_keep_coverage` no Drift/Freshness Gate. Anti-pattern §24: compaction sem receipt.
- ✓ "emitir receipt apos AiCompactionService comprimir 20kB->4kB". ✗ "fazer summary livre sem receipt" (silencia perda).
- Aliases: ok={compaction_receipt, long_horizon_compaction_receipt}; proibido={atlas.teos.compaction_receipt (familia paralela proibida), summary_receipt (perde "compaction").

### replay_manifest

- `planned` (TEOS-I1 M8). Manifesto canonico que descreve como continuar uma Obra/workstream sem chat bruto: ponteiro para `continuation_pack` + `context_manifest` + intervalo da timeline (event_first_id, event_last_id) + `expected_state_hash` + `expected_evidence_set_hash` + `replay_mode_hint`. Schema target: `atlas.long_horizon.replay_manifest.v1`.
- Use: gerar antes de trocar de provider ou retomar apos pause; consumido por replay reader que valida `expected_state_hash`.
- Nao use: como dump de transcript; como wrapper para chat history de outro provider.
- Relacao com Atlas Dev: replay de workstream Dev cross-thread; permite trocar Claude por Codex sem perder estado.
- Relacao com Atlas Forge: replay de Obra mid-cycle; provider B reconstroi o ponto exato do provider A; Continuity Cert valida `expected_state_hash`.
- Relacao com LHIL/TEOS: LHIL nao definia replay; TEOS introduz o conceito como GREENFIELD (M8). Provider-independent continuity (TEOS §22) opera sobre este manifest.
- ✓ "gerar `replay_manifest.v1` apos M5 (continuation_pack v2) e antes de provider swap". ✗ "passar log inteiro do Claude para o Codex" (nao e replay manifest).
- Aliases: ok={replay_manifest, long_horizon_replay_manifest}; proibido={provider_handoff_dump, atlas.teos.replay_manifest (familia paralela proibida).

### continuity_certification

- `planned` (TEOS-I1 M10). Certificacao que valida estado antes de continuar execucao. Sem schema novo no I1: estende `ForgeObraCertificationService` + Dev verification gate adicionando checks `long_horizon_continuation_pack_v2_present`, `long_horizon_compaction_receipt_present`, `long_horizon_freshness_report_pass`, `long_horizon_replay_manifest_present`.
- Use: como gate obrigatorio antes de mode `execute` em Dev resume ou Forge advance. Saida: `passed` / `passed_with_warnings` / `failed`.
- Nao use: como replacement do `ForgeObraCertificationService` (que continua como consumidor); como certification de release (escopos diferentes).
- Relacao com Atlas Dev: gate adicionado em `ProgrammingResumeService` antes de declarar resume = `execute`.
- Relacao com Atlas Forge: gate adicionado em `ForgeObraCertificationService` e `ForgeMilestoneGateRunner` antes de `advanceMilestone()` declarar `advanced=true`.
- Relacao com LHIL/TEOS: TEOS §21 define o conceito; TEOS-I1 M10 implementa via extensao de checks existentes — NAO cria schema `atlas.teos.continuity_certification.v1` separado.
- ✓ "ForgeObraCertificationService::certify falha quando `long_horizon_freshness_report_pass=false`". ✗ "criar `atlas.teos.continuity_certification.v1` paralelo" (extensao primeiro).
- Aliases: ok={continuity_certification, long_horizon_continuity_certification}; proibido={atlas.teos.continuity_certification.v1 (sem schema novo no I1), obra_certification = continuity_certification (escopo diferente; obra cert e consumidor).
