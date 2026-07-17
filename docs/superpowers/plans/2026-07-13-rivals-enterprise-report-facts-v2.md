# Rivals Enterprise Report — Facts v2 (AA-grade)

> **For agentic workers:** execute task-by-task. Checkboxes track progress.

**Goal:** Elevar o relatório enterprise para um patamar Artificial Analysis: um modelo = uma posição; colunas Sem Atlas / Com Atlas / Δ; só fatos medidos; zero indução a erro.

**Architecture:** O builder passa a emitir `model_matrix` preenchido mesmo em `single_model_battery` (face Atlas×modelo). O HTML consome esses fatos canônicos (não recalcula médias traidoras). Uplift unsupported nunca vira “0% de inteligência”.

**Tech Stack:** PHP/Laravel · `EnterpriseReportBuilder` · `EnterpriseReportDashboardHtml` · `EnterpriseReportPresenter` · Chart.js · testes Pest/PHPUnit

**Fatos atuais (baseline 2026-07-13):**
- `uplift_ready=2/5` (HAL +, BFCL −)
- `suites_ok=3` / `missing_data=7`
- `model_matrix.rows=[]` (vazio por design antigo — bug de produto)
- `claim_allowed=false` (mantém)

---

## Escopo

### Dentro (esta obra)
1. JSON canônico: leaderboard + per_suite + facts
2. HTML: ranking + tabela suite×suite + faixa de fatos
3. Markdown/CSV alinhados
4. Testes + `report-enterprise` regen

### Fora (obra seguinte — dados)
- Fechar `bare_runtime_proof_missing` nas 3 famílias unsupported
- Re-rodar baterias LCB/marathon para `missing_data`
- Qualquer claim público

---

## Arquivos

| Arquivo | Papel |
|---------|--------|
| `app/Services/Ai/Rivals/Core/EnterpriseReportBuilder.php` | Emitir matrix/facts |
| `app/Services/Ai/Rivals/Core/EnterpriseReportDashboardHtml.php` | UI AA-grade |
| `app/Services/Ai/Rivals/Core/EnterpriseReportPresenter.php` | MD factual |
| `tests/Unit/Ai/Rivals/EnterpriseReportBuilderTest.php` | Contratos |
| `docs/engineering-knowledge-base/atlas-rivals-phase-a-enterprise-report-v1.md` | Nota de contrato (rows não vazias no single_model) |

---

### Task 1: Builder — model_matrix Atlas face

**Files:** Modify `EnterpriseReportBuilder.php`

- [ ] **Step 1:** Em `single_model_battery`, popular `rows` com 1 entrada:
  - `model_id`, `bare_intelligence`, `atlas_intelligence` (só pares `real_uplift`), `delta`, `pair_coverage`, `bare_suite_count`, `per_suite[]`
- [ ] **Step 2:** `per_suite[]`: cada suite com `bare`, `atlas|null`, `delta|null`, `uplift_status`, `comparable:bool`
- [ ] **Step 3:** Enrich `atlas_uplift.families[]` com `bare_intelligence`, `atlas_intelligence`, `delta_intelligence` quando houver scores nos report rows
- [ ] **Step 4:** Adicionar `facts: { measured: string[], incomplete: string[] }` no report (campo extra permitido pelo schema)
- [ ] **Step 5:** Teste: single_model → `rows` non-empty; `comparable` false quando unsupported

### Task 2: HTML — consumir fatos canônicos

**Files:** Modify `EnterpriseReportDashboardHtml.php`

- [ ] **Step 1:** Preferir `report.model_matrix.rows` / `facts` no payload
- [ ] **Step 2:** Tabela suite×suite Sem | Com | Δ | status (não inventar 0)
- [ ] **Step 3:** Faixa “Fatos medidos” + “Ainda incompleto”
- [ ] **Step 4:** Dual-bar visual por suite (só quando comparable)

### Task 3: Presenter MD

**Files:** Modify `EnterpriseReportPresenter.php`

- [ ] **Step 1:** Seção face modelo: tabela Sem/Com/Δ mesmo em single_model
- [ ] **Step 2:** Listar facts measured/incomplete

### Task 4: Verify

- [ ] `php artisan test --filter=EnterpriseReportBuilderTest`
- [ ] `php artisan atlas:rivals report-enterprise --json`
- [ ] Abrir HTML: 1 linha de modelo; suite table sem 0 falso

---

## Critério de pronto

1. Usuário não consegue ler “Atlas perdeu do bare no ranking” como se fossem dois modelos.
2. Δ e Com Atlas só em pares `real_uplift`.
3. Incomplete explícito (3/5 famílias; 7 suites missing_data).
4. `model_matrix.rows` deixa de ser `[]` no caso single-model.
5. Testes verdes; claim_allowed continua false.
