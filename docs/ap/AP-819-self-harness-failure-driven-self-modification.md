---
title: AP-819 Self-Harness — Harness que aprende com as próprias falhas (failure-cluster → harness-surface edits, governado)
status: implemented
owner: atlas-ai / learning / kernel-failure
line_limit: 240
related_paths:
  - app/Services/Ai/AiWorker.php
  - app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
  - app/Services/Ai/Kernel/Failure/FailureClassifier.php
  - app/Services/Ai/Kernel/Failure/FailureDomain.php
  - app/Services/Ai/Cognitive/Failure/FailureSignatureClassifier.php
  - app/Services/Ai/Cognitive/Failure/FailureSignatureRepository.php
  - app/Services/Ai/Cognitive/Failure/FailureRepetitionAlerter.php
  - app/Console/Commands/AtlasFailureCommand.php
  - app/Services/Ai/Compounding/AtlasLearningProposalApplier.php
  - app/Services/Ai/Compounding/AtlasLearningProposalService.php
  - app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php
  - app/Services/Ai/AutonomousEvolution/AtlasLoopProposalOutOfProcessVerifier.php
  - app/Services/Ai/AutonomousEvolution/AtlasLoopProposalPromotionGate.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionDetector.php
  - app/Services/Ai/SelfConstruction/AtlasSelfImprovementAdversarialRecheck.php
  - config/atlas.php
  - database/migrations/2026_05_07_160000_create_failure_signatures_table.php
  - docs/engineering-knowledge-base/domains/learning.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
---

# [AP-819] Self-Harness — Harness que aprende com as próprias falhas

## 1. Propósito + fonte verificada

Capturar, sob governança soberana, o mecanismo do paper **Self-Harness**
(arXiv 2606.09498, Shanghai AI Lab — **lido e verificado**, não inferido de
resumo): com o **modelo congelado**, o harness se otimiza a partir das próprias
falhas — `Weakness Mining (traces → clusters determinísticos) → Harness Proposal →
Proposal Validation (não-regressão em suite congelada) → adoção`.

**Correção material após ler o paper (anti-over-claim):** os edits do paper **NÃO
são código-fonte arbitrário do harness**. Quote decisivo: *"Self-Harness can only
change the harness definition file that configures how DeepAgent is instantiated"* —
superfícies editáveis = **pontos de configuração declarados** (instruction, tool
policies, runtime control policy com `max_recent_tool_errors`/`max_total_tool_
messages`, verification guidance). Validação = pass-rate em **dois splits
congelados** (held-in + held-out), regra de promoção `Δ_in≥0 ∧ Δ_ho≥0 ∧ max>0`;
~40-60% dos candidatos rejeitados. Resultados (Terminal-Bench-2.0, modelo frozen):
MiniMax M2.5 40.5→61.9% held-out (+53% rel), Qwen3.5 +60%, GLM-5 +33%. Clustering é
**determinístico evaluator-grounded** (assinatura = causa-do-verifier + status
causal + mecanismo), não LLM livre.

É a tese N×M ao vivo: provider congelado, harness multiplica. E o degrau de risco é
**menor** do que a v1 deste AP assumiu: edit de config declarada ≈ o que o Atlas
**já** adota reversível/receitado.

**Achado Atlas (code-verified):** ~70% existe desconectado; o cérebro de falhas
(`Cognitive/Failure/*`) está **dormente e starved** — único alimentador de
`failure_signatures` é o CLI manual (`AtlasFailureCommand.php:79`);
`AtlasEvidenceLedger::recordFailure` (`:366`) tem zero chamadores de produção; o
pipeline morre em `open_learning_failure_review` (`FailureRepetitionAlerter.php:82`).
E a peça de adoção do paper **já existe**: `AtlasLearningProposalApplier.php:44`
tem kind **`failure_pattern`** (apply+reverse governado) + `routing` (rota viva).

## 2. Placement e gate

`atlas:ai:place-feature`: `layer=domain`, `domain=learning`,
`flow=learning.failure_review`, `gate_status=blocked`. Este AP é o gate de revisão
que o operador pediu (a obra toca a camada de execução do próprio Atlas).

## 3. Reuso vs net-new (honesto, pós-crítica + pós-paper)

| Estágio (paper) | Estado no Atlas | Âncora |
|---|---|---|
| Collect traces | ✓ reuso as-is | `AiWorker.php:1809-1823`/`426-441` (ai_job_attempts + ledger) |
| Cluster (assinatura determinística) | ✓ reuso (mesma filosofia do paper) | `FailureSignatureClassifier` signature_key + `FailureRepetitionAlerter` |
| Harness Proposal (config-surface) | 🔴 net-new (a ponte) — mas mira **config declarada**, não fonte | seam: `AtlasSelfConstructionDetector::detect($extraRequests)` (`:35`) |
| Validate (suite frozen, 2 splits) | 🟡 motor existe; **suite held-in/held-out do harness é net-new** | `AtlasEvolutionFrozenJudge` + `OutOfProcessVerifier` |
| Adopt (reversível, receitado) | ✓ **já existe p/ config-level** | `AtlasLearningProposalApplier.php:44` (failure_pattern/routing, apply+reverse) |

## 4. Re-sequenciamento (3 degraus, do paper pra cima)

- **Obra A (ship-now, risco-zero):** F1 auto-feed + F3 métrica honesta = *"Atlas vê
  as próprias falhas"*. Acorda o corpus dormente; gera o dataset que calibra B.
- **Obra B (o paper de verdade, gated):** F2′ ponte cluster→**proposta de edit na
  Atlas Harness Surface** (config declarada) + F4′ a suite congelada de validação.
  Risco estrutural MENOR que a v1 deste AP: a superfície editável **é** a allowlist
  por construção; adoção reusa o applier reversível existente. Continua
  operator-gated.
- **Obra C (fora de escopo neste AP):** edits em **código-fonte** do harness
  (AiWorker/Router/gates). O próprio paper não faz isso e admite: *"higher-stakes
  harness changes would require stronger acceptance gates than pass-rate
  non-regression alone."* Só com AP próprio futuro.

## 5. Fatias

**F1 — Auto-feed (Obra A).** Harvester: `ai_job_attempts.status ∈ {failed,timeout}`
+ ledger `provider_exception`/`job_failed` → `FailureSignatureClassifier` existente
→ `failure_signatures`. Projeta `provider`+`tool` em `canonical_features` (hoje
ausentes, `:137-146`). Flag default-OFF, modo `observe`. Reusa
`AtlasCaptureQualityGate` (precedente da semana de ~100% ruído).

**F3 — Métrica de recorrência honesta (Obra A).** Delta de recorrência pré/pós por
assinatura, computado sobre **`ai_job_attempts.status` cru** por caminho que nenhum
candidato pode editar (G3). Em A é painel/learning; em B compõe com a suite.

**F2′ — Ponte cluster→harness-surface (Obra B, gated).** Define a **Atlas Harness
Surface v1** — análogo do "harness definition file" do paper: pontos de config
DECLARADOS e SÓ eles editáveis (ex.: runtime control policy — limites de tool-error
/turnos/loop-breaker; seções de instruction/verification guidance dos providers;
tool policies). Serviço out-of-process lê alertas ≥ high e propõe um edit NESSA
superfície (tipo os aceitos do paper: "create output early", "redirect after N tool
calls", "use correct content tags"). Proposta entra como `AtlasLearningProposal`
(kind novo `harness_config`, apply+reverse obrigatórios no applier).

**F4′ — Suite congelada do harness (Obra B, gated).** O oráculo do paper: conjunto
de tarefas representativas do harness Atlas, **split held-in/held-out fixado ANTES
de qualquer loop**, verifier congelado. Regra de promoção do paper:
`Δ_in≥0 ∧ Δ_ho≥0 ∧ max>0` (não-regressão dupla). Net-new real: o Atlas não tem hoje
um benchmark congelado do próprio harness.

## 6. Governança + buracos fechados (pós-crítica, recalibrado pós-paper)

- **🚨 RED LINE — adoção final operator-gated.** Mesmo sendo config reversível, um
  edit de harness-surface só vira default com teu aceite (trust-ladder: a classe
  `harness_config` nasce no degrau mais friccionado). Nenhum adopt autônomo
  irreversível; apply+reverse obrigatórios (o applier já impõe isso por kind).
- **G1 (recalibrado) — allowlist por construção.** A Harness Surface declarada
  resolve o G1 estruturalmente: o pipeline de sinal (`Cognitive/Failure/**`, judges,
  métrica, a própria suite F4′) **não pertence** à superfície ⇒ inalcançável por
  proposta. Teste estrutural prova que um edit fora da superfície é rejeitado.
- **G2 (recalibrado) — oráculo = suite congelada + held-out.** O paper resolve o
  oracle-gaming não com reprodução por-cluster, mas com verifier FIXO + split
  held-out fixado antes do loop + não-regressão dupla. Adotamos isso. O músculo não
  escreve o oráculo — ele já existe congelado.
- **G3 — anti symptom-gaming.** Recorrência (F3) medida no outcome cru; e a
  promoção (F4′) é por pass-rate na suite, que um edit de config não pode reescrever.
- **G4 — branch/proposal-creation autônoma é decisão sua.** O loop auto-enfileira
  (`AtlasLoopQueueRefiller.php:40-70`); ligar B = você aceita propostas autônomas
  acumulando na fila de review.
- **Barra de diagnóstico (gate de B, suavizada pós-paper).** O alvo do diagnóstico
  agora é "qual seção da Harness Surface" (não "qual arquivo-fonte") — bar mais
  baixa, ainda medida com F1+F3 vivos antes de ligar F2′.

## 7. Métrica de sucesso honesta

Dupla, como no paper: (a) **não-regressão + ganho na suite congelada**
(`Δ_in≥0 ∧ Δ_ho≥0 ∧ max>0`); (b) **redução de recorrência do cluster de origem**
medida no outcome cru (G3). Edit que passa (a) mas não (b) é reportado como
"ganho geral sem resolver o cluster" — nunca como sucesso do cluster.

## 8. Riscos + mitigações

| Risco | Mitigação |
|---|---|
| Oracle gaming | G2: verifier fixo + held-out fixado pré-loop + não-regressão dupla |
| Symptom gaming | G3: outcome cru não-editável; suite fora da superfície |
| Edit toca pipeline de sinal | G1: fora da Harness Surface por construção + teste estrutural |
| Overfit ao benchmark (limitação admitida do paper) | held-out + (b) do §7 + review humano |
| Firehose de propostas | regra de promoção dupla rejeita (paper: ~40-60% reject); cap por run |
| Corpus ruidoso | F1 + `AtlasCaptureQualityGate`; embedding deferido |

## 9. Definition of Done

- **Obra A (F1+F3):** corpus enche de runs reais (não-CLI); provider/tool na
  assinatura; quality-gate ativo; F3 no outcome cru; flag default-OFF; testes;
  `atlas:failure` manual preservado.
- **Gate de B:** relatório de acurácia cluster→seção-da-superfície + Harness
  Surface v1 declarada + suite held-in/held-out congelada + confirmação G4.
- **Obra B (F2′+F4′):** cluster real → proposta `harness_config` na superfície;
  promoção pela regra dupla; apply+reverse provados por teste; edit fora da
  superfície rejeitado por teste; never-irreversible provado.
- **Global:** docs-health + architecture-validate + DomainProfileCompliance verdes.

## 10. Fora de escopo

- **Obra C:** edits em código-fonte do harness (o paper não faz; AP futuro próprio).
- Adopt autônomo irreversível / afrouxar never-merge (red line).
- Detector de loop-infinito 1ª classe como bucket novo (o edit de runtime-control
  policy do paper — "redirect after N tool calls" — cobre o caso SEM bucket novo).
- Embedding semântico de falhas (deferido).

## 11. Registro de revisão adversarial

**R1 (crítico de arquitetura, 7 âncoras code-verified):** pegou over-claim "Validate
= reuso" (task-shape era net-new); over-scope (re-seq Obra A/B); G1/G2/G3; F2
prematuro → gated. **R2 (leitura do paper real, arXiv 2606.09498):** a v1 deste AP
assumia edits em código-fonte — o paper edita **superfície de config declarada**,
validada por não-regressão dupla em suite congelada. Obra B re-desenhada para o
mecanismo real (risco menor, mapeia no `AtlasLearningProposalApplier` existente);
edits de fonte viraram Obra C fora de escopo. G1/G2 recalibrados estruturalmente.
A disciplina anti-over-claim é parte do contrato deste AP.

## 12. Status de implementação (2026-06-11, /goal do operador = autorização)

**Obra A SHIPPED+LIVE:** \`FailureAutoFeedHarvester\` (+\`atlas:failure:auto-feed\`,
schedule horário, flag ON) e F3 \`atlas:failure recurrence\` (outcome cru, G3).
Prova viva: 17 falhas reais colhidas, 11 alertas, clusters reais (decision_expired
8×). provider/tool em canonical_features (aditivo). 9 testes.

**Obra B SHIPPED+LIVE:** \`AtlasHarnessSurface\` v1 (5 seções, bounds, overlay boot),
kind \`harness_config\` no applier (apply+reverse; NUNCA auto-apply — red line),
\`AtlasHarnessProposalBridge\` (propose-only) e \`AtlasHarnessFrozenSuite\` (14 probes,
split fixo, regra Δin≥0∧Δho≥0∧max>0, suite_hash anti-tamper). Cmd \`atlas:harness\`.
Prova viva: baseline selado 1.0/1.0; a ponte criou 2 propostas REAIS
(receipt_ttl 7200→14400) dos clusters da F1 — aguardando aprovação do operador.
9 testes (G1 estrutural + roundtrip + regra dupla + anti-tamper provados).

**Obra C:** segue fora de escopo (inalterado).

**AUTOPILOT (2026-06-11, diretiva do operador "automático, com base matemática"):**
\`AtlasHarnessAutopilot\` fecha o loop SEM aprovação manual por edit — 3 gates:
(1) bounds da surface por construção; (2) não-regressão dupla na suite congelada
ANTES e DEPOIS do apply, regressão ⇒ reverse imediato; (3) recorrência do cluster
no outcome CRU após a janela de observação (7d), sem melhora estrita ⇒ auto-reverse.
1 edit/run, 1 experimento/chave, proposta tentada nunca re-tenta; recibos no ledger;
painel para o app em GET /ai/harness. A red line de §6 foi ATUALIZADA pelo operador:
pré-aprovação humana → transparência + reversão automática matemática (o desenho de
adoção do próprio paper). supportsAutoApply(harness_config) permanece false — o
autopilot é o único caminho automático e carrega os gates.

**SURFACE v2 (2026-06-11, goal "completo e ultra poderoso"):** seções de INSTRUÇÃO
evoluíveis — a outra metade do mecanismo do paper. \`AtlasHarnessInstructionSurface\`
com 3 seções declaradas e espaço de busca FINITO (default + variantes curadas;
texto fora da biblioteca é rejeitado por validate() — evolução = busca discreta
auditada, nunca texto livre). Fio real via AiPromptBuilder (toda chamada de
provider carrega o texto vivo). Kind \`harness_instruction\` (apply+reverse), ponte
mapeia clusters comportamentais → próxima variante, autopilot processa config E
instrução sob os mesmos 3 gates. Suite v2 = 17 probes, baseline re-selado.
