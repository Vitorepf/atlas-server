# EXEC-DEBTS — fila do implementador

```yaml
mission: atlas-server-god-debulk-execute
mode: implement
meta_only: false
awaiting_operator_token: EXECUTE GOD-DEBULK
preferred_engine: claude  # Sol Extra Alto blocked 2026-07-22 on AIP-*-DOCS package
queue_index: 6
wave: A1
bucket: app/Services/Ai/SelfConstruction
anti_trap: ignore_AIP_RES_selfconstruction_dispatcher
p0_tooling_status: complete
current_focus: A1-SC-0001..0008 (queued; readiness facade split and ownership)
claimed_paths: []
```

## Fila (ordem — derive dos META-FINDINGS; atualize ao executar)

0. **P0 tooling** — complete: audit + guard + CODEMAP verifier + initial incomplete CODEMAP; baseline `>5k=14`, `>2k=40`.
1. **A1-SC-0019** — `ReadinessProjectionAgentCodexSection.php`
   - complete: executable liveness-monitor preflight regression reproduced
     `Readiness\\Schema` before the facade import and passes after it.
2. **A1-SC-0076** — `AgentCodexSection` → `AgentDispatchProviderSection`
   - complete: restored the mandatory typed sibling boundary; direct facade and
     command consumers reach the read-only ProviderAdapter contract.
3. **A1-SC-0020** — post-start real-invoker release contract hash
   - complete: real release-preflight hash now participates in the public
     contract identity; the doubled payload lookup remains absent.
4. **A1-SC-0021** — complete: receipt and evidence producers no longer depend
   on the downstream acceptance-bridge ID; the bridge keeps its one local
   correlation/idempotency input and result.
5. **A1-SC-0096** — complete: durable-reservation ledger, storage and
   migration work orders now share reservations, reservation events and packet
   snapshots, plus the canonical available-to-blocked state vocabulary.
5b. **A1-SC-0106** — complete: missing, cancelled, and cyclic task dependencies
   fail closed in the real serving path; only `completed_dry_run` authorizes a
   dependent task (`c23319df3`).
5c. **A1-SC-0155** — complete: the terminal-loop digest now revalidates the
   stored completion evidence, its digest, validation hash and receipt hash
   against the persisted task/lease binding; an internally inconsistent
   completion receipt becomes `attention_required` (`a69a6f50e`).
5d. **A1-SC-0113 + A1-SC-0114** — complete: the public operator-evidence
   entrypoint resolves the extracted collaborators and canonizes the envelope
   payload before serialization/hash; nested secret-bearing fields are removed
   from the returned surface (`7d7aa7c35e`, shared-index label collision
   recorded in EXEC-LEDGER).
6. **A1-SC-0001..0008** — `AtlasSelfConstructionReadinessService.php`
   - TEST status/write honesty + payload contracts
   - BUGFIX read-only vs mutate + fail-closed defaults
   - SPLIT façade thin + owners ≤2000 / hot ≤800 (sem novo `*Section` monstro)
   - OWNER / EXTRACT / CODEMAP / PERF (nessa ordem)
7. ⭐ ORDEM DO COMANDANTE — Fase 0 dos blueprints (characterization pura, test(core), SEM mudança de comportamento; blueprints em ARCH-BLUEPRINTS/):
   a. RuntimeExecution F0 — complete, review-corrected (`af717ce0c29d54d60d2bf19d711859815d6fa2d9`): characterization executável dos 9 APIs AVER seguros + inventário estático do 10º (`executeFixtureCycle`, gap inseguro); AVER pode persistir certificação legada, mas permanece não-correlacionável com RealExecution até F4a adicionar `goal_record_id` (sem alegação de par ausente); prova de AVER payload-hash ≠ SHA-256 do diff cru vinculada ao contrato textual do produtor RealExecution, sem executá-lo; snapshot byte v1 + spy `report()==1`; catálogo 19/9/10 é inventário declarado de seed F3-pre, não classificador atual — `shell.run` é dependente do comando e foi caracterizado sem execução.
   b. ProviderPipeUnification F0 — PARTIAL/BLOCKED, review-corrected (`4f97afd0c8c20c018a365f7014795385a2da73dd`): accepted subsets are (1) manager advisory fallback to the configured fake default, verdict/consulted state, and real cache decoration without `run()`; (2) frozen full manifest/compliance report including warnings; (3) real Forge base-driver → `ProviderGovernanceConsult` → runner composition with a no-provider process fixture, where CONSULTED is causal and bypass occurs only after the seam is removed; and (4) Swarm's original emitted `execution_hash` against its production formula, followed by a copy-only `started_at` normalization and recomputed hash snapshot. Blocked only by `AtlasSwarmExecutorService` directly constructing `DateTimeImmutable('now', UTC)`: literal byte-identical live-envelope proof waits for a separately governed clock seam.
   c. KernelTriad passo 1: snapshot de array_keys(architectureScanChecks()) (166 keys) + teste de reflexão da API pública do AtlasEvidenceLedger (métodos+construtor) + replay hash-chain verde
   d. LearningConsolidation M1a: snapshot --json de atlas:ai:learning collect/list/review + bloco learning do controlPlaneSummary (os 13 testes existentes ficam como base)
8. Próximos YAMLs em `META-FINDINGS/A1--SelfConstruction.md` (LOC desc, s0 primeiro)
9. Holding → Kernel/Gates → resto A1 → A2… (COMPLETE §5)

## Regras

- Uma op por ciclo · acceptance do finding obrigatória
- Claim path aqui antes de editar (evita briga com META)
- PROIBIDO vanity `residual pass N` sem aceitação
- Fonte: `META-FINDINGS/<WAVE>--<Bucket>.md`
- origin: arch-review — ROTULAGEM: commits cujo diff inclui `app/` devem usar `refactor(core): GOD-DEBULK …` (não `test(core)`); `test(core)` só quando o diff é exclusivamente tests/. Conteúdo dos commits auditados está APROVADO (0a630e750/cea059e34: fix real + teste red→green + boundary one-way); só o rótulo desvia da lei §8.
