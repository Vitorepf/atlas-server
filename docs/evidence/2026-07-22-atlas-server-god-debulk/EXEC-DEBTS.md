# EXEC-DEBTS — fila do implementador

```yaml
mission: atlas-server-god-debulk-execute
mode: implement
meta_only: false
awaiting_operator_token: EXECUTE GOD-DEBULK
preferred_engine: claude  # Sol Extra Alto blocked 2026-07-22 on AIP-*-DOCS package
queue_index: 5
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
5. **A1-SC-0001..0008** — `AtlasSelfConstructionReadinessService.php`
   - TEST status/write honesty + payload contracts
   - BUGFIX read-only vs mutate + fail-closed defaults
   - SPLIT façade thin + owners ≤2000 / hot ≤800 (sem novo `*Section` monstro)
   - OWNER / EXTRACT / CODEMAP / PERF (nessa ordem)
6. ⭐ ORDEM DO COMANDANTE — Fase 0 dos blueprints (characterization pura, test(core), SEM mudança de comportamento; blueprints em ARCH-BLUEPRINTS/):
   a. RuntimeExecution F0: characterization AVER (10 métodos array-in/out) + teste que DOCUMENTA a contradição atual (AtlasAverCertifiedExecution certified com AiRealExecutionCertification ausente p/ mesmo diff_hash) + snapshot byte do schema atlas.ai.runtime_release_gate.v1 + espiões de mutação por tool (classifica os 19 de AiToolRuntime em read-only vs mutador)
   b. ProviderPipeUnification F0: characterization AiProviderManager get/getRecommended/keys (±decoradores) + snapshot manifest/complianceReport da ProviderDriverRegistry (oráculo da fusão) + envelope do Swarm com resolver stub + runner Forge governed/não-governed→consulted/bypass no ledger
   c. KernelTriad passo 1: snapshot de array_keys(architectureScanChecks()) (166 keys) + teste de reflexão da API pública do AtlasEvidenceLedger (métodos+construtor) + replay hash-chain verde
   d. LearningConsolidation M1a: snapshot --json de atlas:ai:learning collect/list/review + bloco learning do controlPlaneSummary (os 13 testes existentes ficam como base)
7. Próximos YAMLs em `META-FINDINGS/A1--SelfConstruction.md` (LOC desc, s0 primeiro)
8. Holding → Kernel/Gates → resto A1 → A2… (COMPLETE §5)

## Regras

- Uma op por ciclo · acceptance do finding obrigatória
- Claim path aqui antes de editar (evita briga com META)
- PROIBIDO vanity `residual pass N` sem aceitação
- Fonte: `META-FINDINGS/<WAVE>--<Bucket>.md`
- origin: arch-review — ROTULAGEM: commits cujo diff inclui `app/` devem usar `refactor(core): GOD-DEBULK …` (não `test(core)`); `test(core)` só quando o diff é exclusivamente tests/. Conteúdo dos commits auditados está APROVADO (0a630e750/cea059e34: fix real + teste red→green + boundary one-way); só o rótulo desvia da lei §8.
