# Atlas Engineering OS — Análise Profunda, Hierarquia Completa e Nota Final

**Data:** 2026-07-05
**Método:** kernel lido em primeira mão (todos os arquivos de `app/Services/Ai/EngineeringKernel/`), executores mapeados por varredura de código real (não docs), defaults verificados em `config/atlas.php` e `config/atlas_dev.php`, evidência comparativa colhida de `storage/atlas/rivals/`. Claims sem evidência são marcados como tal — este relatório segue a claim policy do próprio AAEOS.

---

## 1. Hierarquia completa do Atlas Engineering OS

```
Atlas AI / Autonomous Intelligence OS
 └─ AAEOS — Atlas Agentic Engineering OS  (camada-mãe; doc canon: atlas-agentic-engineering-os.md)
     │
     ├─ Programming Governance System (a LEI: placement, spec, task contract, evidence)
     │   │
     │   ├─ ★ ENGINEERING KERNEL  (app/Services/Ai/EngineeringKernel/ — 46 arquivos)
     │   │    O coração: AcceptanceGate soberano + SovereignHonestyFloor + Spec-Adversary
     │   │    + probes não-funcionais + adapters por executor. Único juiz dos três.
     │   │
     │   ├─ ATLAS DEV      (app/Services/Ai/Programming/AtlasDev/ — 26 subdirs)
     │   │    Fast-path R1–R3: 1–5 arquivos, single-provider, gates E1–E6
     │   │
     │   ├─ ATLAS FORGE    (app/Services/Ai/Programming/Forge/ — 33 arquivos + invocation layer)
     │   │    Fábrica pesada R3–R5: obras, work packets, multiagente, 11 provider drivers
     │   │
     │   └─ ATLAS CODE     (cockpit humano — surface, nunca runtime-mãe)
     │
     ├─ AUTONOMOUS ENGINEERING OS (app/Services/Ai/AutonomousEngineering/ + AutonomousEvolution/ [69 dirs]
     │   + SelfConstruction/ [~1.112 arquivos])
     │    Loop de goals 24/7: origina, executa, repara, certifica, aprende — sem humano
     │
     ├─ Autonomous Software Company Runtime — 11 departamentos (Product, Architect, Research,
     │    Dev, Debug, Review, QA, Security, Forge, Delivery, Memory) sob schema atlas.aaeos.department.v1
     │
     ├─ RealExecution (app/Services/Ai/RealExecution/ — 8 arquivos)
     │    Espinha de entrega física: worktree → patch → test → repair → delivery pack → certify
     │
     ├─ Engineering Services (app/Services/Engineering/ — 137 arquivos, ~69.5k linhas)
     │    Quality/security scan (gitleaks, semgrep, osv, trivy, grype), CodeGraph detectors,
     │    documentation-reality gates, release gates
     │
     └─ ACOS / Compounding — learning_capsule → memória → recall → auto-routing aprendido
```

**Contratos estruturais (docs canônicos, maturidade L4):** 17 fases canônicas (intent_capture → … → certification → learning_capture), 15 gates universais por entrega, autonomy ladder L0–L7, claim policy ("narrativa de agente não é evidência; doc_maturity ≠ implementation_state").

**Fluxo canônico inviolável (convergido 03/07):**
`PRESENCE → ORIENT → ORIGINATE → ELICIT → SPEC-ADVERSARY → FREEZE → COMMIT → EXECUTE → CERTIFY → POST-MERGE RE-CERTIFY → DELIVER → OBSERVE → COMPOUND`

---

## 2. O KERNEL — EngineeringKernel (a parte mais importante)

### 2.1 Arquitetura em uma frase

Um **único juiz soberano** (`AcceptanceGate`) com **uma única implementação** (`SovereignHonestyFloor`), consumido pelos três executores via **adapters strangler** (`AtlasDevGateAdapter`, `AtlasForgeGateAdapter`, `AtlasAutonomosGateAdapter`): a superfície coleta a evidência, o kernel decide, o `MergeActuator` age. `bar(dev) = bar(forge) = bar(autonomos)` — o `TrustLevel` troca **apenas a testemunha**, nunca a régua.

### 2.2 Os blocos do kernel (46 arquivos)

| Bloco | Arquivos-chave | Responsabilidade |
|---|---|---|
| **Contrato** | `AcceptanceGate.php` (interface, "a 6ª interface do kernel") | `certify(AcceptanceBundle, TrustLevel): CertVerdict` — fail-closed |
| **Juiz** | `SovereignHonestyFloor.php` (412 linhas) | A ÚNICA implementação; 11 invariantes always-on |
| **Evidência** | `AcceptanceBundle.php`, `ExecutionEvidence.php` | criteria_hash/frozen_hash, changed_files, execution (commands/tests_run/assertions), mutation_report, security_scan, judges, context_sufficiency, non_functional, criteria cruas |
| **Veredito** | `CertVerdict.php` | PROMOTE / HOLD / REFUSE / REVIEW + blockers + trilha por invariante |
| **Testemunha** | `TrustLevel.php` | Dev=`human_witnesses_disambiguation_only`; Forge=`planning_seal_plus_reviewer_agent`; Autonomos=`frozen_judge_clean_checkout_reproof` |
| **Auditoria** | `SovereignReceipt.php`, `ReceiptLedger.php`, `JsonlReceiptStore` | Recibo selado content-addressed com FLOOR_VERSION + hashes |
| **Adapters** | `Adapters/Atlas{Dev,Forge,Autonomos}GateAdapter.php` | Traduzem evidência real de cada superfície para o bundle |
| **Não-funcional (Obra #3)** | `NonFunctional/MigrationSafetyProbe.php`, `ArchitectureRegressionProbe.php` | Probes determinísticos COMPUTADOS do diff (não auto-reportados) |
| **Spec-Adversary (Obra #2)** | `Spec/` (23 arquivos): `SovereignSpecFloor`, `SpecAdversary`, `SpecOracle`, `WitnessResolver`, `SpecReceipt`… | Fidelidade-DA-spec: piso determinístico é a única autoridade de FREEZE; modelo é advisor CONTEST-only |
| **Atuação** | `MergeActuator.php`, `WorkcellExecutor.php`, `ProviderPort.php`, `BudgetMeter.php` | Portas para agir no veredito sem o kernel decidir duas vezes |

### 2.3 Os 11 invariantes do SovereignHonestyFloor (verificados no código)

Computados **sem ler o trust_level** — a régua é idêntica para os três:

1. **`false_claim_blocked`** (o coração anti-fake-green): pass alegado com `tests_run=0` ou `assertions=0` = mentira, recusa. Detecta a assinatura do smoke fixo legado (`atlas_real_execution_smoke`). `php -l` apresentado como suíte = `lint_only_run_presented_as_suite`, recusa. Suíte alegada sem test runner de verdade (phpunit/artisan test/paratest/pest) = recusa.
2. **`context_sufficiency`** ≥ 80 (piso soberano; config só aperta).
3. **`mutation_kill_ratio`** ≥ **0.6 não-sobrescrevível** — `max(SOVEREIGN_MUTATION_FLOOR=0.6, config)`; o config `atlas.loop.mutation_kill_ratio_floor` ainda é 0.0, mas o piso vence (a lição do "floor banguela" virou invariante). Waive só se `decision_surface_added=false`; mutation alegada com 0 mutantes = recusa.
4. **`changed_public_symbol_census`**: todo símbolo público alterado precisa de critério E teste.
5. **`security_free`**: scan que não rodou = recusa (fail-closed); secret detectado, SAST crítico ou CVE crítico = recusa.
6. **`criteria_hash_frozen`**: hash da suíte certificada = hash congelado; quando as criteria cruas vêm no bundle, o hash é **recomputado** (`CriteriaCanonicalizer::hash`) — proof-of-binding que fecha o buraco que a Obra #2 achou (dois strings iguais passarem). *Resta um caminho legado hash-equality-only quando as criteria cruas não são carregadas.*
7. **`judge_diversity`**: ≥ 2 famílias de provider distintas aprovando.
8. **`performance_budget`** (Obra #3): declarou budget → tem que medir dentro dele; declarado-sem-medir = recusa; não-declarado = waive.
9. **`migration_safety`** (Obra #3): qualquer arquivo em `database/migrations/` tocado SEM probe = recusa (o guarda direto contra o wiper); probe unsafe = recusa; sem migration = waive.
10. **`architecture_no_regression`** (Obra #3): edge de import cruzando camada proibida = recusa. *Advisory-strong: waiva quando nenhum scan de edges rodou (comentário `ponytail:` no código admite o teto e o upgrade path).*
11. **`property_clean_for_tagged`** (Obra #3, soberania): entrega taggeada sensitive/secret/cyber sem checagem de propriedade = recusa.

Cada `certify()` sela um `SovereignReceipt` content-addressed (witness-set + FLOOR_VERSION + hashes) — auditável no ledger.

### 2.4 Como o kernel é dividido pelos três executores (o ponto crítico)

| | **Atlas Dev** | **Atlas Forge** | **Autonomous** |
|---|---|---|---|
| Adapter | `AtlasDevGateAdapter` | `AtlasForgeGateAdapter` | `AtlasAutonomosGateAdapter` |
| Caller em produção | `AtlasRealEngineeringExecutionKernelService::certify()` ([RealExecution](../app/Services/Ai/RealExecution/AtlasRealEngineeringExecutionKernelService.php):678) | `ForgeWorkPacketExecutionCycleService::complete()` (linhas 399–425) | `AtlasAutonomousEngineeringService::sovereignEngineeringVerdict()` (linha ~565) |
| Trust/witness | Dev — humano testemunha desambiguação | Forge — planning seal + reviewer agent | Autonomos — FrozenJudge re-prova em checkout limpo |
| **Evidência que chega ao floor** | **REAL**: `MutationScoreVerdict` (MSI do infection) + `EngineeringQualityScanService::scan()` mapeado por ferramenta (gitleaks→secret, osv/grype→CVE, semgrep/trivy→SAST); scan bloqueado = fail-closed | **DEFAULTS**: mutation `decision_surface_added=false` (waive automático) e security `ran=true/secret_free=true` quando a pipeline não fornece; `tests_run=0` hardcoded no ciclo | **DEFAULTS**: mesmos defaults do Forge; `safe_simulation` produz `tests_run=0` |
| Modo do gate | **Enforcing** no caminho `certify()` (o stub fake-green é REFUSADO — mudança viva da Obra #1) | **OBSERVE-mode por default** (`atlas.engineering_kernel.forge_execution_gate_enforcing=false`): registra veredito, nunca bloqueia; ENFORCE é opt-in | Consulta o gate na certificação do goal, MAS **usa o AtlasDevGateAdapter**, não o Autonomos; o adapter próprio está **unwired em produção** |

**A verdade estrutural:** o piso é único e idêntico — mas **a qualidade da certificação é a qualidade da evidência que cada superfície entrega**. Hoje, só o Dev alimenta o floor com evidência real fim-a-fim. Forge e Autonomous têm o contrato pronto e o floor ligado em observe/consulta, com defaults que waivam mutation e security até o executor injetar dados reais. Isso é intencional (rollout strangler), mas é O gap que separa a nota atual da nota de projeto.

---

## 3. Os três executores em detalhe

### 3.1 ATLAS DEV — fast-path governado (R1–R3, 1–5 arquivos)

**Pipeline real** (`AtlasDevRuntimeService` → `AtlasDevFastPathOrchestrator` → `PipelineRunExecutor`):

1. **Runtime init** (sem provider): workspace obrigatório + AWIS execution gate, task/flow normalizados.
2. **Fast-path plan-only**: IntakeNormalizer → TaskClassifier (bug|feature|refactor) → RiskLevelScorer (R1–R5) → SpecComposer (CompactSdd) → CodeDiscoveryEngine → prompt; receipts em `storage/atlas-dev/receipts/<run_id>/`.
3. **Provider** (Claude/Hermes/Codex/Cursor lockado): M1 single-shot; **M2 repair loop** (cap 3, anti-spin por assinatura de falha); M4 best-of-N (opt-in).
4. **Gates pós-provider — defaults verificados em [config/atlas_dev.php](../config/atlas_dev.php):**
   - ScopeGuard (arquivos permitidos) → PatchApplier → **VerificationGate (suite real)**
   - **E1 Intent Judge = `hard`** | **E2 Quality Gate = `hard`** | **E3 Mutation Score = `advisory`, threshold 60% MSI** (roda infection escopado nos arquivos tocados, MSI anti-gaming em população completa, per-file check) | **E4 Shadow-Diff = `hard`** | **E5 Regression Baseline = `hard`** | E6/W1 weak-output = `advisory`
   - CompletionStateGate: honesty flags fazem downgrade PASSED → NEEDS_REVIEW; nunca esconde.
5. **Certificação soberana**: `atlas:engineering:deliver` / RealExecution kernel → worktree isolado → suite REAL do projeto (`php artisan test`, sqlite dedicado) → self-repair até N → `AtlasDevGateAdapter::certify()` → branch de review (`atlas/delivery/*`), **nunca merge automático**.

**Evidência de funcionamento real:** receipts persistidos, models `AiRealExecution*` (Worktree/PatchRun/TestRun/RepairAttempt/DeliveryPack/Certification), certificação machine-resolved persistida (ex.: `mfinal-scheduleparser-weeks`: 17/17 testes, 9/9 assertions, schema `atlas.dev.run_certification.v1`), testes de feature vivos (smoke desktop, anti-dilution do mutation gate).

### 3.2 ATLAS FORGE — fábrica de obras (R3–R5, multiagente)

**Blocos:** `ForgeIntakeService` (52k) → work packets → `ForgeMultiAgentSchedulerService` (topologia: roles planner/worker/verifier/researcher/debugger/reviewer; ownership_map com detecção de conflito; integration_plan single_worker | parallel_no_overlap | serial_merge_on_overlap | blocked) → `ForgeWorkPacketExecutionCycleService` (selectPacket → planExecution → startCycle → complete/fail/block) → `ForgeQaGateRunner` (6 gates: spec_complete, acceptance_criteria, verification_plan, evidence_ready, tests_declared_or_blocked, certification_ready) → `ForgeObraCertificationService` com **SpecAdversary vivo** (Obra #2) → long-horizon state + `ForgeFailureIntelligenceService` + `ForgeOutcomeMemoryService`.

**Camada de invocação de provider** (a mais governada do sistema): `AtlasForgeProviderInvocationService` com **13–14 gates** (obra obrigatória, decision receipt com hash proof-of-binding, AWIS gate, capacity, e no modo EXECUTE: confirm_runtime_dispatch + confirm_provider_call + confirm_budget + driver configurado). Router com 11 drivers (claude_cli, codex_cli, gemini_cli, antigravity_sdk, cursor_sdk/cli, hermes_cli, atlas-local…; council/minimax em shadow).

**Estado default (honesto):** `execution_mode=safe_simulation`, invocação `dry_run`, gate soberano **observe-mode**. O Forge hoje certifica **processo e spec-fidelidade** com força; a certificação de **execução** real fica atrás de flags de soberania (decisão do operador de spend/autonomia).

### 3.3 AUTONOMOUS — o loop 24/7

**Loop de goals** (`AtlasAutonomousEngineeringService`, 1003 linhas): router → goal+receipt → ciclo → world model (nós/arestas de 10 dirs/120 arquivos) → **RAG Gate obrigatório** (threshold 82%, bloqueia e abre repair se insuficiente) → plano → work step em **`safe_simulation`** (tests_run=0) → repair loop → compounding outcome (ai_run_outcomes + ai_learning_candidates) → certificação via kernel.

**Órgãos do AutonomousEvolution (69 dirs):** `AtlasLoopMasterSwitch` (ATLAS_LOOP_MASTER_ENABLED, default FALSE, lê .env direto, fail-closed), Merge/ (auto-merge com pre-flight gate, conflict detector 3-way, staleness refuser, calibrated confidence gate, change-class drain gate, forbidden-self-targets pétreos — judge/certifier/switch nunca se auto-editam), Discovery/, Grinder/, Brain/, Verify/ (judge adversarial), Quality/, Receipts/, Sentinels/, Recovery/, Twin/. **SelfConstruction (~1.112 arquivos):** máquina de fases de dispatch com invokers reais, liveness monitor, task fabric + serving queue.

**Achados críticos (verificados):**
1. **O auto-merge NÃO consulta o AcceptanceGate.** `AtlasLoopAutoMergeService::mergeOne()` re-prova (git apply + php -l + canário) e passa por gates de negócio (conflict/staleness/confidence/classe) — mas zero invocação de `certify()`. Uma proposta pode chegar à main sem julgamento soberano de execução. Design da "Merge-livre v2", mas é um bypass real do kernel.
2. **`AtlasAutonomosGateAdapter` está unwired** — a certificação do goal usa o `AtlasDevGateAdapter`; a witness-set `frozen_judge_clean_checkout_reproof` existe como contrato, não como runtime.
3. **`safe_simulation` = 0 testes reais** — o floor recusaria promover (o que é correto e honesto); por isso a certificação autônoma hoje reporta blocked/needs-review em vez de green fake. O sistema prefere a verdade ao teatro — comportamento certo, capacidade incompleta.

---

## 4. Quando usar cada executor

| Critério | **Atlas Dev** | **Atlas Forge** | **Autonomous** |
|---|---|---|---|
| Escopo | 1–5 arquivos, R1–R3 | Obras multi-arquivo/multi-dia, R3–R5 | Escopo dado ao loop; evolução contínua |
| Quem pede | Operador (chat/CLI/desktop) | Operador via obra/intake | Ninguém — origina sozinho 24/7 |
| Topologia | Single-provider + repair | Multiagente com ownership map + verifier/reviewer | Task fabric + serving + FrozenJudge |
| Gate soberano | **Enforcing** no deliver/certify | Observe (enforce opt-in) | Consultado; automerge bypassa |
| Latência | Minutos | Dias/semanas (milestones) | Contínuo |
| Use para | Bug fix, feature pequena, refactor curto — o dia-a-dia | Sistema novo, refactor enterprise, migração multi-domínio | Moer um escopo continuamente (hardening, coverage, evolução) |

---

## 5. Evidência comparativa disponível (honesta)

**Existe:**
- **Rivals 2.0** operacional (`atlas:rivals`, `App\Services\Ai\Rivals`): 10 runs persistidos em `storage/atlas/rivals/runs/`, receipts com scoring multi-dimensão (correctness/validation/rubric/taste/bloat), Adjudicator que **invalida** claims sem repetição mínima (1 adjudication `invalid` por falta de 3 reps — o sistema pune a própria pressa), 9 suites externas clonadas (SWE-Bench-Live, Aider Polyglot, Terminal Bench, Senior SWE Bench…).
- **Fair Claude Benchmark** especificado ([docs/atlas-cli-fair-claude-benchmark.md](atlas-cli-fair-claude-benchmark.md)): Atlas CLI + Claude Opus vs Claude Code + Claude Opus, mesmo modelo, hipótese "o harness entrega mais tarefas prontas sem intervenção".
- **Cobertura de testes**: 31 arquivos de teste no Kernel, 66 Engineering, 109 Forge, 36 Autonomous, ~5.946 arquivos de teste no repo.
- **Entregas certificadas persistidas** (ex. `mfinal-scheduleparser-weeks`: 17/17 pass, gates AEDPDS passed).

**NÃO existe (ainda):**
- Resultado **agregado** persistido de Atlas vs Claude Code / Codex (pass-rate comparado, N estatístico). A hipótese de superação do Dev é **bem fundada estruturalmente e ainda não provada numericamente**. O próprio Rivals adjudicaria "invalid" um claim de superioridade hoje — e este relatório respeita isso.

---

## 6. NOTAS FINAIS (régua: entrega de engenharia de software de nível empresarial #1 global = 10)

### Por bloco

| Bloco | Nota | Justificativa |
|---|---|---|
| **Engineering Kernel (design + implementação)** | **9.0** | Piso soberano real, fail-closed, não-sobrescrevível (`max(piso, config)`), 11 invariantes com dente, receipt selado, spec-adversary, probes computados do diff. Nenhum harness comercial público (Claude Code, Codex, Cursor, Devin) tem um juiz de aceitação soberano equivalente. Desconta: caminho legado hash-equality no criteria binding, architecture probe advisory-strong, e o floor decide sobre a evidência que recebe — não a coleta. |
| **Atlas Dev (entrega governada)** | **8.5** | Pipeline completo VIVO com E1/E2/E4/E5 `hard` por default + mutation advisory 60% + scope guard + repair anti-spin + verificação pela suíte REAL em worktree isolado + receipts auditáveis + entrega em branch de review. É estruturalmente mais verificado que Claude Code/Codex crus (que entregam diff + testes se você pedir, sem mutation gate, sem census de símbolos públicos, sem receipt soberano). Desconta: E3 ainda advisory (não hard), superação numérica não provada (Fair Claude pendente), e o motor de geração É Claude/Codex — o uplift é do harness, por construção. |
| **Atlas Forge (obras)** | **7.0** | Arquitetura de fábrica completa e testada (intake → packets → scheduler multiagente com ownership map → 6 QA gates → cert com spec-adversary vivo → long-horizon state → failure intelligence). A camada de invocação é a mais governada do sistema (13–14 gates). Desconta forte: gate soberano em observe-mode, execução default safe_simulation/dry_run, evidence bundle com mutation/security em default-waive — o Forge hoje certifica processo e spec, não correção de execução, por default. |
| **Autonomous OS (loop 24/7)** | **6.0** | A construção é imensa e os guardrails pétreos são reais (master switch fail-closed, forbidden-self-targets, RAG gate que bloqueia, compounding). E o sistema é honesto: prefere blocked a green fake. Desconta forte: automerge bypassa o AcceptanceGate (o buraco mais sério do sistema hoje), adapter Autonomos unwired, safe_simulation sem testes reais, músculo multi-file não fecha obras — origina bem, completa mal. Autonomia real com certificação soberana fim-a-fim ainda não existe. |

### Nota final do Atlas Engineering OS

## **7.8 / 10** (hoje, como sistema de ENTREGA) — com **design/teto em 9.5**

**Leitura da nota:**
- **Contra Claude Code / Codex como produtos** (a comparação que importa para o Dev): no eixo **governança + verificação + honestidade + auditabilidade**, o Atlas Dev está **acima** — mutation gate, census de símbolos, scope guard, criteria congeladas, receipt soberano e entrega em branch de review não existem neles. No eixo **qualidade bruta de geração**, é empate por construção (o músculo é o mesmo modelo). No eixo **prova numérica**, o claim "supera" está **pendente** — a spec Fair Claude existe exatamente para isso e ainda não foi rodada em volume adjudicável. Veredito honesto: *estruturalmente superior, numericamente não-certificado*.
- **Contra "construtora de software empresarial #1 global"**: em processo de certificação e anti-fake-green, o AAEOS já opera num padrão que times de elite não têm (nenhuma empresa roda mutation floor soberano + spec-adversary + evidence ledger em toda entrega). Em **capacidade de execução provada em volume** — obras multi-arquivo completadas, throughput sustentado, enforce vivo nos três executores — ainda está abaixo do padrão enterprise de elite. A nota 7.8 é isso: a **lei** é de número 1 global; o **músculo sob a lei** ainda não é.

### O caminho de 7.8 → 9+ (curto e já mapeado)

1. **Fechar o bypass do automerge**: `AtlasLoopAutoMergeService::mergeOne()` consultar `AcceptanceGate::certify()` antes do commit (o gate já recusa evidência vazia — é wiring, não construção).
2. **Wire do `AtlasAutonomosGateAdapter`** com FrozenJudge real em checkout limpo (a witness-set já está definida).
3. **Forge enforce-mode** + executor injetando evidência real (tests_run, scan, mutation) no bundle — matar os default-waives dos adapters Forge/Autonomos.
4. **E3 mutation `advisory` → `hard`** no Dev (o floor soberano já exige 0.6 no certify; falta o pipeline interativo).
5. **Rodar o Fair Claude em volume adjudicável** (≥3 reps por caso, Adjudicator valid) — transformar "supera Claude Code" de hipótese estrutural em número certificado.
6. Fechar o caminho legado do criteria binding (todo caller carregando criteria cruas).

---

*Relatório gerado por análise de código em primeira mão + 7 varreduras de agente. Locais canônicos: kernel em `app/Services/Ai/EngineeringKernel/`, docs-mãe em `docs/engineering-knowledge-base/atlas-agentic-engineering-os*.md`. As matrizes de estado nos docs (gap matrix, department maturity) estão com snapshot ~26/05 e NÃO refletem as Obras #1–#3 de 03–04/07 — precisam de re-snapshot.*
