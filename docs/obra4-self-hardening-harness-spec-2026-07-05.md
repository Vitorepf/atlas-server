# Obra #4 — Self-Hardening Harness (Harness Auto-Endurecível)

**Data da spec:** 2026-07-05
**Status:** SPEC — aguardando FREEZE do operador
**Risk band:** HIGH (toca o EngineeringKernel) → obra HEAVY: gates incompletos = FAIL, não WARN
**Referência externa:** [comet-ml/opik](https://github.com/comet-ml/opik) (Apache-2.0) — referência de DESIGN, não dependência; nada do Opik é importado
**Doutrina externa alinhada:** Anthropic Engineering Note #02 "Loop Engineering 2.0" (as 6 camadas; a camada Optimization é a que falta no Atlas)

---

## 1. Tese da obra

**"A cada ciclo, o harness fica mais difícil de quebrar."**

Hoje o AAEOS tem juiz soberano (Obra #1), spec-adversary (Obra #2) e gates não-funcionais (Obra #3) — mas o sistema **não endurece com as próprias falhas**:

1. Falha reparada NÃO vira teste congelado (a suíte não cresce de falhas reais)
2. Reparo é declarado consertado sem re-provar o caso EXATO que falhou
3. O repair loop regenera às cegas — não diagnostica causa-raiz (failure-brain corpus VAZIO)
4. O aprendizado de outcome não retroalimenta nada (admission.jsonl sem leitor; scorecard/behavior-ledger com 0 callers) — o loop tem 5 das 6 camadas; falta a Optimization
5. O único caminho de merge 100% autônomo não consulta o juiz antes do commit

Esta obra fecha os cinco. Resultado composto: falha → diagnóstico → fix provado no caso original → falha trancada como regressão para sempre → aprendizado alimenta o próximo ciclo → nada landa sem juiz.

## 2. Fatos verificados que motivam (com paths)

| Fato | Onde verificado |
|---|---|
| Floor tem 11 invariantes; nenhum sobre regression-lock ou replay | `app/Services/Ai/EngineeringKernel/SovereignHonestyFloor.php:68-83` |
| Repair do Dev devolve falha crua ao provider (cap 3, anti-spin) sem diagnóstico | `app/Services/Ai/Programming/AtlasDev/Repair/` + `PipelineRunExecutor` |
| Repair do deliver regenera com repairPrompt() sem classificar a falha | `app/Services/Ai/RealExecution/AtlasRepoVerifiedDeliveryService.php` |
| Failure capsules existem no Forge mas corpus do failure-brain está vazio | `Forge/Intelligence/ForgeFailureIntelligenceService.php` + memória AP-819 |
| Automerge autônomo: re-proof `php -l` + gates de negócio, ZERO `certify()` | `AutonomousEvolution/AtlasLoopAutoMergeService.php` (mergeOneCritical — o drain VIVO é o da RAIZ do namespace, 1473 linhas; `Merge/AtlasLoopAutoMergeService.php` é um brick wave-14 de 165 linhas com executor no-op, NÃO o caminho de produção) |
| Outcome learning desconectado: bridge escreve admission.jsonl SEM leitor; scorecard/matcher/behavior-ledger 0 callers | memória `autonomos-outcome-learning-verified-map` (03/07) |
| Tentativas de repair já persistem | `app/Models/AiRealExecutionRepairAttempt.php` |

## 3. Não-objetivos (o adversary deve cobrar isto)

- **NÃO muda a topologia de git.** Main única, commit direto, write-set por agente — intocados. S0 adiciona uma cancela, não uma estrada.
- **NÃO constrói observability nova.** Evidence Ledger/receipts já são superiores ao tracing do Opik.
- **NÃO adiciona LLM no caminho de decisão do kernel.** Todo julgamento novo é determinístico; modelo entra só como advisor CONTEST-only (padrão Obra #2).
- **NÃO importa código do Opik.** Referência conceitual (fluxo trace→diagnose→fix→replay→lock); implementação 100% Atlas-nativa.
- **NÃO toca a superfície de missão** (S5 é deferível para o workstream de superfície).

## 4. Arquitetura — 5 slices + 1 deferível

### S0 — Cancela do automerge (enabling, pequeno)
**Objetivo:** o caminho 24/7 sem humano passa pelo juiz antes de commitar na main.
**Mudança:** em `AutonomousEvolution/AtlasLoopAutoMergeService::mergeOneCritical()` (o drain VIVO da raiz do namespace — não o brick de `Merge/`), ANTES do commit do bloco 5: montar `AcceptanceBundle` da evidência da proposta (re-proof + canário + envelope `_sovereign_evidence` quando threaded) e chamar `AtlasAutonomosGateAdapter::certifyAutonomosDelivery()` (wire real do adapter que hoje é órfão — `TrustLevel::Autonomos`, witness `frozen_judge_clean_checkout_reproof`).
- `PROMOTE` → commit segue idêntico ao de hoje (+ receipt_ref no merge receipt)
- `REFUSE` → proposta fica parqueada `certified_for_review` (mesmo comportamento que staleness/conflict já têm hoje)
**Arquivos:** `Merge/AtlasLoopAutoMergeService.php`, `Adapters/AtlasAutonomosGateAdapter.php`, `AtlasLoopAutoMergeReceiptLedger`
**Aceitação:**
- AC-0.1: proposta com `tests_run=0` alegando pass é REFUSADA no drain (teste prova)
- AC-0.2: proposta com evidência real verde é PROMOVIDA e commitada byte-idêntico ao fluxo atual
- AC-0.3: REFUSE não quebra o drain (segue para a próxima proposta; receipt registra o veredito)
- AC-0.4: zero mudança de topologia git (nenhum branch/worktree novo no caminho)

### S1 — Regression-Lock (a peça central; vira LEI, não feature)
**Objetivo:** toda falha que passou por repair vira caso congelado permanente; a suíte cresce de falhas reais.
**Componentes:**
1. `EngineeringKernel/RegressionLock/RegressionLockLedger` — registro append-only content-addressed: `{failure_id, origin (dev|forge|autonomos), failing_input/test, failure_signature, locked_test_ref, locked_at, flake_status}`
2. **Novo invariante no floor** (`SovereignHonestyFloor`): `regression_locked_for_repaired` — entrega cuja evidência declara `repair_attempts > 0` DEVE carregar `regression_lock_ref`; ausente = REFUSE. Waive quando `repair_attempts = 0`. (Padrão Obra #3: waive-se-não-toca, fail-closed-se-toca.)
3. **Writers nos 3 caminhos de repair:** Dev (`PipelineRunExecutor` pós-repair), Deliver (`AtlasRepoVerifiedDeliveryService` pós-repair), Forge (`fail()`→repair). Cada repair bem-sucedido materializa o caso que falhou como teste PHPUnit (ou critério congelado) + entrada no ledger.
4. **Anti-flake quarantine (anti-Goodhart obrigatório):** antes de trancar, o caso roda N=3 vezes no worktree; instável → vai para quarentena (`flake_status=quarantined`), NÃO entra na suíte, e o lock aponta a quarentena (o invariante aceita `quarantined` como lock válido — trancamos o CONHECIMENTO da falha, não um teste flaky).
**Aceitação:**
- AC-1.1: repair verde no Dev sem lock → floor REFUSA (`repaired_without_regression_lock`) — teste prova
- AC-1.2: repair verde COM lock → PROMOTE; ledger tem a entrada; o teste trancado existe e passa
- AC-1.3: caso flaky (passa/falha alternado no 3× run) vai para quarentena e NÃO polui a suíte
- AC-1.4: dedup — mesma failure_signature não gera dois locks (segunda ocorrência referencia o primeiro)
- AC-1.5: contagem de invariantes do floor 11 → 12, `FLOOR_VERSION` bumpado, receipts carregam a versão nova

### S2 — Replay-Proof (reparo só conta se o caso original passa)
**Objetivo:** matar o "fix que deixa a suíte verde mas não resolve o que falhou".
**Componentes:**
1. Campo novo na evidência de execução: `replay_proof: {original_failure_ref, replayed: bool, passed: bool}`
2. Nos 3 caminhos de repair: o re-run pós-fix DEVE incluir o(s) teste(s)/input(s) exatos da falha original (o Dev já tem `selectedTests`; o deliver já re-roda suíte — a mudança é garantir a INCLUSÃO do caso original e registrar o proof)
3. **Novo invariante no floor:** `replay_proof_for_repaired` — `repair_attempts > 0` sem `replay_proof.passed=true` = REFUSE; waive se sem repair. (Floor 12 → 13 invariantes.)
**Dependência:** S1 (compartilham a leitura de `repair_attempts` e a materialização do caso original — implementar S1 primeiro, S2 reusa).
**Aceitação:**
- AC-2.1: fix que passa na suíte mas falha no replay do caso original → REFUSE (teste com fixture que reproduz exatamente esse cenário)
- AC-2.2: fix com replay verde → PROMOTE com `replay_proof` no receipt
- AC-2.3: os 3 caminhos (Dev, Deliver, Forge) emitem `replay_proof` — 3 testes de integração, um por caminho

### S3 — RepairBrain (diagnóstico antes de regenerar; o "Ollie" Atlas-nativo)
**Objetivo:** o repair deixa de ser "tenta de novo com o erro colado" e vira "entende → escolhe estratégia → age".
**Componentes:**
1. `EngineeringKernel/Repair/FailureTaxonomy` — classes determinísticas: `impl_bug | test_wrong | spec_wrong | env_flake | dependency_broken | scope_miss | unknown`
2. `RepairDiagnosisStage` — roda ANTES de qualquer regeneração; lê receipts + failure capsule + diff + saída do teste; classifica por regras determinísticas primeiro (assinatura de erro, arquivos tocados vs escopo, histórico de flake); modelo entra APENAS como advisor de desempate com veredito `contest-only` (nunca decide sozinho — padrão Obra #2)
3. **Estratégias por classe:** `impl_bug` → regenerar com hint dirigido | `test_wrong` → PARAR e escalar (nunca "consertar" o teste sozinho — proteção pétrea) | `spec_wrong` → devolver ao spec-adversary | `env_flake` → re-run + quarentena | `dependency_broken` → abort com blocker nomeado | `scope_miss` → decompor
4. **Failure-brain corpus finalmente alimentado:** cada diagnóstico persiste `{signature, class, strategy, outcome}` — o corpus vazio do AP-819 passa a crescer a cada repair
5. Integração nos 3 caminhos de repair (o cap de tentativas continua; o que muda é o que acontece entre as tentativas)
**Aceitação:**
- AC-3.1: falha com assinatura de flake conhecida → estratégia `re-run` sem gastar provider (teste prova zero chamada de modelo)
- AC-3.2: classe `test_wrong` NUNCA resulta em edição automática do teste — escala com blocker (teste prova)
- AC-3.3: corpus > 0 após uma rodada de repairs; entrada carrega classe + estratégia + outcome
- AC-3.4: taxa de completude de repair medida ANTES (baseline no primeiro commit da slice) e DEPOIS — o delta é reportado no delivery pack, sem meta inventada (honestidade: medir, não prometer)

### S4 — Fechar COMPOUND → Brain (a camada Optimization do Loop 2.0)
**Objetivo:** os dados de outcome que hoje morrem sem leitor passam a modificar comportamento futuro.
**Componentes:**
1. `OutcomeDigestReader` — o leitor que falta para `admission.jsonl` (e para os organs 0-callers scorecard/matcher/behavior-ledger — reusar os organs existentes, NÃO reconstruir: são unwired, não dead-code, per memória)
2. Três consumidores concretos (um por tipo de aprendizado):
   a. **Exemplares:** outcomes verdes alimentam `DevGreenRunExemplarRetriever` (já existe — ganha fonte viva)
   b. **Routing:** outcomes por (task_class, provider) alimentam `AtlasConductorRoutingMemory::recommend()` (já existe — ganha fonte viva)
   c. **Repair priors:** o corpus do S3 alimenta as priors de estratégia do RepairBrain (falha classe X + estratégia Y funcionou → Y sobe na ordem)
3. **Imunidade obrigatória (lição do echo 04/07):** todo registro consumido carrega provenance; entrada sem origem verificável é ignorada; poisoning de um registro não pode rebaixar prior abaixo do default (clamp)
**Aceitação:**
- AC-4.1: `admission.jsonl` tem leitor com testes (0 callers → ≥1 caller provado por teste de integração)
- AC-4.2: run N+1 recupera exemplar/rota aprendidos do run N (teste de flywheel, mockando o provider)
- AC-4.3: registro sem provenance é ignorado (teste de imunidade)
- AC-4.4: prior nunca cai abaixo do default por influência de UM registro (teste de clamp)

### S5 — Gramática de goal-condition no intake (DEFERÍVEL para o workstream de superfície)
**Objetivo:** o intake de missão aceita a gramática que o mundo aprendeu: `estado final verificável + escopo + stop rule + budget` e a mapeia para os campos do FREEZE (criteria, scope guard, caps).
**Nota:** operador sinalizou "depois a gente volta para a superfície" — esta slice fica na spec por completude, marcada como ÚLTIMA e destacável sem afetar S0–S4.

## 5. Ordem, dependências e milestones

```
S0 (cancela)  →  S1 (regression-lock)  →  S2 (replay-proof)  →  S3 (RepairBrain)
                                                                      ↓ corpus
S4 (COMPOUND→Brain) — independente de S0-S2, consome o corpus do S3 quando existir
S5 — deferível, sem dependência
```

- **M1 (fim S0+S1):** nenhum repair certifica sem lock; automerge sob juiz. O sistema já "endurece a cada ciclo".
- **M2 (fim S2+S3):** reparo prova o caso original e diagnostica antes de agir; corpus crescendo.
- **M3 (fim S4):** flywheel fechado — outcome do ciclo N muda o comportamento do ciclo N+1 com prova de teste.

**Regra de entrega (pétrea, do operador):** commit-por-slice direto na main, commitando SÓ os arquivos da obra; nunca `git add -A`; branch de obra é proibida (contaminação por worker — lição Obra #1). Cada slice passa pelo próprio gate soberano antes do commit (dogfood, como Obra #1: o gate forçou MSI 49%→79% na própria obra).

## 6. Plano de verificação da obra inteira

1. **Testes:** cada AC acima tem teste nomeado; suíte da obra em `tests/Unit/Ai/EngineeringKernel/{RegressionLock,Repair}/` + `tests/Feature/Ai/AutonomousEvolution/AutoMergeSovereignGateTest.php`. Wiper-safe: SQLite `:memory:`, zero RefreshDatabase em pgsql (lição 15/06).
2. **Mutation:** MSI ≥ 0.6 nas classes novas (o floor exige de si mesmo).
3. **Invariantes do floor:** 11 → 13; `FLOOR_VERSION` bumpado; teste de regressão prova que os 11 antigos continuam byte-idênticos em comportamento.
4. **Baseline honesto:** antes do S3, registrar taxa atual de completude de repair (dos `AiRealExecutionRepairAttempt` existentes) — o delta pós-obra é medido, não prometido.
5. **Métricas de sucesso (medíveis, anti-Goodhart aos pares):**
   - locks criados / repairs (alvo: 100%) **pareado com** taxa de quarentena (se quarentena explodir, o lock está trancando flakes — investigar)
   - taxa de completude de repair **pareada com** taxa de escape de regressão (fix rate subir com escape subindo = Goodhart)
   - callers de outcome-data: 0 → ≥3 **pareado com** uplift real de green-run (consumo sem uplift = teatro)

## 7. Passe de spec-adversary (auto-ataque antes do FREEZE)

| Risco | Mitigação na spec |
|---|---|
| **Lock de teste flaky infla a suíte com ruído** | Quarentena 3× obrigatória antes do lock (AC-1.3); flake tranca conhecimento, não teste |
| **Suíte cresce sem limite → CI lenta** | Dedup por failure_signature (AC-1.4); locks rodam como suite escopada no worktree, não em todo `php artisan test` |
| **RepairBrain vira LLM-juiz disfarçado** | Classificação determinística primeiro; modelo é contest-only; `test_wrong` nunca age sozinho (AC-3.2) |
| **Poisoning do flywheel (lição do echo)** | Provenance obrigatória + clamp de prior (AC-4.3, AC-4.4) |
| **S0 quebra o fluxo da esteira do operador** | S0 toca APENAS o drain do automerge autônomo; a esteira de workers (main direta, testemunha humana) não passa por esse código — teste AC-0.4 prova topologia intacta |
| **Goodhart em "repair completion rate"** | Métricas sempre aos pares (§6.5) |
| **Obra edita o próprio juiz (recursive-safety)** | Mudanças no floor são ADITIVAS (2 invariantes novos); forbidden-self-targets do automerge continuam pétreos; o floor não ganha nenhum caminho de waive novo para os 11 existentes |

## 8. Governança pré-implementação (obrigatória)

1. `php artisan atlas:ai:session-bootstrap --task="obra4 self-hardening harness" --json`
2. `php artisan atlas:ai:place-feature "regression lock ledger + repair diagnosis no engineering kernel" --json` (placement esperado: `EngineeringKernel/` para S0-S2, `EngineeringKernel/Repair/` novo para S3, `AutonomousEvolution/` para S4 — o place-feature confirma ou corrige)
3. Após docs/código: `atlas engineering knowledge sync --prune` + `atlas engineering knowledge index-code --prune`
4. FREEZE desta spec pelo operador antes de qualquer implementação (esta spec é a candidata; o hash dela no commit do S0 é o frozen ref da obra)

## 9. Mapa de referência Opik (o que estudar, não copiar)

| Conceito Opik | Uso nesta obra |
|---|---|
| Ollie: trace → root cause → diff → approve | Blueprint conceitual do S3 (RepairBrain); no Atlas o "approve" é o gate, não o humano |
| Failing trace → regression test automático | S1 — elevado de feature para invariante de kernel |
| Rerun contra o input original da falha | S2 — elevado de prática para invariante |
| Test suite que cresce de falhas de produção | S1 ledger + writers |
| Agent Sandbox (what-if de grafo inteiro) | FORA desta obra — organs Twin/Simulation já existem dormentes; acordá-los é obra futura |
| Licença Apache-2.0 | Estudo livre; se qualquer trecho for adaptado (não previsto), atribuição no arquivo |
