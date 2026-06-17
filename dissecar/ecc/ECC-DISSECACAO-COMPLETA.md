# Dissecação completa — `affaan-m/ECC` ("the agent harness operating system")

> Teardown de código (clone raso `--depth 50`, HEAD `5b173d2`, v2.0.0). Não é AP.
> Objetivo: capturar técnica, rejeitar runtime, mapear convergência/divergência com a tese Atlas.
> Método: 5 sub-agentes de leitura profunda (ecc2/Rust, skills, hooks/segurança, cross-harness, brain/governança) + verificação direta de fatos load-bearing.

---

## 0. O que é ECC (em uma frase honesta)

**Um catálogo de configuração markdown extremamente bem curado (262 skills, 104 rules, 64 agents, 84 commands) + um instalador Node multi-harness + um control-plane Rust alpha (`ecc2`) que supervisiona CLIs de agentes de terceiros.** Marketing diz "operating system"; o código entrega *plumbing de orquestração local-first sem cérebro próprio*. ECC = "Everything Claude Code", autor `affaan-m` (vencedor de hackathon Anthropic), ~25K stars reais (o badge "211.9K stars" do README é vanity/endpoint próprio — ignorar).

**Por que é a dissecação mais relevante até hoje:** ECC é o **peer convergente mais próximo da tese Atlas** que existe no OSS — "camada reutilizável é o produto, harnesses são superfícies de execução intercambiáveis". Valida a tese Atlas **por convergência**, e ao mesmo tempo mostra o **teto da versão doc-only, não-governada, sem evidence ledger** dessa mesma ideia. Não concorre: Atlas substitui esse tipo de produto como produto; o valor aqui é capturar técnica e ver o limite.

---

## 1. O engine real — `ecc2/` (Rust) + npm `ecc-universal`

**Dois artefatos que compartilham marca, não codebase:**

### (A) `ecc2/` → binário `ecc`/`ecc-tui` (Rust, ~28k linhas, 1 crate, ~327 deps lock, perfil release `lto+strip`)
- **É um control-plane operador que supervisiona CLIs de agentes de OUTROS vendors como processos filhos detached.** Zero LLM, zero chamada a API de modelo, zero lógica de agente própria. É: supervisor de processo + store SQLite + TUI ratatui + gerenciador de git-worktree + scheduler cron + um intake HTTP token-auth minúsculo.
- **Como roda um agente** (`manager.rs`): `ecc start --task ... --agent claude` insere linha em `sessions`, opcionalmente cria worktree, e **re-exec do próprio binário** como processo detached (`setsid()` Unix / `DETACHED_PROCESS` Windows) → subcomando oculto `run-session` constrói o comando real do harness e faz `capture_command_output` com stdout/stderr piped, streamando cada linha para a tabela `session_output` via thread DB-writer dedicada.
- **Comando do harness é hard-coded por vendor**: Claude → `claude --print --name ecc-<id> [...]`; Codex → `codex exec --skip-git-repo-check --sandbox workspace-write [...]`; OpenCode → `opencode run`; Gemini → `gemini -p -m`. Resto cai num runner genérico configurado por TOML.
- **Impersona launch nativo** injetando env: `CLAUDE_SESSION_ID`, `CLAUDE_PROJECT_DIR`, `CLAUDE_CODE_ENTRYPOINT=cli`, `CLAUDE_PLUGIN_ROOT`.
- **Store local SQLite** em `~/.claude/ecc2.db` (rusqlite bundled), 14 tabelas: `sessions`, `tool_log` (com `risk_score`, `file_events_json`), `messages` (handoffs inter-sessão), `session_output`, `session_board` (kanban), `decision_log`, `context_graph_entities/_relations/_observations` (knowledge graph local), `pending_worktree_queue`, `scheduled_tasks` (cron), `remote_dispatch_requests`, `conflict_incidents`, `daemon_activity`. Migração idempotente hand-rolled via `has_column` PRAGMA.
- **Daemon** (`ecc daemon`): loop a cada 30s — ressuscita sessões mortas (`kill(pid,0)`), enforce heartbeat, roda cron due, dreno fila remota, máquina de estado de backlog-coordination com cooloff de saturação crônica. Auto-merge worktree e auto-dispatch **default OFF**.
- **Risk scoring** (`observability/mod.rs`): heurística determinística de keyword (não-ML). base por tool (bash 0.20) + secret-files (`.env`/`id_rsa`/`.pem` +0.25) + blast-radius (`rm -rf /` +0.35) + irreversibilidade (`drop table` +0.45), thresholds Review 0.35 / Confirm 0.60 / Block 0.85. **Só carimba `tool_log.risk_score` — NÃO bloqueia agente em runtime. É metadado advisory.**

### (B) `ecc-universal` (npm, root package.json)
- **Bundle de conteúdo + instalador Node. ZERO código compilado, NÃO empacota o binário Rust** (verificado: `files[]` não tem `ecc2/`/`target/`/`bin/`). Deps: só `@iarna/toml`, `ajv`, `sql.js`. Bins são JS puro.
- `install.sh`/`install.ps1` → `npm install` deps → `node scripts/install-apply.js`: **só copia conteúdo markdown** para dirs de config dos harnesses + escreve manifest `install-state`. `merge-json` em vez de clobber. `--dry-run` suportado. Sem escalação de privilégio, sem escrita fora de `$HOME`/projeto.

### Rede / phone-home
- **Binário Rust: SEM telemetria, SEM analytics, SEM chamada a `api.ecc.tools`.** Único egress: webhook Slack/Discord (`ureq`), **disabled by default**. Inbound: `ecc remote serve` (HTTP/1.1 hand-rolled, loopback default, bearer-token — comparado com `!=` não-constant-time, nit menor). Toda string `ecc.tools` no `main.rs` é fixture de teste.

### `ecc-agentshield` (npm) — **VAPORWARE deste repo**
- Não existe no tree. Só referências de string a um repo separado `affaan-m/agentshield` + 1 doc roadmap (`docs/architecture/agentshield-enterprise-research-roadmap.md`) + 1 policy de exemplo. **Nenhum package/source/install backing o nome.** Verificado.

---

## 2. Skill system — 262 módulos markdown

- **Skill = diretório `skills/<name>/` com 1 arquivo obrigatório `SKILL.md`** (YAML frontmatter `name`+`description` + corpo markdown). Verificado: 262 dirs, 262 SKILL.md. Média **521 linhas** — módulos de conhecimento substanciais, não stubs.
- **SEM `skill.schema.json`.** Contrato enforced por linter JS (`scripts/ci/validate-skills.js`): cada subdir tem SKILL.md, frontmatter declara `name`, `description` é scalar inline (não block). Falha estrutural = erro duro; frontmatter = WARN salvo `--strict`.
- **Trigger = a própria `description`.** Não há índice de keyword nem manifesto de triggers separado — o harness (Claude Code) lê descriptions e decide. Convenção de confiabilidade: front-load `TRIGGER when: ... DO NOT TRIGGER when: ...` dentro da própria description (ex.: `blueprint`).
- **Discovery/install = cópia via manifests** (`manifests/install-modules.json`, 17 módulos) para `~/.claude/skills/ecc`, `.cursor/`, `.antigravity/.agent/`, etc.
- **Self-maintenance (a camada meta):** `skill-scout` (dedup antes de criar) → `skill-stocktake` (audit de qualidade via subagent batches) → `skill-comply` (mede adesão REAL via classificação de traces stream-json em 3 níveis de rigor) → `rules-distill` (skills → rules) → `config-gc`/`context-budget`/`workspace-surface-audit` (poda/budget) → `ecc-guide` (lê superfície viva). Provenance em 4 tiers: **curated** (shipa, sem provenance), **learned**, **imported**, **evolved** (`schemas/provenance.schema.json`). Só curated shipa.
- **~30-40 dos 262 são filler**: `continuous-learning` v1 (DEPRECATED, redirect stub), família `ito-*` (4 wrappers finos de 1 API), `motion-*`/`homelab-*`/`scientific-*` (splits de domínio único / wrappers de 1 API). O bulk (~90) são pattern docs de linguagem/framework sólidos-mas-convencionais. Os ~25 genuinamente novos estão em **meta / harness-engineering / verification**.

**Skills standout (a captura real):** `gateguard` (gate DENY→FORCE→ALLOW), `santa-method` (2 reviewers independentes têm que passar), `skill-comply` (mede se skill é REALMENTE seguida), `blueprint` (objetivo→plano multi-sessão cold-executable), `agent-harness-construction`, `gan-style-harness` (gerador-avaliador), `iterative-retrieval`, `ck`/Context-Keeper (memória por-projeto determinística).

---

## 3. Hook / segurança / enforcement rail

- **Registrado em 1 `hooks/hooks.json` (354 linhas)**, schema nativo Claude Code. Eventos: PreToolUse, PreCompact, SessionStart, PostToolUse, PostToolUseFailure, Stop, SessionEnd. Cada hook = bootstrap Node inline `node -e` que resolve o plugin root e chama `run-with-flags.js <id> <script> <profiles>`.
- **Postura fail-open:** runner com catch top-level `process.exit(0)` = qualquer crash libera. Profile gating (`ECC_HOOK_PROFILE=minimal|standard|strict`) + `ECC_DISABLED_HOOKS`.
- **Só 3 hooks REALMENTE bloqueiam (fail-closed):**
  - **`block-no-verify.js`** — exit 2 em `git --no-verify`/`-c core.hooksPath=`. Tokenizer rigoroso, **sem retry-allow**. O único gate que agente não satisfaz só retentando. **O modelo de gate real.**
  - **`config-protection.js`** — exit 2 ao modificar config de linter/formatter existente (fail-closed até em input truncado).
  - **InsAIts** (`insaits-security-monitor.py`) — o ÚNICO blocker real de injeção/secret/anomalia, mas **NÃO está no hooks.json** (opt-in manual, `pip install insa-its` + flag, fail-open). Como shipa: enforça nada.
- **GateGuard (flagship) é friction-only, NÃO security gate:** 100% determinístico (regex + tokenizer shell, sem LLM). No 1º toque de um path/comando: `deny` exigindo 4 fatos (importers, API afetada, schema, instrução do user). **Mas o retry idêntico é liberado — nunca re-checa se os fatos foram apresentados.** `rm -rf /x` → deny 1x → roda igual de novo → allow. Bypass trivial e by-design (+ env kill-switches anunciados na própria mensagem de deny + subagents pulam + `.claude/settings.json` isento).
  - **O que GateGuard faz bem (capturável):** `collectExecutableBodies` + `shell-substitution.js` — BFS sobre `$(...)`/backtick/`(...)`/`{...}` pra achatar todo corpo executável antes de pattern-match (pega `echo y | $(rm -rf x)`). `isDestructiveGit` distingue `--force` vs `--force-with-lease`. Parsing determinístico real — só gateia com fricção, não negação.
- **Continuous-learning / observe rail:** PreToolUse/PostToolUse `*` async → `observe.sh` escreve `observations.jsonl` (project-scoped, FORA de `~/.claude` pra escapar do guard de path sensível). Scrub de secret por regex advisory (perde AWS key/JWT/PEM bare). Observer Haiku background acordado por `SIGUSR1` a cada N obs → vira instincts → skill-evolution.
- **Os dentes de segurança REAIS estão em CI, não no runtime do agente:** `validate-workflow-security.js` (untrusted checkout/`pull_request_target`/`id-token: write`), `scan-supply-chain-iocs.js` (DB hardcoded de centenas de `package@version` maliciosos), `check-unicode-safety.js` (zero-width + bidi-override = vetor de prompt-injection escondido). **Esses 3 são determinísticos, falham o build, e são diretamente portáveis.**
- **Theater encontrado:** `governance-capture.js` — docblock afirma que persiste na tabela `governance_events`; **na real só escreve `process.stderr.write`, nunca toca o state-store, off-by-default, nunca bloqueia.** `quality-gate.js` só loga mesmo em strict.

---

## 4. Cross-harness projection (o diferencial — e o parallel direto da projection Atlas)

**Veredito: híbrido honesto.** Há fonte canônica única (`skills/`, `rules/`, `agents/`, `commands/`, `AGENTS.md`, `.mcp.json`) + engine de projeção real **em install-time** (`scripts/lib/install-targets/` + manifests) que copia+remapeia+merge para o layout nativo de cada harness. MAS:

- **A projeção é copy + rename + JSON-merge**, com só ~2 transforms de conteúdo reais. Sem IR de templating rico.
- **As dot-dirs por-harness no repo (`.codex/`, `.cursor/`, `.gemini/`, `.agents/skills/`...) são artefatos hand-authored que JÁ DRIFTARAM do canônico.** NÃO são geradas. Prova: `skills/deep-research/SKILL.md` (159 linhas, tem `origin: ECC` + warning "Drift-prone") difere por md5 da cópia `.agents/skills/deep-research/SKILL.md` (154 linhas). `.codex/agents/reviewer.toml` totalmente divergente de `agents/code-reviewer.md`.
- **CI NÃO policia paridade canônico↔projeção.** Validators checam só estrutura/frontmatter de cada dir; nenhum compara cópias. Drift estruturalmente impune. A regra do doc ("se uma mudança exige editar 3 cópias, a fonte está no lugar errado") é aspiracional, não enforced.

**4 técnicas de normalização (em copy-time):**
1. Flatten de rule + frontmatter por-harness (`rules/python/testing.md` → `.cursor/rules/python-testing.md` MDC com `globs`/`alwaysApply`). Programático só no mangle de filename.
2. Rename de dir hard-coded por target (Antigravity: `commands/`→`.antigravity/workflows/`).
3. **MCP shape JSON↔TOML** (`scripts/codex/merge-mcp-config.js`) — o transform mais battle-tested: converte `.mcp.json` (`mcpServers:{command,args,url}`) → Codex `config.toml` `[mcp_servers.*]`, documenta o gap (Codex TOML é stdio-only, nunca emite `url`), **add-only com drift-detection + self-repair** (`RETIRED_INVALID_URL_SERVERS` conserta config quebrado por versão antiga do próprio ECC).
4. Remap de tool-name no frontmatter de agente (`gemini-adapt-agents.js`: `Read`→`read_file`, `Bash`→`run_shell_command`, strip `color:`).

**Matriz de cobertura honesta** (taxonomia 4-estados auto-reportada: Native/Adapter-backed/Instruction-backed/Reference-only): **2 fundos (Claude, Cursor/OpenCode), ~2 thin-real (Codex, Copilot/Kiro), ~4 stub/instruction/install-shell (Gemini=1 arquivo, Qwen=1, Zed=1 settings, Trae/CodeBuddy=só shell de install).** "Works across 7+ harnesses" = a máquina de install consegue colocar arquivos em todos, mas paridade é rasa pra maioria.

**Lição cautelar pra projection Atlas:** ECC construiu bem o *engine* de projeção (manifests + adapters + merge), mas deixou as *fontes* por-harness serem hand-authored e nunca colocou gate de paridade no CI → drift mensurável. **Se Atlas capturar o padrão, a peça que falta é uma asserção de paridade no CI (hash canônico == hash projetado, módulo transforms declarados).** Atlas já tem isso melhor: projeção regenerada do registry canônico (`atlas memory projection`).

---

## 5. Operator-brain / governança / filosofia

- **Tese: "Configuração é o produto, e configuração compõe (compounds)."** O diferencial entre sessão produtiva e desperdiçada não é o modelo — é o harness: skills, subagents, hooks, rules, MCP hygiene, context-budget, workflows reutilizáveis. Problema que diz resolver: **context rot + tokens desperdiçados.**
- **5 moves:** skills-first (commands = legacy compat), routing por modelo cheapest-sufficient (Haiku/Sonnet/Opus por tarefa), context-budget agressivo (<10 MCP enabled, <80 tools ativos), fases sequenciais com arquivos como bus (Research→Plan→Implement→Review→Verify + `/clear` entre fases), memory-persistence via hooks PreCompact/Stop/SessionStart.
- **"Hermes" (v2.0.0):** Hermes é a *operator shell privada full-life do autor*; **ECC é o substrato público sanitizado destilado dela.** "Hermes is the operator shell. ECC is the reusable system behind it." Fronteira enforced como doutrina: não shipar tokens OAuth, `~/.hermes` raw, memória de workspace pessoal. Cita `NousResearch/hermes-agent` como design pressure (mesma fonte upstream do Hermes do Atlas — convergência de inspiração, não de código). **Detalhe: o autor mantém um repo `affaan-m/JARVIS`** — usa livremente o nome que o CLAUDE.md Atlas bane com tolerância zero. Marcador de quão indisciplinado é o naming do lado ECC.
- **Governança = hierarquia conceitual SEM motor de precedência enforced.** Rules (always-on, autoridade máxima) > Soul (5 princípios) > Agents (scoped) > Skills (durável) > Hooks (mecânico) > Instincts (probabilístico, confidence-gated). A única "precedência" declarada é "system prompt > user message > tool result" — propriedade de *onde você cola texto*, não de um árbitro runtime. **Sem Decision Receipt, sem Evidence Ledger, sem gate de governança.** O único guardrail mecânico repo-wide é o "Prompt Defense Baseline" copy-pasted em CLAUDE.md + todo agent.
- **Agent = 1 arquivo `agents/<name>.md`** com frontmatter (`name`, `description`, `tools`, `model`, `color`) + bloco de defesa + prosa. `model:` codifica a filosofia de custo direto no spec (architect=opus read-only, security-reviewer=sonnet).
- **Memory/homunculus/instincts — a peça MAIS genuinamente construída:** instinct = comportamento atômico aprendido (1 trigger/1 ação) com `confidence` (0.3→0.9), `domain`, `scope` (project|global), `evidence`. Pipeline: hooks capturam → `observations.jsonl` → observer Haiku detecta padrão → instincts → `/evolve` clusteriza em skills/commands/agents → `/promote` sobe project→global quando visto em 2+ projetos a ≥0.8. Project-scoping por hash de git-remote (anti-contaminação cross-project). `instinct-cli.py` = 1.914 linhas com SSRF guard. **MAS ship disabled by default**, e a própria meta-instinct do autor admite: "auto-generated instinct dumps duplicam rules, alargam triggers demais, preservam placeholder" — i.e., o autor SABE que produz ruído sem curadoria e desliga. **Sem gate fail-closed de admissão, sem verificação adversarial antes de virar "core behavior" a 0.9.**

---

## 6. Convergência × divergência com a tese Atlas

### CONVERGE (valida a tese por convergência):
- **"Harness OS, não catálogo"** — stack 5-camadas (Operator Surface → Harness Adapter → Worktree/Session/Queue Runtime → Observability/Eval → Security/Commercial). Mesmo *shape* de cérebro-sobre-engines.
- **Providers/harnesses = engines intercambiáveis, lógica durável fica na camada reutilizável.** "Harnesses are execution surfaces."
- **Modelo de projeção sanitizada** (Hermes privado → ECC público redacted) = idêntico estruturalmente a "memória canônica → projeção provider-safe".
- **Capture-not-cede** — transforma repos externos (Orca, dmux, hermes-agent) em "design pressure → deltas concretas".
- **Compounding learning** (observe → distill → apply → promote) = mesma ideia do flywheel de compounding Atlas.
- **Anti-self-review:** `santa-method` (2 reviewers independentes), `skill-comply` (mede adesão REAL via trace) = mesma filosofia anti-Goodhart / out-of-process guard do Atlas.

### DIVERGE (e é o ponto importante — mostra o teto da versão não-governada):
- **Sem autoridade canônica / sem single source of truth.** O "cérebro" do ECC é markdown espalhado SEM árbitro. Sem registry que diz "decisão X sobrepõe Y", sem Decision Receipt, sem Evidence Ledger. Autoridade = "onde você cola o texto".
- **O catálogo É o cérebro, e é hand-maintained e drifty.** Os próprios self-counts se contradizem entre SOUL/AGENTS/WORKING-CONTEXT (30 vs 64 agents; 135 vs 262 skills). Sem disciplina de regeneração de projeção.
- **Memória fail-open e off-by-default, não governada.** Confidence scores mas sem gate fail-closed, sem verificação adversarial de instinct antes de virar core, ship disabled.
- **"Brain stays out of engine" só recomendado, não enforced** — cópias de harness driftam de fato.
- **É config pessoal destilada em OSS, NÃO self-constructing.** Quando falta workflow, a doutrina é "reconstrua a versão mínima ECC-native À MÃO", não auto-construção autônoma.

**Uma linha:** ECC é o mesmo instinto arquitetural do Atlas (cérebro-sobre-harnesses-intercambiáveis, canônico-então-projetado, memória que compõe) expresso como **catálogo markdown curado com governança em prosa e curadoria human-in-the-loop** — não como runtime governado com precedência enforced, evidence e self-construction. Valida a tese; demonstra o teto da versão doc-only dela.

---

## 7. Técnicas a CAPTURAR (ranked por ROI pro Atlas)

1. **`skill-comply` — prober de adesão REAL** (gera cenários em 3 níveis de rigor, roda `claude -p`, classifica trace stream-json: a skill/rule foi REALMENTE seguida?). É exatamente o gap "self-declared vs resolved-evidence" que o Atlas combate. **Artefato mais transferível do repo inteiro.** Atlas já tem o prober class_exists 1:1; isto estende pra adesão comportamental.
2. **CI trio determinístico** — `scan-supply-chain-iocs` (DB de package@version maliciosos pinados), `check-unicode-safety` (zero-width/bidi anti-injection), `validate-workflow-security`. Portáveis direto, falham build, dentes reais.
3. **`collectExecutableBodies` + `shell-substitution`** — BFS achatando `$(...)`/backtick/subshell/brace antes de detectar comando destrutivo. Primitivo certo pra qualquer guard de bash (pega injeção em subshell).
4. **Add-only merge com drift-detection + self-repair** (`merge-mcp-config.js`) — gold standard pra regenerar arquivo provider sem pisar em edição do operador. Tensão projeção-vs-conteúdo-operador que o Atlas já vive (bloco gerado em CLAUDE.md).
5. **Marker-bounded managed block** (`<!-- BEGIN ECC --> ... <!-- END ECC -->`) com recovery de marker duplicado/ausente — o Atlas já usa padrão similar; ECC tem a versão robusta de recovery.
6. **`description`-as-trigger com `TRIGGER`/`DO NOT TRIGGER` inline** — truque barato de confiabilidade pra qualquer surface de skill/description.
7. **Taxonomia de compliance 4-estados honesta** (Native/Adapter-backed/Instruction-backed/Reference-only) + matriz CI-validada — antídoto ao over-claim "works everywhere". Mesma disciplina anti-over-claim do Atlas.
8. **Deprecate-by-pointer shims** (`legacy-command-shims`: comando velho delega ao skill canônico, sem duplicar) — evita fork-and-drift.
9. **`block-no-verify.js` como modelo de PreToolUse fail-closed** (tokenizer rigoroso, sem retry-allow) — contraste útil ao anti-padrão GateGuard.
10. **DB-writer single-thread atrás de mpsc channel** (serializa writes SQLite, contorna `!Sync`) — padrão Rust limpo se algum runtime local Atlas precisar.

## 8. O que REJEITAR (runtime / anti-padrões)
- **GateGuard deny-then-allow-retry** — parece gate de comando destrutivo, é fricção que qualquer retry derrota. Anti-padrão de segurança.
- **`governance-capture` theater** — documenta persistência que não existe (só stderr). Lição: nunca afirmar persistência em docblock que o código não faz.
- **Cópias de harness hand-maintained sem CI de paridade** — fonte de drift garantido. Atlas regenera do canônico; manter assim.
- **Memória learning fail-open / off-by-default** — sem gate de admissão fail-closed nem verificação adversarial de instinct. Atlas já tem capture-quality-gate + admissão fail-closed; não regredir.
- **Segundo store SQLite que não concorda com o primeiro** — Rust usa `~/.claude/ecc2.db`, JS usa `.claude/ecc/state.db`; `ecc status` (JS) e a TUI (Rust) leem arquivos DIFERENTES por default. Lição anti-fragmentação: single-source de store.
- **`git2` como dead-weight dep** (declarado + puxa vendored-openssl, nunca importado; tudo shell-out pro CLI `git`) — inflar build por dep não-usada.
- **Naming indisciplinado** (repo `JARVIS`, badge "211.9K stars", counts contraditórios) — exatamente o vocabulário/over-claim que o CLAUDE.md Atlas bane.
- **`ecc-agentshield` como produto npm publicado sem source no repo** — marca à frente do código.

---

## 9. Veredito final
Engine compilado (`ecc2`) = supervisor de processo / store / TUI local-first competente e dependency-light — plumbing genuinamente útil (spawn detached, captura streamed, isolamento worktree, cron, backlog-coordination). `ecc-universal` npm = bundle de conteúdo + instalador, não runtime. A camada de marketing ("operating system", "agentshield", "context graph", "shield" de risco) corre à frente do código: agentshield ausente, graph/risk são SQLite + heurística, e as metades JS e Rust nem compartilham path de DB por default. **O valor pro Atlas é técnica (§7) + a prova-por-convergência da tese, e a evidência empírica do teto da versão doc-only não-governada (§6 DIVERGE).**
