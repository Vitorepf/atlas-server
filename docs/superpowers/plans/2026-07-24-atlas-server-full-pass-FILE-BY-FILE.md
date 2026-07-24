# Plano EXTREMO — Atlas Server Full-Pass **Arquivo por Arquivo · Linha por Linha**

> **For agentic workers:** este é o plano de **cobertura total**.  
> Não existe arquivo “de fora”. Não existe linha “que a gente olha depois”.  
> O inventário máquina é a lei de cobertura; este documento é o **protocolo de visita**.

| | |
|---|---|
| **Status** | INVENTÁRIO GERADO · execução onda a onda |
| **Repo** | `atlas-server` **somente** |
| **Compreensão** | 100% dos arquivos do corpus · cada linha de cada arquivo de texto sob o crivo das **30 áreas** |
| **Canon de áreas** | `docs/engineering-knowledge-base/atlas-full-pass-hygiene-areas.md` |
| **Plano-mãe (fases)** | `docs/superpowers/plans/2026-07-24-atlas-server-full-pass-refactor-complete.md` |
| **Inventário (lei)** | `docs/evidence/2026-07-24-atlas-server-full-pass/inventory/` |
| **Branch** | local `main` · commits escopados |
| **Feature de produto** | **PROIBIDA** |

---

## 0. Compreensão (o que o operador pediu)

Você pediu:

1. Extremamente detalhado.  
2. **Arquivo por arquivo** — tudo que tem no Atlas Server (corpus selecionado).  
3. **Linha por linha** — nenhuma linha fica sem o crivo das 30 áreas quando o arquivo é processado.  
4. Atuação na lista curta **inteira** (30 itens).

**O que isso significa na prática (honesto e executável):**

| Camada | Significado | Artefato |
|---|---|---|
| **Corpus 100%** | Todo arquivo listado no inventário **deve** ser visitado | `inventory/FILES.jsonl` (13 399 paths) |
| **Arquivo por arquivo** | Cada path recebe ficha de visita + status | schema §4 · receipts por bucket |
| **Linha por linha** | Ao abrir o arquivo, o agente aplica o **crivo L0–L6** em **todas** as linhas (não amostra, não “só o método que achei”) | protocolo §5 |
| **30 áreas** | A visita de um arquivo **não fecha** se só 1–2 áreas foram consideradas | checklist §6 por arquivo |
| **Programa** | Fecha só quando **0** arquivos `pending` no inventário (ou `blocked` com DEBT) | SCOREBOARD global |

**O que isto NÃO é:**

- Não é colar 3M de linhas de código dentro de um único MD (isso seria ilegível e inútil).  
- Não é “ler com os olhos e declarar feito” sem ficha/receipt.  
- Não é reescrever o server em outra linguagem.

**Analogia:** o inventário é o **censo de todos os cidadãos**; este plano é a **lei de revista porta a porta e cômodo a cômodo**; cada linha é um cômodo.

---

## 1. Inventário gerado (fonte de verdade de cobertura)

### 1.1 Totais (geração 2026-07-24)

| Métrica | Valor |
|---|---:|
| **Arquivos no corpus** | **13 399** |
| **LOC textuais contadas** | **3 017 306** |
| **Buckets** | **176** |
| **PHP >2000 LOC** | **21** |
| **Lista completa** | `inventory/FILES.jsonl` + `FILES.csv` |
| **Por bucket** | `inventory/by-bucket/*.md` (176 arquivos) |
| **Godfiles** | `inventory/GODFILES.md` |
| **Manifest** | `inventory/MANIFEST.json` |

### 1.2 Corpus incluído (100% destes roots)

```
app/
tests/
config/
routes/
database/
bin/
scripts/
bootstrap/
resources/
docs/engineering-knowledge-base/
```

**Fora do corpus (proposital):** `vendor/`, `node_modules/`, `.git/`, `storage/` runtime, binários grandes, artefatos de build.  
Se o operador quiser **literalmente todo o disco do repo** (incl. vendor): abrir DEBT `expand-corpus-vendor` — **não** é o default.

### 1.3 Top buckets (ordem de massa)

| LOC | Files | Bucket |
|---:|---:|---|
| 533 066 | 2 804 | `tests/Unit` |
| 490 971 | 1 939 | `tests/Feature` |
| 358 760 | 1 071 | `Ai/SelfConstruction` |
| 345 020 | 1 075 | `docs` |
| 136 837 | 953 | `Console/Commands` |
| 132 932 | 556 | `Ai/Programming` |
| 117 938 | 307 | `Ai/SoftwareCompanyStewardship` |
| 73 380 | 177 | `Services/Engineering` |
| 47 516 | 339 | `Http` |
| 43 893 | 195 | `Ai/Kernel` |
| 43 837 | 41 | `Ai/Holding` |
| 43 278 | 87 | `Ai/AgenticEngineeringOs` |
| 41 196 | 241 | `Ai/AutonomousEvolution` |
| … | … | ver `inventory/BUCKETS.md` (176) |

### 1.4 Regenerar inventário (sempre no start de onda)

```bash
# Re-rodar o gerador se o tree mudou (script embutido no histórico da sessão
# ou re-executar o inventário Python documentado no LEDGER F0).
# Gate de cobertura:
python3 - <<'PY'
import json
from pathlib import Path
pending = 0
total = 0
for line in Path('docs/evidence/2026-07-24-atlas-server-full-pass/inventory/FILES.jsonl').read_text().splitlines():
    if not line.strip():
        continue
    e = json.loads(line)
    total += 1
    if e.get('status','pending') == 'pending':
        pending += 1
print({'total': total, 'pending': pending, 'visited_ratio': (total-pending)/total if total else 0})
PY
```

**Done de cobertura:** `pending == 0`.

---

## 2. Definição pétrea de “visitado”

Um arquivo só deixa de ser `pending` se existir **File Visit Receipt** (§4) com:

1. `path` idêntico ao inventário.  
2. `loc_at_visit` (linhas no momento).  
3. `lines_scanned: all` (**proibido** `sample` / `head` / “só métodos públicos” como visita completa).  
4. As **30** `area_id` com veredito por **este arquivo**.  
5. Ações aplicadas **ou** `no_op` justificado por área.  
6. Prova (commands / golden / rg) se houve edit.  
7. `commit` hash(es) se houve diff.  
8. Próximo estado: `done` | `partial` | `n/a` | `blocked`.

**`n/a` no arquivo** só se o arquivo é não-código e a área não se aplica (ex.: `platform_migrate` em `.md` de glossário) — **ainda assim a área é marcada**.

**`blocked`** = floor operator-present / keep-list / Evidence sagrado — DEBT obrigatório.

---

## 3. Ordem de visita (nunca aleatória)

### 3.1 Prioridade global

```
1. inventory godfiles (PHP >2000) — inventory/GODFILES.md
2. app/Providers + config/atlas*.php + routes/console.php  (wiring)
3. app/Services/Ai/* operate path (SelfConstruction, Aaeos, Kernel, root pipes)
4. restante app/Services/Ai/* por LOC desc
5. Console/Commands + Http
6. restante app/
7. tests/ que cobrem classes já visitadas (espelho)
8. tests/ órfãos / Archive
9. docs/engineering-knowledge-base
10. database/ bin/ scripts/ bootstrap/ resources/
```

### 3.2 Dentro de um bucket

```
1. Ordenar FILES do bucket por LOC desc
2. Visitar 1 arquivo por vez (commit atômico se edit)
3. Atualizar status no FILES.jsonl (ou receipt espelho)
4. Só avançar quando receipt do arquivo atual fechado
```

### 3.3 Parallelismo

- Permitido: **1 agente por arquivo** (paths disjuntos).  
- Proibido: 2 agentes no mesmo path.  
- Blackboard/claim no path antes do edit.

---

## 4. Schema — File Visit Receipt (obrigatório)

Salvar em:

```
docs/evidence/2026-07-24-atlas-server-full-pass/receipts/
  <bucket_safe>/
    <sha1path12>__<basename>.json
```

### 4.1 JSON schema (v1)

```json
{
  "schema": "atlas.server.full_pass.file_visit.v1",
  "path": "app/Services/Ai/AiWorker.php",
  "bucket": "Ai/RootSingles",
  "visited_at": "ISO-8601",
  "agent": "autonomos|codex|claude|grok|human",
  "loc_at_visit": 1962,
  "lines_scanned": "all",
  "line_protocol": "L0-L6",
  "fingerprint": {
    "sha256_pre": "...",
    "sha256_post": "..."
  },
  "areas": {
    "refactor":            { "verdict": "done|partial|n/a|blocked|no_op", "notes": "", "actions": [] },
    "defactor":            { "verdict": "...", "notes": "", "actions": [] },
    "optimize":            { "verdict": "...", "notes": "", "actions": [] },
    "reuse":               { "verdict": "...", "notes": "", "actions": [] },
    "standardize":         { "verdict": "...", "notes": "", "actions": [] },
    "architecture":        { "verdict": "...", "notes": "", "actions": [] },
    "eliminate":           { "verdict": "...", "notes": "", "actions": [] },
    "quarantine":          { "verdict": "...", "notes": "", "actions": [] },
    "density":             { "verdict": "...", "notes": "", "actions": [] },
    "fuse":                { "verdict": "...", "notes": "", "actions": [] },
    "rehome":              { "verdict": "...", "notes": "", "actions": [] },
    "decouple":            { "verdict": "...", "notes": "", "actions": [] },
    "honesty":             { "verdict": "...", "notes": "", "actions": [] },
    "contracts":           { "verdict": "...", "notes": "", "actions": [] },
    "simplify":            { "verdict": "...", "notes": "", "actions": [] },
    "invariants":          { "verdict": "...", "notes": "", "actions": [] },
    "golden":              { "verdict": "...", "notes": "", "actions": [] },
    "gates":               { "verdict": "...", "notes": "", "actions": [] },
    "reliability":         { "verdict": "...", "notes": "", "actions": [] },
    "anti_goodhart":       { "verdict": "...", "notes": "", "actions": [] },
    "navigability":        { "verdict": "...", "notes": "", "actions": [] },
    "dx":                  { "verdict": "...", "notes": "", "actions": [] },
    "docs_map":            { "verdict": "...", "notes": "", "actions": [] },
    "governance":          { "verdict": "...", "notes": "", "actions": [] },
    "unify_pipes":         { "verdict": "...", "notes": "", "actions": [] },
    "world_model":         { "verdict": "...", "notes": "", "actions": [] },
    "surface_std":         { "verdict": "...", "notes": "", "actions": [] },
    "platform_migrate":    { "verdict": "n/a", "notes": "server stays PHP", "actions": [] },
    "operate_vs_legacy":   { "verdict": "...", "notes": "", "actions": [] },
    "agent_qos":           { "verdict": "...", "notes": "", "actions": [] }
  },
  "line_findings": [
    {
      "line_start": 0,
      "line_end": 0,
      "kind": "dead|dup|god|misname|side_effect|secret|todo|complexity|ok",
      "area_ids": ["eliminate"],
      "action": "delete|extract|rename|keep|debt",
      "note": ""
    }
  ],
  "edits": {
    "changed": false,
    "summary": "",
    "commits": []
  },
  "proof": {
    "commands": [],
    "tests": []
  },
  "file_status": "done|partial|blocked|n/a",
  "debts": []
}
```

### 4.2 Receipt mínimo se o arquivo é trivial (ex.: 5 linhas)

Ainda precisa das **30 áreas** (quase todas `no_op`/`n/a` com nota curta) e `lines_scanned: all`.  
**Proibido** receipt sem `areas` completo.

---

## 5. Protocolo **linha por linha** (L0–L6)

Quando o agente **abre** um arquivo de texto do inventário, a leitura é **sequencial do início ao fim**.  
Ferramentas: editor + `read` + (opcional) AST — mas a **cobertura de linhas** deve ser total.

### L0 — Mapa físico

Para **cada linha** `1..N`:

| Pergunta | Anotar se SIM |
|---|---|
| Linha em branco / só comentário de seção? | classificar `ok` ou ruído |
| Código executável? | entra em L1–L6 |
| String / SQL / JSON embutido? | reliability + honesty |
| Comentário `TODO/FIXME/HACK`? | debt ou eliminate |

Não pular blocos “porque parecem gerados” **sem** marcar finding `generated-keep` ou `generated-debt`.

### L1 — Eliminação / quarentena (áreas 7–8, 29)

Por linha / símbolo:

- Código inalcançável após `return`/`throw`?  
- Método/propriedade sem refs **vivas** no corpus?  
- Import não usado?  
- Branch `if (false)` / flag morta?  
- Comentário que documenta API morta?  
- Entry point legado (`atlas:loop` operate)?  

Ação: delete / quarantine / debt — **nunca** prefix-delete de keep-list.

### L2 — Densidade / refator / simplify (áreas 1, 9, 15)

Por função/método e por linha de complexidade:

- Método > ~80–120 linhas? → candidata extract  
- Nesting > 3–4? → simplify  
- Duplicação visual de blocos adjacentes? → reuse/fuse  
- Arquivo > 2000? → density plan no receipt (pode ser multi-commit)  
- God-switch / match monstro? → data-table ou extract  

### L3 — Reuso / fuse / pipes (áreas 4, 10, 25, 26)

Por linha de helper local:

- Já existe trait/kernel store/canônico? → swap  
- Helper idêntico a outro arquivo? → dedup task  
- Segundo ledger / segundo provider registry? → unify_pipes  
- Edge/world-model dead-fed? → honesty + não ativar como vivo  

### L4 — Nomes / contratos / surfaces (áreas 5, 13, 14, 27)

Por identificador na linha:

- Nome mente o papel? → honesty rename (lote)  
- String mágica que deveria ser enum/const? → standardize  
- API pública fora do vocabulário `decide*/pack*/rank*/certify*`? → surface_std  
- Entrypoint duplicado da capability? → defactor/contracts  

### L5 — Arquitetura / rehome / decouple (áreas 2, 6, 11, 12)

Por `use` / FQCN / side-effect na linha:

- Classe na pasta errada / root single? → rehome  
- Policy com I/O? → decouple  
- Twin OS / segunda façade? → defactor  
- Órgão N1–N12 errado? → architecture note  

### L6 — Prova / reliability / anti-Goodhart (áreas 16–20, 30)

Por linha de risco:

- Secret / token / private IP? → reliability (remover)  
- `catch (\Throwable)` swallow? → reliability  
- Assert/invariante faltando em gate? → invariants  
- Teste que só existe para morto? → eliminate no teste  
- “Melhoria” só cosmética sem ganho? → anti_goodhart no_op  

### Regra de fechamento linha-a-linha

```
lines_scanned == all
  ⇔ o agente percorreu 1..loc_at_visit sem pular intervalo
  ⇔ line_findings cobre TODOS os problemas acionáveis encontrados
  ⇔ linhas sem problema NÃO precisam de finding (implícito ok)
```

**Proibido:** `lines_scanned: "hotspots only"` como visita completa.  
Permitido hotspot **depois** da varredura full, como *priorização de edit*.

---

## 6. Checklist das 30 áreas **por arquivo**

Ao fechar o receipt, o agente responde para **este path**:

| # | area_id | Pergunta mínima ao arquivo |
|---:|---|---|
| 1 | `refactor` | Há forma a reorganizar sem mudar comportamento? |
| 2 | `defactor` | Este arquivo é façade gêmea / OS duplicado? |
| 3 | `optimize` | Há custo medido (I/O, hops, alocação) a cortar? |
| 4 | `reuse` | Há duplicata canônica a adotar? |
| 5 | `standardize` | Viola vocabulário/estilo/layout? |
| 6 | `architecture` | Está no owner/órgão certo? |
| 7 | `eliminate` | Há morto neste arquivo ou ele é morto? |
| 8 | `quarantine` | Deveria estar archived/frozen? |
| 9 | `density` | Estoura teto LOC / método? |
| 10 | `fuse` | Deveria ser fundido em host/façade? |
| 11 | `rehome` | Path/namespace errado? |
| 12 | `decouple` | Mistura policy/I/O/projection? |
| 13 | `honesty` | Nomes/status mentem? |
| 14 | `contracts` | Contrato público estável / duplicado? |
| 15 | `simplify` | Lógica excessiva nesta unidade? |
| 16 | `invariants` | Falta assert/keep-list/caps? |
| 17 | `golden` | Precisa characterization antes de editar? |
| 18 | `gates` | Que prova fecha o edit? |
| 19 | `reliability` | Secrets, swallow, fail-open perigoso? |
| 20 | `anti_goodhart` | A ação proposta melhora capability real? |
| 21 | `navigability` | Entraria no CODEMAP? hops ok? |
| 22 | `dx` | Commit/receipt/ledger ok? |
| 23 | `docs_map` | Doc relacionado mente operate-path? |
| 24 | `governance` | Floor? ordem de wave? halt? |
| 25 | `unify_pipes` | Pipe paralelo injustificado? |
| 26 | `world_model` | Graph/edge/dead-fed? |
| 27 | `surface_std` | Command/HTTP/MCP thin e canônico? |
| 28 | `platform_migrate` | Quase sempre `n/a` no server |
| 29 | `operate_vs_legacy` | É operate path ou legado? |
| 30 | `agent_qos` | Afeta medição honesta do loop? |

---

## 7. Protocolo por **tipo** de arquivo

### 7.1 `*.php` em `app/Services/**`

1. Ler **todas** as linhas (L0–L6).  
2. Extrair: namespace, class, public methods, deps (`use`, `app(`, `new `).  
3. Contar LOC; se >2000 → plano density no receipt **antes** de fuse.  
4. `rg -n` do class name no corpus → callers vivos?  
5. Decidir: keep / split / rehome / delete / quarantine.  
6. Se edit: golden se monstro · testes do pacote · pint · commit.  
7. Receipt completo 30 áreas.

### 7.2 `app/Console/Commands/*.php`

- Signature viva? (Schedule, Artisan::call, config, docs)  
- Thin? Lógica no service?  
- operate vs legacy naming  
- density se >800  

### 7.3 `app/Http/**`

- Controller thin?  
- Auth/validation reliability  
- Contracts de payload  

### 7.4 `app/Providers/**` + `config/**`

- Wiring bombs / bind-to-missing  
- class_exists de mortos  
- density de `config/atlas.php` (split se >800/2000)  
- **Ordem:** commits **sozinhos** (anti-colisão)  

### 7.5 `tests/**`

- Espelha SUT vivo?  
- Teste de cadáver → eliminate  
- Monster test >2000 → density split  
- Golden/characterization útil?  
- **Não** usar Feature suite full se wipe-risk  

### 7.6 `docs/engineering-knowledge-base/**`

- Operate-path vs LEGADO  
- Links mortos  
- Contradição com código  
- `docs_map` + `honesty`  

### 7.7 `database/**`

- Migrations **não se apagam** (histórico)  
- Imports pinam services? classificar  
- reliability  

### 7.8 `bin/**` `scripts/**`

- DX, gates, hygiene tooling  
- Secrets  

---

## 8. Fila de execução arquivo-a-arquivo (Waves)

Cada wave processa buckets inteiros até `pending=0` no subconjunto.

| Wave | Buckets (obrigatório esgotar) | Notas |
|---|---|---|
| **W-INV** | regenerar inventory + GODFILES | F0 |
| **W-GOD** | 21 PHP >2000 + monstruos app adjacentes | density first |
| **W-WIRE** | Providers, config, routes | sozinhos |
| **W-AI-0** | `Ai/SelfConstruction` (1071 files) | operate |
| **W-AI-1** | `Ai/Kernel`, RootSingles, `Ai/Aaeos` | spine |
| **W-AI-2** | `Ai/Programming`, Stewardship, AutonomousEvolution | defactor |
| **W-AI-3** | Holding, AgenticEngineeringOs, restante Ai/* | por LOC |
| **W-APP** | Engineering, Http, Models, Jobs, resto app | |
| **W-CMD** | Console/Commands (953) | surface |
| **W-TEST-MIRROR** | tests que referenciam classes já done | |
| **W-TEST-REST** | tests restantes + Archive | eliminate |
| **W-DOCS** | docs ekb | docs_map |
| **W-INFRA** | database, bin, scripts, bootstrap, resources | |
| **W-CLOSE** | pending==0 audit · SCOREBOARD 30 global | |

**Dentro de cada wave:** seguir lista `by-bucket/<bucket>.md` de cima (maior LOC) para baixo.

---

## 9. Atualização do inventário (status)

Após cada receipt:

```bash
# Pseudocódigo obrigatório do agente
# 1. Escrever receipt JSON
# 2. Atualizar a linha correspondente em FILES.jsonl: status=done|partial|blocked|n/a
# 3. Se bucket completo: marcar em LEDGER wave
# 4. Commit: docs(full-pass): visit <path>  OU  refactor(full-pass): <area> <path>
```

Ferramenta recomendada (a criar na F0 se não existir):

```text
php scripts/full-pass-mark-visited.php --path=... --status=done --receipt=...
```

Até existir, edição controlada do JSONL + receipt é válida.

---

## 10. Godfiles atuais (fila W-GOD — lista nominativa)

Fonte: `inventory/GODFILES.md` (re-gerar se tree mudar). Snapshot:

| LOC | Path |
|---:|---|
| 31811 | `tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php` |
| 12678 | `tests/Feature/Console/AtlasAaeosCommandTest.php` |
| 5641 | `tests/Feature/EngineeringHarnessRunnerTest.php` |
| 5400 | `config/atlas.php` |
| 5043 | `tests/Unit/.../AutonomousEvolutionSessionServiceTest.php` |
| 4005 | `tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php` |
| 3896 | `tests/Feature/AtlasMemoryRegistryTest.php` |
| 3597 | `app/.../AutonomousEvolutionSessionService.php` |
| 3420 | `tests/Feature/CaptureTranscriptionRetryTest.php` |
| 3377 | `tests/Feature/Ai/AtlasOpenBrainContextPackServiceTest.php` |
| 3080 | `tests/Feature/Ai/AtlasSelfImprovementRuntimeTest.php` |
| 2976 | `tests/Unit/.../Reliable24hLoopRunnerServiceTest.php` |
| 2694 | `tests/Feature/.../AtlasWorkspaceIntelligenceRuntimeServiceTest.php` |
| 2460 | `tests/Feature/.../ChainIntegrityAuditTest.php` |
| 2416 | `tests/Unit/Ai/ProposalInboxEmitterTest.php` |
| … | **ver lista completa no inventário (21)** |

Cada um = **1+ receipts** (pode exigir vários commits de split; receipt final só quando `lines_scanned:all` no arquivo **resultante** e nos filhos extraídos).

---

## 11. Exemplo de visita (arquivo médio)

**Path:** `app/Services/Ai/ExampleService.php` (ilustrativo)

1. `claim` path  
2. `wc -l` + sha256  
3. Ler linhas 1..N inteiras  
4. L0–L6 → `line_findings`  
5. Preencher 30 áreas (mesmo que 25 sejam `no_op`)  
6. Se dead method L.140–188: delete + rg callers=0  
7. Pint + unit do pacote  
8. Commit `refactor(full-pass): A07-eliminate ExampleService dead method`  
9. Receipt JSON + mark inventory `done`  
10. Release claim  

---

## 12. Exemplo de visita (godfile)

**Path:** `AutonomousEvolutionSessionService.php`

1. Golden characterization **antes** de qualquer extract  
2. Varredura linha 1..3597 completa → mapa de famílias  
3. Plano de split no receipt (lista de sections alvo ≤1500)  
4. Extract lote 1 → commit → re-scan filhos **e** mãe  
5. Repetir até mãe no teto ou floor documentado  
6. Receipt final da mãe + receipts dos filhos  
7. Inventory: mãe `done|partial`, filhos novos **entram no inventário** (re-gerar ou append)

**Lei:** arquivo **novo** criado no split → **nasce `pending`** até ser visitado.

---

## 13. Critério de DONE do programa (não da onda)

```
∀ file ∈ FILES.jsonl:
    status ∈ {done, n/a, blocked}
    e se blocked → DEBT id existe
    e se done|n/a → receipt path existe
∀ receipt:
    lines_scanned == "all"
    len(areas) == 30
anti_goodhart global == pass
gates das zonas editadas == green
pending == 0
```

Até lá: programa **aberto**. Ondas podem fechar com subconjunto, mas o **programa** não.

---

## 14. Mandate Autônomos (file-by-file)

```text
PROGRAM: Atlas Server Full-Pass FILE-BY-FILE LINE-BY-LINE
PLAN: docs/superpowers/plans/2026-07-24-atlas-server-full-pass-FILE-BY-FILE.md
CANON_AREAS: docs/engineering-knowledge-base/atlas-full-pass-hygiene-areas.md
INVENTORY: docs/evidence/2026-07-24-atlas-server-full-pass/inventory/FILES.jsonl
LAW:
  - Every inventory path must be visited
  - Every visit scans ALL lines (L0-L6)
  - Every visit scores ALL 30 areas
  - New files from splits become pending
  - No product features
  - Behavior-preserving default
  - main + scoped commits
  - No prefix delete / keep-lists
  - Floors = blocked + DEBT
DONE_WAVE: zero pending in assigned buckets
DONE_PROGRAM: zero pending in entire FILES.jsonl
```

---

## 15. LEDGER / DEBTS paths

```
docs/evidence/2026-07-24-atlas-server-full-pass/
  LEDGER.md                 # progresso de waves
  DEBTS.md                  # blocked + partial acionáveis
  SCOREBOARD.md             # 30 áreas em escala de programa
  inventory/                # censo
  receipts/                 # 1 JSON por arquivo visitado
```

---

## 16. Halt

- Marcar `done` sem receipt  
- `lines_scanned != all`  
- Áreas < 30 no receipt  
- Criar arquivo no split e não inventariar  
- Suite destrutiva  
- Edit em floor sagrado sem blocked/DEBT  
- Vanity “pass N” sem path visitado  

---

## 17. Relação com o plano de fases

| Doc | Papel |
|---|---|
| `atlas-full-pass-hygiene-areas.md` | **O quê** (30 áreas) |
| `...-refactor-complete.md` | **Como em fases** (F0–F8, W0–W6 lógicos) |
| **Este doc** | **Onde e com que granularidade** (13 399 files · all lines · receipts) |

Os três formam o sistema. Este doc **prevalece** em disputas de cobertura (“será que esse arquivo conta?” → se está no inventário, **conta**).

---

## 18. Números de esforço (honestidade)

| Unidade | Contagem |
|---|---:|
| Arquivos a visitar | 13 399 |
| LOC sob crivo | ~3.02M |
| Buckets | 176 |
| Receipts finais esperados | ≥ 13 399 (+ filhos de split) |
| Áreas por arquivo | 30 |
| Avaliações área-arquivo (ordem) | ~13 399 × 30 ≈ **402 000** células de scoreboard fino |

Por isso a execução é **Autônomos 24/7 + ondas + receipts máquina** — não uma sessão humana única.

---

## 19. Kickoff imediato (ordem)

1. Criar `LEDGER.md` / `DEBTS.md` / `SCOREBOARD.md` / `receipts/`.  
2. Re-confirmar `pending` count = 13399.  
3. Começar **W-GOD** arquivo 1 do `GODFILES.md` **ou** **W-WIRE** se wiring bomb.  
4. Um arquivo → um receipt → atualizar JSONL → commit.  
5. Repetir até `pending=0`.

---

## 20. Changelog

| Data | Nota |
|---|---|
| 2026-07-24 | v1 — plano file-by-file + line-by-line; inventário 13399 files / 3.02M LOC / 176 buckets; protocol L0–L6; receipt schema 30 áreas; waves de esgotamento. |

---

### Frase de bolso

> **Nenhum arquivo do inventário fica de fora. Nenhuma linha fica sem crivo. Nenhuma área das 30 fica sem veredito. Receipt ou não aconteceu.**
