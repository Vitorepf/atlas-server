# ARCH BLUEPRINT — KernelTriad (split do Kernel em 3 produtos)

> status: draft-v2 (SOBREVIVEU ao verify adversarial; emendas obrigatórias incorporadas)
> EMENDAS ao passo 4 da migração — no MESMO commit do git mv: (i) sweep das SELF-strings do scanner (ele lê arquivos do PRÓPRIO dir por app_path/base_path em ~15 linhas — pós-mv viram '' e os token-checks disparam violações em massa; a mitigação original estava INVERTIDA p/ esses casos); (ii) atualizar os 8 externos por path — em especial os 4 GUARDS fail-open que protegem o path do scanner (ReadinessPathPolicy:89 VERIFICADO, ReservationRepository:293, DurableReservationContract:159, ReleaseWriterSection:1326 forbidden_scope) — senão o scanner novo fica DESPROTEGIDO em silêncio; (iii) 4 testes por path/FQCN-string (KernelBypassRegression:170 file_get_contents do scanner, CodeGraphContext:160, ArchitectureValidate:532, ContextPack:1061); (iv) shim eager p/ class_exists (ProgrammingProfessionalCompletionAudit:907).
> data: 2026-07-22
> obra: GOD Debulk / cluster GOVERNANCA-QUALIDADE
> insumos: CONSOLIDATION-MAP.md (linha Kernel, "KEEP + SPLIT em blueprint")

## 1. Contexto provado (censo medido)

| Subpasta | LOC | Files | Callers ext | Papel provado |
|---|---|---|---|---|
| Architecture/ | 26.031 | 37 | 35 | Linter: `KernelArchitectureStaticScanner` 15.566 LOC, 166 checks registrados (`architectureScanChecks()` L207 — VERIFICADO), numeração real ap1..ap687, 167 métodos scan*. + FeaturePlacement 1.086, StructureMotherAudit 992, contratos AtlasAp* ~1.500 |
| Evidence/ | 5.239 | 11 | 179 | Ledger 1.096 + `AtlasLedgerReplayService` 2.240 (2º maior do Kernel) + projeções + âncora hash-chain |
| Decision/ | 2.380 | 18 | 26 | Receipts, budgets, seleção de provider, reversibilidade |
| Pipeline/ 1.324/13/6 · Repair/ 1.098/14/10 · Procedural/ 887/6/4 · Domain/ 732/5/24 · Failure/ 716/6/4 · Gates/ 654/13/6 · Provider/ 611/11/6 · Capability/ 497/3/**0** · Envelope/ 444/11/15 · Slo/ 365/4/22 · Surface/ 234/5/13 · Behavior/ 175/1/1 · Mcp/ 87/1/1 | | | | |

**Architecture = 63% do bloco e não é runtime.**

### Fronteiras medidas
- **0 imports de `Kernel\Architecture` dentro do Kernel** (VERIFICADO) — o linter é folha downstream pura.
- Runtime→Evidence (escrita): 4 arquivos. Evidence→runtime: só VOs (`OperationEnvelope`, `RepairDecision/Result`, `DecisionReceiptHash`, `ComputeEffortPolicy`) + exceção real: ledger injeta `Failure\FailureClassifier`+`FailureHandlerRegistry` no construtor (usados em `recordFailure`).
- `Failure/` não importa nada acima — base do grafo.

### Fronteira de escrita do ledger (medida)
- **0 escritas diretas** em `AtlasLedgerEvent` fora de `Kernel/Evidence` (VERIFICADO) — storage já estanque.
- API: `record()` + 12 métodos tipados `record*` + ~10 leitores. **148 arquivos externos escrevem via API tipada** — writers NÃO são só G0-G8; selar por allowlist de caller quebraria metade do sistema. A fronteira real é a API tipada.

## 2. Decisão dos 3 produtos

| Produto | Veredito | Destino |
|---|---|---|
| Decision-runtime | KEEP como espinha | `Ai/Kernel/` passa a significar "runtime de request" e nada mais (~10,3k) |
| Evidence-ledger | KEEP onde está, fronteira SELADA, **zero mudança de API** | 179 refs externas; promover = churn puro. Ganha selo por regra de scanner |
| Architecture-linter | **PROMOVER a bloco próprio** | `Ai/ArchitectureGovernance/` (sem colisão — verificado); leva `Capability/` junto (0 callers ext) |

## 3. Owners-alvo

### 3.1 Split do godfile do scanner (por família de check-key, sufixo `Audit`, sob `ArchitectureGovernance/Scanner/`)
- `KernelArchitectureStaticScanner` (façade, fica ≤400): registry = merge dos audits; API pública intacta; **check-keys ap* são contrato congelado**.
- `ScanPrimitivesSupport` ≤400 (walkers, forbidden-tokens, shape de findings).
- Audits por família (checks medidos): SelfImprovement 21 · AgentBehavior 13 · ArchitectureOperations 13 · Ledger ~12 · DecisionReceipt 8 · InboxAction 8 · ScheduleReplay 6 · VoiceRealtime/RepairLoop/KernelPipeline 4 cada · OpenBrainRetrieval/SurfaceBoundary/SloTelemetry ~5 cada. Tetos 500–1.400.
- Cada audit expõe `checks(): array<string,callable>`; teste falha se o conjunto de keys divergir do snapshot.

### 3.2 Evidence — ganha exatamente 3 coisas (zero mudança de código do ledger)
1. Check novo `ap*_ledger_single_writer`: escrita em `AtlasLedgerEvent` só de `Kernel/Evidence` (congela o que já é verdade).
2. Check novo `ap*_evidence_import_allowlist`: Evidence só importa os VOs listados + `Kernel\Failure\*` (exceção documentada).
3. Documentação da fronteira: writers = 148 arquivos via API tipada; selar por caller = rejeitado explicitamente.

### 3.3 Re-homes internos baratos
`Behavior/AgentBehaviorQualityGate`→`Gates/` · `Mcp/OpenBrainMcpInput`→`Envelope/`. Domain/Slo/Surface/Procedural: KEEP.

## 4. Grafo one-way (alvo)

```
ArchitectureGovernance (linter — lê tudo, ninguém o importa)  [0 inbound hoje — verificado]
        ▼ lê
Kernel runtime (Decision·Pipeline·Gates·Provider·Envelope·Repair·Domain·Slo·Surface·Procedural)
        ▼ escreve via record*()
Kernel/Evidence (ledger+replay+projeções) ← só VOs + Failure (allowlist)
        ▼
Kernel/Failure (base; importa nada acima)
```
Enforcement: os 2 checks novos + `ap*_kernel_no_linter_import`.

## 5. Teste do patamar
`ArchitectureGovernance` não faz nada novo — passa no teste INVERSO: o Kernel só vira de verdade "runtime de request" removendo os 63% que não são. Promoção do Evidence REPROVA (179 refs, ganho zero). Fusões adicionais no runtime REPROVAM (sub-pastas com callers próprios).

## 6. Ordem de migração
1. **Characterization**: snapshot de `array_keys(architectureScanChecks())` (166) + `complianceReport()`; reflexão da API do ledger (23 métodos + construtor); replay hash-chain verde.
2. **Regras antes do código**: os 3 checks novos entram com o scanner ainda no lugar — o split é vigiado pelo próprio produto.
3. **Split do godfile in-place** (1 família por PR, começando por self_improvement; façade estável; teste de keys verde a cada extração).
4. **Promoção**: `git mv` Architecture→ArchitectureGovernance + Capability/ junto; `class_alias` shims p/ 35 arquivos + testes; shims morrem ≤1 ciclo.
5. **Re-homes internos** com shims ≤1 ciclo.
6. **Fechamento**: matar shims; Kernel ≈15,5k (runtime 10,3k + Evidence 5,2k); ArchitectureGovernance ≈26,5k com maior arquivo ≤1.400; atualizar CONSOLIDATION-MAP.

## 6.5 FATIA 1 EXECUTÁVEL (validada pelo comandante 2026-07-22) — extrair família self_improvement

Alvo: `KernelArchitectureStaticScanner.php` (15.566 LOC). `architectureScanChecks()` L207-422 = array literal `'ap*' => fn (): array => $this->scanX()`, **166 keys** (confirmado). Única pública = `complianceReport()`. Zero `new KernelArchitectureStaticScanner` (container-resolved → construtor novo é seguro, autowire).

**Passo A (SEGURO, F0 intacto): extrair `Scanner/ScanPrimitivesSupport`** — mover os 6 primitivos COMPARTILHADOS (mantendo delegadores 1-linha no scanner p/ não churnar 96+ callers): `scanPhpFilesForForbiddenTokens` (L6694), `missingTokenViolations` (L6730), `fileContents` (L6742), `documentationCorpus` (L15556), `kernelDocumentationCorpus` (L15502), `selfImprovementDomainDocumentationCorpus` (L15542) + props de cache `$kernelDocumentationCorpus` (L9) e `$fileContentsCache` (L14). Como NÃO toca o conjunto/ordem de keys, o F0 (hash ordenado) passa SEM mudança. ~200 LOC no support.

**Passo B: extrair `Scanner/SelfImprovementAudit`** — mover VERBATIM os 21 métodos `scan*` da família (keys ap41-53, ap69-71, ap98, ap115-116, ap123, ap131; def L1228→L10973, 1.347 LOC), reescrevendo `$this->kernelDocumentationCorpus()`→`$this->primitives->kernelDocumentationCorpus()` e idem selfImprovementDomain. Expor `checks(): array` com as 21 entradas. Construtor `(ScanPrimitivesSupport $primitives)`. No scanner: deletar as 21 entradas inline + `return [ ...145... ] + $this->selfImprovementAudit->checks();`.

**F0 (trap #1 = ORDEM):** `KernelTriadF0CharacterizationTest.php` L44 hasheia `json_encode($keys)` EM ORDEM + assertSame first-3/last-3. Merge via `+` joga as 21 keys pro fim → hash ordenado quebra mesmo com SET idêntico. FIX no mesmo commit: trocar L44 p/ hash de **conjunto ordenado** (`sort()` antes do sha256) — mudança de characterization deliberada (count+set continuam congelados; casa com a intenção "conjunto de keys" do §3.1; destrava todas as próximas famílias). Manter assertCount(166) + o loop `valid=true/violations=[]` (re-prova cada check).

**Consumidores externos das ap-keys por NOME** (`AtlasAiArchitectureValidationService`, `AtlasAiArchitectureValidateCommand`): esta fatia NÃO renomeia nem move arquivo → key-strings e class-name intactos. Só o git-mv p/ ArchitectureGovernance/ (fatia futura) toca path — aí entram os 4 guards fail-open (§EMENDAS).

**EXECUÇÃO SEGURA (anti-colisão-Sol):** cirurgia por SCRIPT (line-ranges + brace-match), provada contra CÓPIA do scanner (F0 verde na cópia), depois aplicada ao vivo + `git add -- <arquivos>` + commit ATÔMICO (minimizar janela; rodar F0 com DB sqlite :memory:). Densidade pós-fatia: scanner ~14.100, SelfImprovementAudit ~1.410 (teto 1.400 — ok marginal), support ~200. Façade só chega a ≤400 após todas as ~13 famílias.

## 7. Riscos
| Risco | Sev | Mitigação |
|---|---|---|
| Scanner se auto-referencia (strings hardcoded `Kernel\...` nas regras) — mover dispara as próprias regras | alta | rg nas strings de token ANTES do passo 4; strings que apontam para alvos escaneados NÃO mudam |
| Check-keys ap* consumidas fora (SelfConstruction/Readiness lê o scanner — 8 arquivos) | alta | keys congeladas por characterization; rename = breaking documentado |
| Construtor do ledger injeta Failure* — refactor "bem-intencionado" quebra 148 writers | alta | teste de reflexão + allowlist impedem regressão silenciosa |
| `AtlasLedgerReplayService` 2.240 > teto | média | fora de escopo (Evidence intocável); candidato futuro com characterization própria |
| 167 testes citam namespaces antigos | média | shims cobrem o ciclo; sweep de imports no fechamento |
