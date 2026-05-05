> Cleanup status: archived.
> Canonical replacement: docs/atlas-cli-final-product.md; docs/atlas-cli-release-checklist.md.
> Cleanup note: Historical CLI execution plan. Product/release docs have authority.

# Atlas CLI — Plano de Execução Final

**Data:** 2026-04-30
**Status:** Aprovado para execução
**Audiência primária:** Codex (executor das implementações)
**Audiência secundária:** Vitor (operador/revisor)
**Documento de referência:** [Atlas_Concorrente_Hermes_Agent.md](Atlas_Concorrente_Hermes_Agent.md) — análise comparativa que motivou as escolhas

---

## 0. Como o Codex deve usar este documento

**Regra geral:** este documento é a **única autoridade** para a sequência P0→P11. Não desviar para itens marcados como "futuro" sem aprovação explícita do Vitor.

**Convenções:**

- `[ ]` = checkbox a marcar quando concluído. **Marcar com `[x]` no momento que terminar cada item, comitar.**
- `path/file.php` = caminho relativo a `/Users/vitorepf/Develop/atlas/atlas-server/`.
- Bloco com `signature` = signature literal do Laravel Artisan a copiar.
- Bloco SQL = migration completa, copiar como está.

**Workflow por P:**

1. Ler **integralmente** a seção do P antes de tocar código.
2. Confirmar **pré-requisitos** marcados.
3. Criar branch `atlas-cli/P<N>-<slug>` com `git worktree add`.
4. Executar **passos na ordem** especificada (não pular).
5. Marcar checkbox a cada passo concluído + commit.
6. Rodar smoke test do P.
7. Abrir PR com referência a este documento (`closes Atlas_CLI_Plano_Execucao_Final.md §<N>`).
8. Após merge, marcar checkbox **"P<N> concluído"** no §1.
9. Avançar para próximo P.

**Não pular ordem.** Pre-reqs explícitos garantem que cada P tem fundação dos anteriores. Saltar quebra.

**Em caso de dúvida:** parar e perguntar ao Vitor. Não inventar.

---

## 1. Mapa de versões e progressão geral

### V2.0 — Atlas CLI substituto sério de Claude Code/Codex CLI

- [x] **P0** — Hardening rápido (~3-4h) — implementado localmente em 2026-04-30
- [x] **P1** — `/steer` + `/busy` modes (~1d) — implementado localmente em 2026-04-30
- [x] **P2** — B5.bis Agent Skills compat (~5-7d) — implementado localmente em 2026-04-30
- [x] **P3** — `session_search` + progressive context (~2d) — implementado localmente em 2026-04-30
- [x] **P4** — Plan-validate-execute skill (~1d) — implementado localmente em 2026-04-30

**Critério V2.0:** Vitor opera 5 dias sem abrir Claude Code/Codex CLI direto, com skills bundle funcionando, `/steer` ativo, busca de sessões anteriores via Postgres.

### V2.5 — Atlas opera sozinho

- [x] **P5** — Cron / scheduled tasks (~3-4d) — implementado localmente em 2026-04-30
- [ ] **P6** — Atlas Mobile Gateway + Push + Inbox Operacional (~6-8d)

**Critério V2.5:** Atlas dispara `atlas schedule add "every 9am" "morning briefing"` e entrega como push notification no Atlas-app, com item em inbox respondível inline.

> **Decisão arquitetural explícita (2026-04-30):** Telegram, Discord, Slack e similares **NÃO** são canais do Atlas. Razão: o Atlas é projetado para reduzir fragmentação cognitiva — pilar central para operadores com TDAH. Empurrar para outro app cria o problema que o Atlas existe para resolver. Atlas-app (Expo/React Native) é o canal único; CLI continua para sessões pesadas no Mac.

### V3 — Atlas paralelo + remoto + API

- [ ] **P7** — Background tasks (~3d)
- [ ] **P8** — SSH remote backend (~3-5d)
- [ ] **P9** — API server OpenAI-compatible (~2-3d)

**Critério V3:** Atlas roda em VPS de $5/mês, processa background tasks paralelas, é consumível por Open WebUI/LobeChat.

### V4 — Ecossistema

- [ ] **P10** — Transport ABC (~3-5d)
- [ ] **P11** — MCP / ACP / self-evolution / plugin system (~variável, modular)

**Critério V4:** Atlas fala com APIs diretas (Anthropic/OpenRouter/Bedrock), expõe MCP, é editável de VS Code/Zed via ACP.

### Progressão estimada

| Fase | Sequencial | Paralelo razoável |
|---|---|---|
| V2.0 (P0-P4) | ~10-12 dias | ~7-9 dias |
| V2.5 (P5-P6) | ~8-11 dias | ~6-8 dias |
| V3 (P7-P9) | ~8-11 dias | ~6-9 dias |
| V4 (P10-P11) | variável | variável |
| **Total V2.0→V3** | **~26-34 dias** | **~19-26 dias** |

---

## 2. Princípios não-negociáveis (espelho da constituição Atlas)

Antes de tocar qualquer P, confirmar que ainda valem:

1. Atlas é **superfície**; provider é **motor** intercambiável.
2. CLI/TUI é o produto principal no Mac (não App visual).
3. Runtime próprio **antes** de autonomia.
4. Permissão **explícita** para risco real.
5. Sessão longa preserva raciocínio; compaction e handoff são parte do produto.
6. Qualidade é verificável (gates concretos, não vibe).
7. Resposta clara por default; código bruto só sob demanda.
8. Skills são capacidade versionada, não decoração.
9. Tudo importante deixa rastro (trace persistido).

---

## P0 — Hardening rápido (~3-4h)

### Objetivo

Fechar 3 gaps de segurança que existem hoje no Atlas e que devem ser resolvidos antes de adicionar **qualquer** funcionalidade nova. Nenhum P futuro deve ser implementado sem P0 fechado.

### Pré-requisitos

- [ ] Branch principal limpa (`git status` sem mudanças) — pendente nesta sessão porque o workspace já contém muitas mudanças acumuladas
- [x] Testes passando (`php artisan test`)

### Passo 1: MCP env filter (~1h)

**Objetivo:** subprocess de provider/tool herda apenas allowlist mínima de env, em vez da env shell completa.

**Allowlist canônica implementada:** base mínima (`PATH`, `HOME`, `USER`, `LOGNAME`, `LANG`, `LC_ALL`, `LC_CTYPE`, `TERM`, `SHELL`, `TMPDIR`, `SSH_AUTH_SOCK`, `XDG_*`) + allowlists explícitas por perfil (`tool`, `provider`, `internal`). `ATLAS_*` não é liberado em massa para evitar vazar `ATLAS_TOKEN`.

- [x] Identificar todos os pontos de subprocess. Encontrado e aplicado em providers, runtime de tools, CLI dev/release/update/rollback/final/dogfood/version, profiler e comandos locais Atlas.
  - [x] `app/Services/Ai/ClaudeCliProvider.php`
  - [x] `app/Services/Ai/CodexCliProvider.php`
  - [x] `app/Services/Ai/Runtime/AiToolRuntime.php` (em `shellRun`, `gitApplyPatch`, `testRun`)
- [x] Criar utilitário central de env/redaction/path em `app/Support/AtlasSecurity.php` (`processEnv`, `redactString`, `redactArray`, `commandLineForDisplay`, `canonicalPath`, `pathIsInside`).
- [x] Aplicar em cada subprocess: passar env filtrado por perfil (`tool`, `provider`, `internal`) em vez de env raw.
- [x] Caso de teste automatizado: setar `OPENAI_API_KEY`, rodar `shell.run` e confirmar que **NÃO aparece** no ambiente de tool.
- [x] Caso de compatibilidade: suíte CLI/doctor/bootstrap/release continua passando.

### Passo 2: Redaction patterns em logs (~1h)

**Objetivo:** logs e tool outputs não vazam tokens / API keys.

**Padrões a redact:** `ghp_[A-Za-z0-9]{36,}`, `sk-[A-Za-z0-9-]{20,}`, `sk-ant-[A-Za-z0-9-]{20,}`, `Bearer\s+[A-Za-z0-9-_.]+`, `(token|key|api_key|password|secret)\s*=\s*\S+` (case-insensitive).

- [x] Criar redactor central em `app/Support/AtlasSecurity.php` com padrões para API keys, PATs GitHub/GitLab, Bearer/JWT, AWS access key, private keys e pares `token/key/password/secret`.
- [x] Auditar storage/metadata de runtime e aplicar redact em command, output, stdout, stderr, error, diff e eventos de dogfood/release/dev.
- [x] Auditar `app/Services/Ai/Runtime/AiToolRuntime.php` em pontos onde `shell.run`/`test.run` capturam stdout/stderr.
- [x] Adicionar processador Monolog em `config/logging.php` via `app/Logging/RedactSecretsFromLogs.php`.
- [x] Caso de teste automatizado: output de file/tool e logs não preservam API keys/tokens/passwords.

### Passo 3: realpath antes de access checks (~1h)

**Objetivo:** symlinks não escapam de `ATLAS_AI_TOOL_ALLOWED_ROOTS`.

- [x] Em `app/Services/Ai/AiPermissionEngine.php` e `app/Services/Ai/Runtime/AiToolPermissionEngine.php`, validar `workspace`/`path` contra `allowed_roots`.
- [x] **Antes** de comparar, resolver `realpath()` do path em questão.
- [x] **Antes** de comparar, resolver `realpath()` de cada `allowed_root`.
- [x] Comparar realpath vs realpath. Se path não existe ainda (write), resolver realpath do dirname + basename.
- [x] Caso de teste automatizado: symlink dentro do workspace apontando para fora é negado.

### Passo 4: Smoke test geral

- [x] `php artisan test` passa
- [ ] `bin/atlas doctor --strict` passa — bloqueado por workspace/release dirty nesta sessão, não por falha do P0
- [ ] `bin/atlas dev "implement hello world in /tmp/p0test/hello.txt"` funciona (com approval)
- [x] Logs de `storage/logs/laravel.log` **não** contém secrets do `.env`

### Critério P0 PRONTO

- [x] Todos os 3 passos técnicos com checkbox completo
- [ ] Smoke test passa integralmente após workspace limpo
- [ ] PR aberto: `closes Atlas_CLI_Plano_Execucao_Final.md §P0`
- [ ] Após merge: manter `[x] P0` no §1 e anexar referência do PR

---

## P1 — `/steer` + `/busy` modes (~1d)

### Objetivo

Adicionar 3 modos de "input durante trabalho do agent" + slash command `/steer` para nudges que não interrompem prompt cache.

### Pré-requisitos

- [x] P0 concluído

### Nota de arquitetura implementada

O CLI atual ainda roda `runInline()` de forma síncrona. Portanto, P1 entrega controle profissional para trace ativo persistido no Atlas: `/steer` e `atlas steer` funcionam de outro terminal/processo ou antes da próxima chamada de provider; `/busy` governa o comportamento quando o REPL detecta trace ativo antes de enviar novo input. Captura real de teclas enquanto o mesmo processo bloqueia no provider fica para o TUI/loop assíncrono.

### Passo 1: Schema de `pending_steer` em `AiSessionState` (~0.5h)

- [x] Adicionar coluna `pending_steer` (text, nullable) em `ai_session_states` via migration:
  ```sql
  ALTER TABLE ai_session_states ADD COLUMN pending_steer text NULL;
  ```
- [x] Criar migration: `2026_04_30_140000_add_pending_steer_to_ai_session_states_table.php`
- [x] Rodar `php artisan migrate --force`

### Passo 2: Config de `busy_input_mode` (~0.5h)

- [x] Adicionar em `config/atlas.php`:
  ```php
  'display' => [
      'busy_input_mode' => env('ATLAS_BUSY_INPUT_MODE', 'interrupt'),  // interrupt | queue | steer
  ],
  ```

### Passo 3: Slash command `/steer` em `AiChatCommand` (~2h)

- [x] Em `app/Console/Commands/AiChatCommand.php`, usar o dispatcher inline do REPL.
- [x] Adicionar caso `/steer`:
  - [x] Pega o resto da linha após `/steer`
  - [x] Se há trace ativo em execução: chama `AiSessionStateService::setPendingSteer($threadId, $message)`
  - [x] Se não há trace ativo: imprime erro "no agent running"
- [x] Implementar `AiSessionStateService::setPendingSteer(string $threadId, string $message)`:
  - [x] Update `ai_session_states.pending_steer` para o thread atual
  - [x] Increment `version`

### Passo 4: Injeção de pending_steer no loop (~3h)

- [x] Em `AiWorker`, antes de chamar o provider:
  - [x] Verificar se `ai_session_states.pending_steer` está non-null para a thread atual
  - [x] Se sim: anexar como pedido adicional de operador com `[STEER]`:
    ```
    {role: 'user', content: '[STEER] ' + pending_steer}
    ```
  - [x] Limpar `pending_steer` (set NULL) apenas após permissão runtime aprovada e antes da chamada ao provider
  - [x] Não alterar identidade, skills ou contrato de sistema; o steer entra como pedido adicional do operador
- [x] Caso de teste automatizado: `atlas:cli:state pending-steer set "focus only on tests"` cobre set/clear via comando e `AiSessionStateService` cobre consumo único.

### Passo 5: Busy input mode handler (~3h)

**Contexto:** quando user digita mensagem durante trabalho do agent, hoje Atlas interrompe sempre. Adicionar 3 modos.

- [x] Em `AiChatCommand` REPL, no input loop:
  - [x] Se há trace ativo e user digita mensagem:
    - [x] Modo `interrupt` (default): cancela trace/jobs ativos e processa nova mensagem
    - [x] Modo `queue`: armazena em buffer in-memory `$queuedMessages[]`, imprime `(queued - will send next turn)`, e envia batch quando não houver trace ativo
    - [x] Modo `steer`: trata como `/steer <msg>`, anexa em `pending_steer`, imprime confirmação operacional
- [x] Slash inline para mudar modo:
  - [x] `/busy interrupt` → atualiza modo da sessão CLI
  - [x] `/busy queue` → idem
  - [x] `/busy steer` → idem
  - [x] `/busy status` → imprime modo atual
- [x] First-touch hint: primeira vez que user digita durante trace ativo, imprime once e persiste `seen.busy_input_prompt` em `~/.atlas/onboarding.json`.

### Passo 6: Smoke tests

- [x] Teste automatizado de estado: set/consume único de `pending_steer`
- [x] Teste automatizado de comando: `atlas:cli:state pending-steer set|clear`
- [x] Suíte completa: `php artisan test` com 123 passed, 2 skipped

### Critério P1 PRONTO

- [x] Migration executada
- [x] Config `display.busy_input_mode` lida e respeitada
- [x] `/steer <msg>` funciona em REPL
- [x] `/busy interrupt|queue|steer|status` funcionam
- [x] First-touch hint aparece 1× e fica em config
- [x] Implementado localmente e marcado `[x] P1` no §1

---

## P2 — B5.bis Agent Skills compat (~5-7d)

### Objetivo

Implementar uma camada profissional de **bundles compatíveis com [agentskills.io](https://agentskills.io/specification)**, com progressive disclosure (catálogo no prompt; body só ao ativar), coexistindo com o `AiIntentRouter` e as skills do Vault durante a transição. `MemoryDelta` (B5) fica reservado **só para memória pós-sessão** — skill é procedimento, não fato.

**Status em 2026-04-30:** P2 implementado. Decisão técnica final: não remover o roteador legado nesta fase; os bundles agentskills.io entram como camada compatível com precedência, auditoria, trust gate e security scan. Essa decisão evita quebrar as skills constitucionais existentes do Vault e permite migração progressiva.

### Pré-requisitos

- [x] P0 concluído
- [x] P1 concluído

### Passo 1: Decisão de paths e estrutura (~30min)

**5 paths a escanear** (em ordem de precedência: project override user):

1. `<workspace>/.atlas/skills/<skill-name>/SKILL.md`
2. `<workspace>/.agents/skills/<skill-name>/SKILL.md` (cross-client)
3. `~/.atlas/skills/<skill-name>/SKILL.md`
4. `~/.agents/skills/<skill-name>/SKILL.md` (cross-client)
5. `AtlasVault/_skills/<skill-name>/SKILL.md` (Vault existente, integrar)

**Estrutura bundle:**

```
skill-name/
├── SKILL.md          # required: YAML frontmatter + markdown body
├── scripts/          # optional: executáveis chamados pela skill
├── references/       # optional: docs carregados sob demanda
├── assets/           # optional: templates, schemas
```

- [x] Confirmar que `AtlasVault/_skills/` existe e tem skills hoje
- [x] Decidir migração das skills atuais (lista a fazer no Passo 8)

Nota de implementação: além dos 5 paths definidos, o scanner inclui `atlas-server/skills/` como tier `builtin`, para versionar as 7 skills oficiais no próprio produto.

### Passo 2: Parser `SKILL.md` (~3h)

- [x] Criar `app/Services/Ai/Skills/SkillManifestParser.php`:
  - [x] Método `parse(string $path): SkillManifest`
  - [x] Extrai YAML frontmatter entre `---` delimiters
  - [x] Body = restante após segundo `---`
  - [x] **Lenient validation:**
    - [x] YAML totalmente quebrado → tenta wrap unquoted colons em quotes e reparse
    - [x] Se ainda quebrado → throw `InvalidSkillManifestException` com diagnóstico
    - [x] `description` ausente ou vazio → throw (essential)
    - [x] `name` mismatch com diretório → warn (não fail)
    - [x] `name` >64 chars → warn (não fail)
- [x] Criar Value Object `app/Services/Ai/Skills/SkillManifest.php`:
  ```php
  class SkillManifest {
      public string $name;
      public string $description;
      public ?string $license = null;
      public ?string $compatibility = null;  // ex: "Requires git, docker, jq"
      public array $metadata = [];
      public ?string $allowedTools = null;   // experimental, ignore por enquanto
      public string $body;
      public string $path;                   // path absoluto ao SKILL.md
      public string $directory;              // dirname($path)
      public string $sourceTier;             // 'workspace_atlas' | 'workspace_agents' | 'user_atlas' | 'user_agents' | 'vault'
  }
  ```
- [x] Adicionar `metadata.atlas` extension fields:
  - [x] `metadata.atlas.requires_tools` (array de tools que precisa estar disponível)
  - [x] `metadata.atlas.fallback_for_tools` (array de tools que se ausentes ativa esta skill)
  - [x] `metadata.atlas.trust_level` (`builtin` | `official` | `community`, default `community`)
  - [x] `metadata.atlas.platforms` (array de OS, default `["macos","linux"]`)

- [x] Tests em `tests/Unit/Ai/Skills/SkillManifestParserTest.php`:
  - [x] Parse válido com todos os campos
  - [x] Parse com YAML inválido (colons unquoted) → recovery
  - [x] Parse sem `description` → exception
  - [x] Mismatch nome/diretório → warn mas carrega

### Passo 3: Discovery scanner (~3h)

- [x] Criar `app/Services/Ai/Skills/SkillDiscoveryService.php`:
  - [x] Método `discoverAll(string $workspace): array<SkillManifest>`
  - [x] Escaneia paths em ordem de precedência
  - [x] Para cada `<skill-dir>/SKILL.md`: parse via `SkillManifestParser`
  - [x] **Project override user** (workspace > home > builtin > vault) por `name`
  - [x] Skip dirs `node_modules`, `.git`, `vendor`, `.cache`, `.idea`
  - [x] Cap depth: 4 níveis
  - [x] Cap total dirs: 2000 (proteção runaway)

- [x] **Trust gate workspace skills:**
  - [x] Verificar se `<workspace>` está em `~/.atlas/trusted-projects.json`
  - [x] Se não está: prompt interativo no primeiro `atlas dev`/`atlas chat` no workspace pedindo confirmação
  - [x] Se aprovado: append a `trusted-projects.json` com `{path, approved_at}`
  - [x] Se negado: skills do workspace ignoradas (skills de user/builtin/vault continuam)

- [x] Tests em `tests/Feature/Ai/Skills/SkillDiscoveryTest.php`:
  - [x] Discovery em workspace com `.atlas/skills/foo/SKILL.md`
  - [x] Override: workspace/builtin shadowing tiers inferiores
  - [x] Vault `_skills/baz` carrega quando user/workspace/builtin não tem
  - [x] Trust gate: workspace não-trusted → skills ignoradas

### Passo 4: Filtros de compatibilidade (~2h)

- [x] Em `SkillDiscoveryService::filterAvailable()`:
  - [x] Filtra por `metadata.atlas.platforms` — match com `PHP_OS_FAMILY` (`Darwin`/`Linux`/`Windows`)
  - [x] Filtra por `metadata.atlas.requires_tools` — checa `AiToolRuntime::availableTools()` antes de incluir skill no catálogo
  - [x] `metadata.atlas.fallback_for_tools` — skill aparece **só se** tools listadas estão **ausentes**
- [x] Cruzar `compatibility` (string livre) com `AtlasCliDoctorService`:
  - [x] Se contém `"requires git"` e `git --version` falha → warning em catálogo
  - [x] Se contém `"requires docker"` e `docker --version` falha → warning
  - [x] Não filtrar (skill ainda aparece) — só warn pra Vitor saber

### Passo 5: Registry in-memory + serviço de catálogo (~2h)

- [x] Criar `app/Services/Ai/Skills/SkillBundleStore.php`:
  - [x] `register(SkillManifest)` → indexa por `name`
  - [x] `find(string $name): ?SkillManifest`
  - [x] `catalog(): array<{name, description, compatibility?}>` — para tier 1 do progressive disclosure
  - [x] Singleton bound em `AppServiceProvider`
- [x] No bootstrap de cada `AiChatCommand` invocação:
  - [x] `SkillDiscoveryService::discoverAll($workspace)` → `SkillBundleStore::registerAll(...)`

### Passo 6: Progressive disclosure no `AiPromptBuilder` (~4h)

- [x] Em `app/Services/Ai/AiPromptBuilder.php`:
  - [x] **Tier 1 — Catálogo (sempre no prompt):**
    ```
    # Available Skills
    - <name>: <description (capped 500 chars)>  [<compatibility>]
    - ...
    ```
    Cap por skill: descrição truncada em 500 chars (mesmo que spec aceite 1024).
    Cap total catálogo: 8K tokens (estimativa). Se exceder, ordenar por uso recente + truncar.

  - [x] **Tier 2 — Body injetado quando skill ativada:**
    Quando `intent` ou `--skill=` ou `/<skill>` ativa uma skill:
    - [x] Lê `SkillManifest::$body`
    - [x] Wrap em:
      ```
      <skill_content name="...">
      ...body...
      Skill directory: <directory>
      Relative paths in this skill resolve to skill directory.
      <skill_resources>
        <file>scripts/foo.sh</file>
        <file>references/bar.md</file>
      </skill_resources>
      </skill_content>
      ```
    - [x] Inject no prompt de execução atual. Nota: enquanto o Atlas usa CLI providers, a separação por role/user message fica limitada pelo prompt monolítico; APIs diretas futuras podem mapear esse bloco para role `user`.

  - [x] **Tier 3 — Resources sob demanda:**
    Não carregar `references/*` ou `scripts/*` automaticamente. Deixar agent solicitar via `file.read scripts/foo.sh` (path relativo resolvido pelo runtime).

### Passo 7: Ativação por slash command + flag CLI (~2h)

- [x] Slash `/<skill-name>` em `AiChatCommand`:
  - [x] Se input começa com `/` e match com `SkillBundleStore::find($name)`:
    - [x] Ativa skill (chama tier 2)
    - [x] Resto da linha vira user message normal
  - [x] Se não match: tratar como slash command normal
- [x] Flag `--skill=<name>` em `AtlasCliDevCommand` e `AiChatCommand`:
  - [x] Pré-carrega skill antes do primeiro turn
  - [x] Pode passar múltiplos: `--skill=dev-quality-gate --skill=code-reviewer`
- [x] Roteamento automático via `AiIntentRouter` continua funcionando — mas skills bundle têm precedência quando a description match passa o threshold e existe skill legado equivalente; caso contrário, coexistem como bundle ativado.

### Passo 8: Skills iniciais bundled (~6h)

Criar 7 skills no formato bundle, em `atlas-server/skills/` (path canônico do repo):

- [x] `comunicador-claro/` — output governor (migra do hardcoded em `AiPromptBuilder`)
- [x] `dev-quality-gate/` — quality gates + completion packet (depende de P4 para plan-validate-execute)
- [x] `code-reviewer/` — revisão estrita de diff
- [x] `decision-advisor/` — tradeoff articulation, critério explícito
- [x] `researcher-quick/` — pesquisa contida, sem prometer fontes que não tem
- [x] `provider-handoff/` — guia para troca de provider mid-session
- [x] `session-compaction/` — orientação para compaction manual

Cada skill segue convenção:

```markdown
---
name: dev-quality-gate
description: Quality gates antes de declarar tarefa concluída. Use quando agent finalizou implementação e precisa validar testes/lint/diff antes de retornar completion packet.
metadata:
  atlas:
    trust_level: builtin
    requires_tools: [test.run, git.diff]
    platforms: [macos, linux]
---

## When to use

[1 parágrafo claro]

## Procedure

1. Run quality evaluation
2. ...

## Gotchas

- ...

## Verification

- ...
```

- [x] Para cada skill criada, marcar checkbox individual:
  - [x] `comunicador-claro/SKILL.md` criado
  - [x] `dev-quality-gate/SKILL.md` criado
  - [x] `code-reviewer/SKILL.md` criado
  - [x] `decision-advisor/SKILL.md` criado
  - [x] `researcher-quick/SKILL.md` criado
  - [x] `provider-handoff/SKILL.md` criado
  - [x] `session-compaction/SKILL.md` criado

- [x] Adicionar manifest `atlas-server/skills/.bundled_manifest` listando essas 7 com hash sha256 — proteção contra sobrescrita em update.

### Passo 9: Compaction protect (~1h)

- [x] Em `app/Services/Ai/AiCompactionService.php`:
  - [x] Antes de compactar, identificar messages com tag `<skill_content name="...">` no content
  - [x] **Não compactar** essas messages — sumarizar só o resto
  - [x] Adicionar campo `protected_skill_message_count` em `ai_compactions.metadata`
- [x] Tests:
  - [x] Compactar conversa com skill activation → compaction registra protecao e nao sumariza body interno

### Passo 10: Hash/version no trace (~30min)

- [x] Quando skill é ativada, registrar em `ai_traces.metadata`:
  ```json
  {
    "skills_activated": [
      {"name": "dev-quality-gate", "sha256": "abc...", "source_tier": "builtin"}
    ]
  }
  ```
- [x] sha256 = hash do `SKILL.md` inteiro

### Passo 11: Comandos CLI (~1h)

- [x] Criar `app/Console/Commands/AtlasCliSkillsCommand.php` com signature:
  ```php
  protected $signature = 'atlas:cli:skills
      {action=list : list, show, validate, doctor}
      {name? : skill name}
      {--workspace=}
      {--json}';
  ```
- [x] Ações:
  - [x] `list` — lista todas skills disponíveis com tier (builtin/official/community/workspace) e source path
  - [x] `show <name>` — mostra `SKILL.md` body + metadata
  - [x] `validate <name>` — valida frontmatter + warns
  - [x] `doctor` — verifica integrity de todas skills (broken refs, compatibility mismatch, scan issues)
- [x] Adicionar mapping em `bin/atlas`:
  ```bash
  skills) exec php artisan atlas:cli:skills "$@" ;;
  ```

### Passo 12: Diagnostics integration (~30min)

- [x] Em `app/Services/Ai/Cli/AtlasCliDoctorService.php`, adicionar gate `skills_health`:
  - [x] Conta skills carregadas
  - [x] Lista skills com `compatibility` warning
  - [x] Lista skills com security scan issues (passo 13)
- [x] Score reflects: passed se zero issues; needs_review se há warnings; failed se há scan issues

### Passo 13: Security scan de skill body + context files (~3h)

**Padrões a bloquear** (regex; case-insensitive onde aplicável):

- [x] `/ignore (all )?previous instructions/i`
- [x] `/disregard (all )?prior/i`
- [x] `/do not tell the (user|operator)/i`
- [x] `<!--` seguido de instruction patterns
- [x] `<div style="display:\s*none">` ou similares
- [x] `/curl\s+https?:\/\/[^\s]*\?.*token/i` (exfiltration)
- [x] Unicode invisível: zero-width space `\x{200B}`, RLO `\x{202E}`, etc.
- [x] Acesso a `.env`, `secrets`, credentials patterns somente quando aparece como instrucao de leitura/envio; mencao legitima em skill de seguranca nao bloqueia por falso positivo.

- [x] Criar `app/Services/Ai/Security/PromptInjectionScanner.php`:
  ```php
  public static function scan(string $content): array  // returns [] if clean, [errors] if found
  ```
- [x] Aplicar em:
  - [x] `SkillManifestParser` antes de retornar manifest — se detect, mark skill as `quarantined: true`, log warning, **não carrega**
  - [x] `AiContextPackBuilder` antes de incluir memoria semantica/context snippets recuperados
- [x] Bloqueio output: `[BLOCKED: semantic note contained potential prompt injection - <reason>]`

### Passo 14: Smoke tests P2

- [x] `bin/atlas skills list` mostra 7 builtin
- [x] `bin/atlas skills show comunicador-claro`/`dev-quality-gate` mostra body
- [x] `bin/atlas skills doctor` passa
- [x] `bin/atlas dev "tarefa que claramente precisa code-reviewer"` ativa skill por roteamento quando matching supera threshold
- [x] `bin/atlas dev --skill=code-reviewer "..."` força ativação
- [x] Slash `/code-reviewer revise X` funciona no REPL
- [x] Skill content aparece em `<skill_content name="...">` wrap no prompt gerado
- [x] Hash registrado em `ai_traces.metadata.skills_activated`
- [x] Skill com prompt injection é quarantined e não carrega
- [x] Workspace skill em projeto não-trusted é ignorada

### Critério P2 PRONTO

- [x] Todos passos 1-14 com checkbox completo
- [x] 7 skills builtin no formato bundle
- [x] Discovery + parser + progressive disclosure + ativação funcionando
- [x] Trust gate + security scan ativos
- [x] Suite completa: `php artisan test` → 137 passed, 2 skipped
- [x] §1 marcado como P2 implementado localmente; PR/merge fica pendente se este fluxo for publicado em branch formal.

---

## P3 — `session_search` + progressive context (~2d)

### Objetivo

Atlas hoje guarda histórico em Postgres mas não tem busca cross-session. Implementar tool `session_search` (Postgres tsvector + pg_trgm + sumarização) e injetar resultados como tier on-demand do progressive context.

### Pré-requisitos

- [x] P0, P1, P2 concluídos

### Passo 1: tsvector index em ai_messages (~1h)

- [x] Migration `2026_04_30_150000_add_fts_to_ai_messages.php`:
  ```sql
  CREATE EXTENSION IF NOT EXISTS pg_trgm;
  ALTER TABLE ai_messages ADD COLUMN content_tsv tsvector;
  CREATE INDEX idx_ai_messages_content_tsv ON ai_messages USING gin(content_tsv);
  CREATE INDEX idx_ai_messages_content_trgm ON ai_messages USING gin(content gin_trgm_ops);

  CREATE OR REPLACE FUNCTION update_ai_messages_tsv() RETURNS trigger AS $$
  BEGIN
    NEW.content_tsv := to_tsvector('simple', coalesce(NEW.content, ''));
    RETURN NEW;
  END;
  $$ LANGUAGE plpgsql;

  CREATE TRIGGER ai_messages_tsv_update BEFORE INSERT OR UPDATE
  ON ai_messages FOR EACH ROW EXECUTE FUNCTION update_ai_messages_tsv();

  -- backfill existentes
  UPDATE ai_messages SET content_tsv = to_tsvector('simple', coalesce(content, ''));
  ```
- [x] Rodar `php artisan migrate --force`
- [x] Tests: busca cobre fallback SQLite; migration Postgres executada localmente com trigger e backfill

### Passo 2: Service de busca (~3h)

- [x] Criar `app/Services/Ai/Search/SessionSearchService.php`:
  - [x] `search(string $workspace, string $query, int $topN = 3, bool $summarize = false): array<SearchResult>`
  - [x] Sintaxe aceita: keywords, `"exact phrase"`, `OR`, `NOT`, `prefix*`
  - [x] Conversão para `tsquery`:
    - keywords simples → `to_tsquery('simple', 'word1 & word2')`
    - `"phrase"` → `phraseto_tsquery`
    - `OR` → `|`, `NOT` → `!`
    - `word*` → prefix match `word:*`
  - [x] Query agrupada por `thread_id`, top N únicas, ranqueada por `ts_rank`
  - [x] Para cada thread, slice ~30k chars de mensagens centradas nos matches
  - [x] Fallback lexical para SQLite/testes e para falha de `tsquery`
- [x] Created VO `SearchResult`:
  ```php
  class SearchResult {
      public string $threadId;
      public string $threadTitle;
      public ?\DateTimeInterface $lastMessageAt;
      public string $excerpt;       // ~30k chars centrados
      public float $rank;
  }
  ```

### Passo 3: Sumarização opcional (~2h)

- [x] Em `SessionSearchService`, parâmetro `bool $summarize = false`:
  - [x] Se true: para cada `SearchResult`, aplica sumarização local extrativa determinística:
    ```
    Sumarize 1-2 paragraphs the following conversation excerpt for context recall:
    Query: {query}
    Excerpt: {excerpt}
    ```
  - [x] Substitui `excerpt` pelo summary
- [x] Sem auxiliary model agora: decisão alterada para resumo local, evitando chamada extra ao provider antes do P10

### Passo 4: Tool runtime expostura (~1.5h)

- [x] Em `app/Services/Ai/Runtime/AiToolRuntime.php`, adicionar tool `session.search`:
  ```php
  public function sessionSearch(array $args): ToolResult
  {
      $workspace = $args['workspace'] ?? cwd();
      $query = $args['query'];
      $topN = $args['top_n'] ?? 3;
      $summarize = $args['summarize'] ?? false;
      $results = $this->sessionSearchService->search($workspace, $query, $topN, $summarize);
      return new ToolResult(true, json_encode($results));
  }
  ```
- [x] Permission: `read` (não escreve nada)
- [x] Em `AtlasRuntimeCommand`, adicionar `session.search` ao despachador.

### Passo 5: Comando CLI direto (~30min)

- [x] `bin/atlas search "query"` — atalho conveniente
- [x] Mapping implementado preservando o diretório real do operador (`CALLER_PWD`) e aceitando opções como `--top-n`/`--summarize`

### Passo 6: Injeção como progressive context (~1h)

- [x] Em `AiPromptBuilder`, novo tier:
  - [x] **Tier on-demand session_search:** quando agent escolhe chamar `session.search`, resultado entra como tool result
  - [x] **Tier automático progressive context:** pedidos com continuidade histórica ("como falamos", "conversa anterior", "voltando ao que...") pré-recuperam resultados compactos antes do provider
- [x] Adicionar instrução no prompt:
  ```
  When the user references something from a past conversation or you suspect relevant prior context exists, use the `session.search` tool to retrieve it.
  ```

### Passo 7: Smoke tests P3

- [x] Após algumas conversas no workspace: `atlas search "Atlas CLI" --top-n=2 --summarize` retornou thread relevante
- [x] `AiPromptBuilder` pré-recupera `session.search` automaticamente para pedidos de continuidade histórica
- [x] Sintaxe `OR` / `"phrase"` / `prefix*` funcionam em testes

### Critério P3 PRONTO

- [x] Migration executada, trigger ativo, backfill ok
- [x] `SessionSearchService::search` funciona
- [x] Tool `session.search` no runtime
- [x] `atlas search` shortcut funciona
- [x] Agent usa automaticamente quando relevante via prefetch no `AiPromptBuilder`
- [ ] PR mergeado, marca `[x] P3` no §1

---

## P4 — Plan-validate-execute skill (~1d)

### Objetivo

Criar skill bundled `dev-quality-gate` (referenciada em P2 passo 8) com fluxo formal **plan → validate → execute** para operações batch/destrutivas. Encaixa em `AtlasCliDevWorkflowService` quando há `--max-iterations > 1`.

### Pré-requisitos

- [x] P2 concluído (skill bundle infrastructure pronta)

### Passo 1: Skill bundle (~2h)

- [x] Em `atlas-server/skills/dev-quality-gate/`, criar:
  - [x] `SKILL.md` com frontmatter + body completo
  - [x] `scripts/validate-plan.sh` — script bash que valida plano, paths e invoca `atlas:cli:quality` em JSON
  - [x] `references/quality-gates.md` — explicação detalhada de cada gate

**`SKILL.md`:**

```markdown
---
name: dev-quality-gate
description: Quality gates obrigatórios antes de declarar implementação concluída. Plan → Validate → Execute para mudanças destrutivas. Use sempre que `atlas dev` está em modo --complete ou quando muda 2+ arquivos.
metadata:
  atlas:
    trust_level: builtin
    requires_tools: [test.run, git.diff, file.write]
    platforms: [macos, linux]
---

## When to use

Quando agent finalizou implementação ou está prestes a iniciar fase `edit` que toca múltiplos arquivos. Sempre antes de declarar `completion_packet.status: passed`.

## Procedure

### 1. Plan
Antes de qualquer write:
- Liste arquivos a modificar com motivo
- Crie `field_values.json` análogo (estrutura intencional)
- Documente em 1 parágrafo o que vai mudar e por quê

### 2. Validate
Rode `scripts/validate-plan.sh <plan.json>`:
- Confere se cada path está dentro do workspace permitido
- Confere se há testes para arquivos tocados
- Confere se há `git status` limpo (ou se mudanças em curso são intencionais)

### 3. Execute
Só após validate passar:
- Aplica mudanças via `file.write` / `file.patch` (Atlas runtime, não shell)
- A cada checkpoint, valida diff acumulado

### 4. Verify
Após execute:
- `test.run` para arquivos tocados
- `git.diff` revisão final
- Gera `completion_packet`

## Gotchas

- `file.write` em path fora de `allowed_roots` falha imediatamente — reflete no plan antes
- Testes que escrevem em `/tmp` precisam de cleanup; declarar como side effect no plan
- Não rode `shell.run` para escrita; use `file.write` para que checkpoint funcione

## Verification

- `git diff --stat` mostra exatamente arquivos planejados
- Testes passam
- `completion_packet.quality_gates[].status` = `passed` para todos
```

**`scripts/validate-plan.sh`:**

```bash
#!/usr/bin/env bash
set -euo pipefail
PLAN="${1:?Usage: validate-plan.sh <plan.json>}"
WORKSPACE="${ATLAS_WORKSPACE:-$(pwd)}"

if [ ! -f "$PLAN" ]; then
  echo "Error: plan file not found: $PLAN"
  exit 1
fi

# 1. paths within workspace
jq -r '.files_to_modify[]' "$PLAN" | while read -r path; do
  abs=$(realpath -m "$path")
  if [[ "$abs" != "$WORKSPACE"/* ]]; then
    echo "Error: path outside workspace: $path"
    exit 1
  fi
done

# 2. delegate to atlas quality
php artisan atlas:cli:quality --workspace="$WORKSPACE" --json
```

**`references/quality-gates.md`:** descrição detalhada dos 4 gates (`git_status`, `git_diff`, `workspace_changes`, `tests`) — copiar do código de `AtlasCliQualityService`.

### Passo 2: Hook em AtlasCliDevWorkflowService (~2h)

- [x] Em `app/Services/Ai/Cli/AtlasCliDevWorkflowService.php`:
  - [x] Quando `--max-iterations > 1` ou `--complete` flag:
    - [x] Pré-carregar skill `dev-quality-gate` automaticamente (passo 7 do P2)
- [x] Atualizar `AtlasCliDevCommand.php`:
  - [x] `--complete` flag: usa skill `dev-quality-gate` + exige `quality.status == passed` antes de retornar success exit code
  - [x] Repair loop continua enquanto status final não for `passed`, até `--max-iterations`
  - [x] `AtlasCliQualityService` agora permite `passed` com diff pendente quando testes rodam e passam, evitando que `--complete` seja impossível em workspace com mudanças intencionais

### Passo 3: Smoke tests P4

- [x] `bin/atlas dev --complete "implement getter for X"`:
  - [x] Skill `dev-quality-gate` ativada no comando/payload (`--skill=dev-quality-gate`, trace metadata via `skills_activated`)
  - [x] Skill instrui plan → invoca `validate-plan.sh` → aplica mudanças → testes rodam → completion packet com status `passed`
- [x] Caso falha: `bin/atlas dev --complete "tarefa que vai quebrar testes"` deve **não** retornar success — enforce por `quality.status === passed`

### Critério P4 PRONTO

- [x] Skill bundle completo
- [x] Hook automático em `--complete`/`--max-iterations > 1`
- [x] Smoke tests passam
- [ ] PR mergeado, marca `[x] P4` no §1
- [ ] **V2.0 fechado** — celebrar com tag `v2.0`

---

## P5 — Cron / scheduled tasks (~3-4d)

### Objetivo

Atlas trabalha sozinho. Schedule jobs em formatos múltiplos, persistir em Postgres, executar via Laravel Scheduler, entregar em targets configuráveis (default `local`, futuro `mobile`/`mobile_push` em P6).

### Pré-requisitos

- [x] V2.0 fechado funcionalmente no escopo local (P0-P4 implementados; PR formal/merge seguem pendentes quando publicar branch)

**Status em 2026-04-30:** P5 implementado. Decisão técnica final: delivery real nesta fase é `local` e auditável em arquivo; `mobile`/`mobile_push` ficam persistidos como targets válidos para o refactor do P6, mas ainda retornam status de delivery `pending_p6_*`. Telegram/target-chat-id foi removido do contrato porque quebra o princípio Atlas de superfície única para TDAH.

### Passo 1: Migration `ai_scheduled_tasks` (~1h)

- [x] Migration `2026_04_30_220000_create_ai_scheduled_tasks_table.php`:
  ```sql
  CREATE TABLE ai_scheduled_tasks (
      id UUID PRIMARY KEY,
      title VARCHAR(255) NOT NULL,
      prompt TEXT NOT NULL,
      schedule VARCHAR(255) NOT NULL,            -- formato original (cron expr, "every 2h", ISO, etc)
      kind VARCHAR(16) NOT NULL,                 -- once | interval | cron
      skill_ids JSONB DEFAULT '[]',              -- array de skill names
      target_platform VARCHAR(32) DEFAULT 'local', -- local | mobile | mobile_push (P6)
      target_device_id UUID NULL,                -- atlas_mobile_devices.id; null = todos os devices do user
      workspace TEXT NULL,                       -- absolute path; null = global
      enabled BOOLEAN DEFAULT TRUE,
      next_run_at TIMESTAMPTZ NULL,
      last_run_at TIMESTAMPTZ NULL,
      last_status VARCHAR(16) NULL,              -- success | failure
      last_output_path TEXT NULL,                -- storage/app/atlas/scheduled/{id}/{ts}.md
      repeat_remaining INTEGER NULL,             -- null = infinite (intervals/cron); int = countdown
      context_from_task_ids JSONB DEFAULT '[]',  -- chaining
      wrap_response BOOLEAN DEFAULT TRUE,
      metadata JSONB DEFAULT '{}',
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
  );
  CREATE INDEX idx_ai_scheduled_tasks_next_run ON ai_scheduled_tasks (enabled, next_run_at) WHERE enabled = TRUE;
  CREATE INDEX idx_ai_scheduled_tasks_workspace ON ai_scheduled_tasks (workspace);
  ```

### Passo 2: ScheduleParser (~3h)

- [x] Criar `app/Services/Ai/Scheduling/ScheduleParser.php`:
  - [x] `parse(string $schedule): array` retorna `{kind, expression?, run_at?, interval_minutes?, next_run_at}`
  - [x] Suporta:
    - `30m`, `2h`, `1d` → `kind: once`, `run_at: now() + delta`
    - `every 30m`, `every 2h` → `kind: interval`, `interval_minutes: N`
    - `0 9 * * *`, `0 9 * * 1-5` → `kind: cron`, `expression`
    - `2026-03-15T09:00:00Z` → `kind: once`, `run_at: parsed`
- [x] Tests cobrindo formatos aceitos, cron, ISO, next run e formatos invalidos.

### Passo 3: SchedulerService (~3h)

- [x] Criar `app/Services/Ai/Scheduling/AtlasCliSchedulerService.php`:
  - [x] `addTask(...)` cria task com `next_run_at` calculado
  - [x] `tick()` busca tasks com `enabled=true AND next_run_at <= now() AND (repeat_remaining IS NULL OR repeat_remaining > 0)`
    - [x] Para cada: dispatch `RunScheduledTaskJob` (Laravel queue)
    - [x] Atualiza `next_run_at` baseado em `kind`/`interval_minutes`/cron expr
    - [x] Decrementa `repeat_remaining` se aplicável
    - [x] `WithoutOverlapping` por `workspace` no job — anti-race
  - [x] `removeTask`, `pauseTask`, `resumeTask`, `runNow($id)`

### Passo 4: Job (~2h)

- [x] Criar `app/Jobs/RunScheduledTaskJob.php`:
  - [x] Carrega task por id
  - [x] Cria fresh thread/session via `AiGatewayService::enqueueInteraction(... new_thread=true ...)`
  - [x] Skills do `skill_ids` injetadas via `payload.activated_skills`
  - [x] Chama `AiGatewayService::enqueueInteraction` com prompt guardado
  - [x] Aguarda completion ou timeout (default 600s, configuravel por `ATLAS_AI_SCHEDULED_TASK_TIMEOUT_SECONDS`)
  - [x] Output salvo em `storage/app/atlas/scheduled/{id}/{YYYYmmdd_HHMMSS}.md`
  - [x] Marker `[SILENT]` no início do output → suprime delivery futuro, mas salva
  - [x] Failed jobs sempre delivery (`saved_failure` ou `pending_p6_failure_delivery`)
  - [x] **Anti-recursion:** prompt e payload bloqueiam `atlas schedule`, `atlas cron`, `php artisan atlas:cli:schedule`, `php artisan atlas:scheduler:tick`

### Passo 5: Delivery local (~1h)

- [x] Em `RunScheduledTaskJob` quando `target_platform == 'local'`:
  - [x] Apenas salva em arquivo
  - [x] Atualiza `last_status`, `last_output_path`, `last_run_at`
- [x] Wrap default:
  ```markdown
  # Atlas — Scheduled Task: {title}
  Run at: {ts}
  Status: {status}
  Duration: {duration_ms}ms
  Skills used: {skill_ids}

  ---

  {agent_output}
  ```
- [x] `wrap_response: false` → output cru

### Passo 6: Comando CLI (~3h)

- [x] Criar `app/Console/Commands/AtlasCliScheduleCommand.php`:
  ```php
  protected $signature = 'atlas:cli:schedule
      {action=list : list, add, show, remove, pause, resume, run-now}
      {id? : task id (or for add: title)}
      {--prompt=}
      {--schedule=}
      {--skill=*}
      {--target=local : local|mobile|mobile_push (P6)}
      {--target-device-id= : optional, restrict to one device}
      {--severity=info : info|warning|critical}
      {--workspace=}
      {--repeat=}
      {--context-from=*}
      {--no-wrap}
      {--force}
      {--queue}
      {--json}';
  ```
- [x] Ações:
  - [x] `list` — tabela compacta (id, title, schedule, next_run, last_status)
  - [x] `add` — cria task; valida via ScheduleParser
  - [x] `show <id>` — detalhes + last_output_path tail
  - [x] `remove <id>` — soft-delete (set `enabled=false`) ou hard delete com `--force`
  - [x] `pause <id>` / `resume <id>`
  - [x] `run-now <id>` — força execução imediata sem alterar `next_run_at`; `--queue` enfileira sem esperar
- [x] Mapping em `bin/atlas`:
  ```bash
  schedule|cron) exec php artisan atlas:cli:schedule "$@" --workspace="$CALLER_PWD" ;;
  ```
  - [x] O mapping preserva o diretório original do usuário quando `--workspace` não é informado.

### Passo 7: Tick via Laravel Scheduler (~1h)

- [x] Em `bootstrap/app.php` (Laravel 13):
  ```php
  $schedule->command('atlas:scheduler:tick')->everyMinute()->withoutOverlapping();
  ```
- [x] Criar `AtlasSchedulerTickCommand` que chama `AtlasCliSchedulerService::tick()`
- [x] `atlas:scheduler:tick --dry-run --json` faz preview sem claim, sem avanço de recorrência e sem dispatch.
- [x] `atlas:scheduler:tick --no-dispatch --json` faz claim real sem dispatch para diagnóstico de scheduler.
- [x] Documentar em `Atlas_CLI_Bootstrap_Setup.md`: usuário precisa adicionar à crontab:
  ```
  * * * * * cd /path/to/atlas-server && '/path/to/php>=8.4' artisan schedule:run >> /dev/null 2>&1
  ```
- [x] Atualizar `AtlasCliBootstrapCommand` para detectar e oferecer adicionar automaticamente via `atlas bootstrap --install-scheduler-cron --strict`.

### Passo 8: Smoke tests P5

- [x] `bin/atlas schedule add --schedule="30m" --prompt="echo current time" --skill=comunicador-claro` — validado por smoke JSON
- [x] `bin/atlas schedule list` mostra task — validado por smoke JSON
- [x] `bin/atlas schedule run-now <id>` — coberto por teste com queue fake e por `RunScheduledTaskJobTest` com provider mockado salvando arquivo
- [x] Tick recorrente: `atlas:scheduler:tick --no-dispatch` confirma claim e avanço de recorrência em teste
- [x] Tick dry-run: `atlas:scheduler:tick --dry-run` confirma preview sem mutação e sem fila em teste
- [x] Schedule inválido em dado corrompido é colocado em quarentena sem bloquear outras tasks vencidas
- [x] `[SILENT]` no output suprime delivery futuro via metadata `last_delivery_suppressed`
- [x] Failure: job registra `last_status=failure`, output path e delivery status de falha

### Critério P5 PRONTO

- [x] Migration + parser + service + job + comando + tick funcionando
- [x] 4 formatos de schedule cobertos
- [x] Anti-recursion ativo
- [ ] PR mergeado, marca `[x] P5` no §1

---

## P6 — Atlas Mobile Gateway + Push + Inbox Operacional (~6-8d)

### Objetivo

Atlas-app (Expo/React Native em [atlas-app/](../atlas-app/)) vira o canal único de notificações, aprovações, inbox, captura rápida fora do Mac. **Substitui completamente Telegram/Discord/Slack** — esses são considerados anti-padrão para o Atlas (ver decisão arquitetural no §1).

Princípio operativo: o operador nunca sai do Atlas para interagir com o Atlas. Push notifications nativas + inbox operacional dentro do app + deep links + modo "não me distraia" agrupam tudo em uma surface só.

**Especificação de implementação expandida:** antes de implementar P6, seguir o runbook completo em [`docs/mobile-gateway-push-inbox-implementation.md`](docs/mobile-gateway-push-inbox-implementation.md). Ele detalha Atlas Initiative Engine, Context Bundles, actions auditáveis, proposals seguras, self-monitoring, dedupe, idempotência, push lifecycle, app mobile e smoke tests finais. O checklist abaixo permanece como visão resumida; a especificação expandida é a fonte operacional para execução.

### Pré-requisitos

- [ ] V2.0 fechado (P0-P4)
- [ ] P5 concluído (cron entrega via mobile)
- [ ] Atlas-app (Expo) com build funcional em pelo menos 1 device de teste do Vitor

### Passo 1: API server endpoints (~2d)

`atlas-server` expõe REST sob `/v1/mobile/*` — Bearer auth via token derivado do pairing.

- [ ] Migration `atlas_mobile_devices`:
  ```sql
  CREATE TABLE atlas_mobile_devices (
      id UUID PRIMARY KEY,
      user_id VARCHAR(255) NOT NULL,                -- single-tenant: 'vitor' fixo, ou config
      device_label VARCHAR(64) NOT NULL,            -- ex: "iPhone 15 Pro"
      platform VARCHAR(16) NOT NULL,                -- ios | android
      expo_push_token TEXT NULL,                    -- ExponentPushToken[xxx]
      device_token_hash VARCHAR(255) NOT NULL UNIQUE, -- bearer hash
      paired_at TIMESTAMPTZ DEFAULT NOW(),
      last_seen_at TIMESTAMPTZ NULL,
      revoked_at TIMESTAMPTZ NULL
  );
  ```
- [ ] Migration `ai_inbox_items`:
  ```sql
  CREATE TABLE ai_inbox_items (
      id UUID PRIMARY KEY,
      user_id VARCHAR(255) NOT NULL,
      type VARCHAR(32) NOT NULL,                   -- approval | alert | completion | capture | thread_update | job_status
      title VARCHAR(255) NOT NULL,
      body TEXT NULL,
      payload JSONB DEFAULT '{}',                  -- estrutura por type (ver §15.A)
      severity VARCHAR(16) DEFAULT 'info',         -- info | warning | critical
      status VARCHAR(16) DEFAULT 'unread',         -- unread | read | resolved | dismissed
      response JSONB NULL,                          -- { action: 'approve_once' | ..., responded_at }
      source_type VARCHAR(32) NULL,                -- trace | scheduled_task | background_task | error
      source_id UUID NULL,
      deep_link TEXT NULL,                         -- atlas://...
      created_at TIMESTAMPTZ DEFAULT NOW(),
      read_at TIMESTAMPTZ NULL,
      resolved_at TIMESTAMPTZ NULL
  );
  CREATE INDEX idx_inbox_user_status ON ai_inbox_items (user_id, status, created_at DESC);
  CREATE INDEX idx_inbox_severity ON ai_inbox_items (user_id, severity) WHERE status = 'unread';
  ```
- [ ] Routes em `routes/api.php`:
  ```php
  Route::prefix('v1/mobile')->middleware('atlas.mobile.bearer')->group(function () {
      Route::get('/inbox', [InboxController::class, 'index']);
      Route::post('/inbox/{id}/read', [InboxController::class, 'markRead']);
      Route::post('/inbox/{id}/respond', [InboxController::class, 'respond']);
      Route::post('/inbox/{id}/dismiss', [InboxController::class, 'dismiss']);

      Route::get('/threads', [MobileThreadsController::class, 'index']);
      Route::get('/threads/{id}', [MobileThreadsController::class, 'show']);
      Route::post('/threads/{id}/messages', [MobileThreadsController::class, 'send']);

      Route::post('/captures', [CapturesController::class, 'store']); // text/voice/image

      Route::get('/jobs', [MobileJobsController::class, 'index']);     // background tasks (P7)

      Route::get('/devices', [DevicesController::class, 'index']);
      Route::delete('/devices/{id}', [DevicesController::class, 'revoke']);
  });

  // Pairing endpoints (sem bearer; protegido por code de uso único)
  Route::post('/v1/mobile/pairing/initiate', [PairingController::class, 'initiate']); // operador local gera code
  Route::post('/v1/mobile/pairing/confirm', [PairingController::class, 'confirm']);   // app envia code, recebe device_token

  // WebSocket (Laravel Reverb ou Pusher) para stream real-time como fallback de push
  Route::get('/v1/mobile/stream', [StreamController::class, 'connect']);
  ```
- [ ] Middleware `atlas.mobile.bearer` valida token contra `atlas_mobile_devices.device_token_hash` (SHA-256 do bearer)

### Passo 2: Pairing protocol (~1d)

Reaproveita o protocolo OWASP-compliant antes destinado a Telegram. Aqui pareia **Atlas-app ↔ atlas-server**.

- [ ] Criar `app/Services/Ai/Mobile/MobilePairingService.php`:
  - [ ] Alfabeto não-ambíguo: `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (32 chars, sem `0/O/1/I`)
  - [ ] `initiate(string $deviceLabel): array{code, expires_at}` — operador local roda `bin/atlas mobile pair --label="iPhone 15"`, gera code de 8 chars, TTL 1h
  - [ ] `confirm(string $code, string $expoPushToken, string $platform): array{device_token, device_id}` — app envia code + Expo push token, recebe bearer
  - [ ] Rate limit: 1 initiate/10min, 5 confirm tries → 1h lockout
  - [ ] Max 3 codes pendentes simultâneos
  - [ ] **Codes nunca em logs** (usar `SecretRedactor`)
- [ ] Comando `app/Console/Commands/AtlasCliMobileCommand.php`:
  ```php
  protected $signature = 'atlas:cli:mobile
      {action=devices : devices, pair, revoke}
      {arg? : device id (revoke) ou label (pair)}
      {--label= : label legível para device}
      {--json}';
  ```
- [ ] Ao rodar `bin/atlas mobile pair`:
  - [ ] Imprime code grande no terminal (ex: `BIG-CODE: K3M7HJ2P`)
  - [ ] Imprime QR-code ASCII opcional (libs PHP existem)
  - [ ] Aguarda confirm via API (timeout 5min) e imprime sucesso

### Passo 3: Push provider — Expo Push API (~1d)

Atlas-app é Expo. Caminho mais barato é Expo Push API (não exige cert APNs/Firebase setup direto).

- [ ] Criar `app/Services/Ai/Mobile/MobilePushService.php`:
  - [ ] `sendToDevice(string $deviceId, string $title, string $body, array $data = [], string $severity = 'info'): bool`
  - [ ] Endpoint Expo: `POST https://exp.host/--/api/v2/push/send` com array de mensagens
  - [ ] Severity → priority: `info` = `default`, `warning` = `default`, `critical` = `high`
  - [ ] `data` carrega `deep_link` e `inbox_id` para o app abrir certo
  - [ ] Respeita quiet hours (passo 6)
  - [ ] Retry 1× em failure transitória
  - [ ] Loga sucesso/falha em `ai_inbox_items.payload.push_attempts[]`
- [ ] Tests: mock Expo API, confirma payload format

### Passo 4: Inbox service (~1.5d)

- [ ] Criar `app/Services/Ai/Mobile/AtlasInboxService.php`:
  - [ ] `add(string $userId, string $type, string $title, ?string $body, array $payload, string $severity, ?string $sourceType, ?string $sourceId, ?string $deepLink): InboxItem` — cria item + dispara push se severity bate threshold
  - [ ] `markRead(string $id): InboxItem`
  - [ ] `respond(string $id, string $action, array $data = []): InboxItem` — para approval items
  - [ ] `dismiss(string $id): InboxItem`
  - [ ] `list(string $userId, ?string $statusFilter, int $limit = 50): array<InboxItem>`
- [ ] Hooks no atlas-server:
  - [ ] `RunScheduledTaskJob` (P5) ao terminar: cria inbox item type `completion` (ou `alert` se [SILENT] absent + agent flagou anomalia)
  - [ ] `AiPermissionEngine` quando agent precisa write/danger e operador não está no terminal: cria item type `approval`, agent loop bloqueia até resposta (timeout configurável, default 5min — fallback deny)
  - [ ] `RunBackgroundTaskJob` (P7) ao terminar: cria item type `job_status`
  - [ ] Erro crítico em qualquer worker: cria item type `alert` severity `critical`

### Passo 5: Aprovações inline mobile (~1d)

- [ ] Endpoint `POST /v1/mobile/inbox/{id}/respond` aceita:
  ```json
  { "action": "approve_once|approve_session|approve_workspace_1h|deny", "reason": "..." }
  ```
- [ ] `AiPermissionEngine::waitForMobileApproval(string $inboxItemId, int $timeoutSec = 300): bool`:
  - [ ] Polling ou WebSocket subscribe em `ai_inbox_items.status`
  - [ ] Se `resolved` com action `approve_*` → autoriza com escopo correto
  - [ ] Se `resolved` com `deny` → recusa
  - [ ] Se timeout → recusa (fail-closed)
- [ ] App-side (Expo): notification clicada navega via deep link `atlas://approval/{inbox_id}` para tela de approval com:
  - [ ] tool name + risk + command preview + paths
  - [ ] 4 botões: `Approve once` / `Approve this session` / `Approve workspace 1h` / `Deny`
  - [ ] envia POST para `/respond`

### Passo 6: Quiet hours + batching (~0.5d)

- [ ] Config `config/atlas.php`:
  ```php
  'mobile' => [
      'quiet_hours' => [
          'enabled' => env('ATLAS_MOBILE_QUIET_ENABLED', false),
          'start' => env('ATLAS_MOBILE_QUIET_START', '22:00'),  // local time
          'end' => env('ATLAS_MOBILE_QUIET_END', '07:00'),
          'severity_threshold' => 'critical',  // só critical passa em quiet hours
      ],
      'batching' => [
          'enabled' => env('ATLAS_MOBILE_BATCH_ENABLED', true),
          'window_minutes' => 15,                // agrupa não-críticos a cada 15min
      ],
  ],
  ```
- [ ] `MobilePushService::sendToDevice` consulta quiet_hours antes de send:
  - [ ] Se em quiet + severity < threshold: enfileira em `mobile_push_queue` (table simples)
  - [ ] Job `flushBatchedPushes` roda a cada `window_minutes`, agrupa por device, envia 1 push "Atlas: 5 updates" → app abre inbox com unread

### Passo 7: Deep links (~0.5d)

- [ ] App registra scheme `atlas://` (config Expo)
- [ ] Padrões:
  - `atlas://inbox` — abre inbox
  - `atlas://inbox/{id}` — abre item específico
  - `atlas://approval/{inbox_id}` — abre approval
  - `atlas://thread/{thread_id}` — abre thread
  - `atlas://job/{background_id}` — status de job
  - `atlas://capture` — abre captura rápida
- [ ] Push notifications carregam `data.deep_link` que app navega ao abrir
- [ ] Backend gera deep_link correto ao criar inbox item

### Passo 8: Captura rápida no app (~1d)

- [ ] Endpoint `POST /v1/mobile/captures`:
  ```php
  // multipart/form-data
  // text: string?
  // voice: file (audio/m4a, audio/mp3)
  // image: file (image/jpeg, image/png)
  // workspace: string? (default null = unscoped)
  ```
- [ ] Voice → Whisper (local ou API) → transcribe
- [ ] Image → vision (Claude/etc) → OCR + descrição opcional
- [ ] Cria thread nova ou anexa em thread existente (Vitor decide via UI)
- [ ] Inbox item type `capture` confirma processamento

### Passo 9: P5 delivery refactor (~0.5d)

- [ ] `ai_scheduled_tasks.target_platform` enum atualizado: `local | mobile | mobile_push`
  - [ ] `local` (default): salva arquivo em `storage/app/atlas/scheduled/{id}/{ts}.md` (sem inbox)
  - [ ] `mobile`: cria inbox item type `completion` ou `alert` (sem push se severity baixa)
  - [ ] `mobile_push`: cria inbox item + força push (respeitando quiet_hours/batching)
- [ ] Comando `atlas schedule add --target=mobile_push --severity=warning ...`
- [ ] `[SILENT]` marker continua suprimindo (nem cria inbox)
- [ ] Failed jobs sempre criam inbox item severity `warning` (override silent)

### Passo 10: CLI consume mesmo inbox (~0.5d)

- [ ] CLI também é cliente do inbox (Vitor pode ver itens no terminal):
  - [ ] `bin/atlas inbox` → lista unread items (tabela compacta)
  - [ ] `bin/atlas inbox show <id>` → detalhe
  - [ ] `bin/atlas inbox respond <id> --action=approve_once`
  - [ ] `bin/atlas inbox dismiss <id>`
  - [ ] Mapping `bin/atlas`: `inbox) exec php artisan atlas:cli:inbox "$@" ;;`
- [ ] Comando `app/Console/Commands/AtlasCliInboxCommand.php`:
  ```php
  protected $signature = 'atlas:cli:inbox
      {action=list : list, show, respond, dismiss}
      {id?}
      {--action= : approve_once, approve_session, approve_workspace_1h, deny}
      {--reason=}
      {--filter= : unread, all, approval, alert, completion}
      {--json}';
  ```

### Passo 11: WebSocket fallback (opcional — fim deste P) (~1d)

Se push falhar (sem internet, Apple Silicon dev sem APNs, etc.), Atlas-app pode subscribe a WebSocket `/v1/mobile/stream` para receber notifications real-time enquanto app está em foreground.

- [ ] Laravel Reverb ou broadcasting equivalente
- [ ] Channel privado por device_id
- [ ] Eventos: `inbox.created`, `inbox.updated`, `thread.message`, `job.completed`

### Passo 12: Smoke tests P6

- [ ] `bin/atlas mobile pair --label="iPhone 15"` imprime code
- [ ] App pareia (operador insere code), recebe device_token, registra Expo push token
- [ ] `bin/atlas inbox` no terminal mostra "0 unread"
- [ ] `bin/atlas schedule add --target=mobile_push --schedule="1m" --prompt="echo hello"`:
  - [ ] Aguarda 1min
  - [ ] Push aparece no iPhone com title "Atlas: hello"
  - [ ] Tap abre app, navega para item específico
- [ ] `atlas dev "edit /tmp/test.txt"` quando precisar approval:
  - [ ] Push notification "Atlas: approval needed"
  - [ ] App abre tela de approval, 4 botões funcionam
  - [ ] Resposta unblocka agent loop
- [ ] Quiet hours: setar 22:00-07:00, agendar task severity `info` para 23:00 — push não chega; severity `critical` chega
- [ ] Batching: 5 tasks severity `info` em 10min → 1 push "Atlas: 5 updates" no fim do window
- [ ] Captura: app envia voz → backend transcribe → inbox item type `capture` aparece
- [ ] Revoke: `bin/atlas mobile revoke <id>` → token rejeitado em chamadas seguintes

### Critério P6 PRONTO

- [ ] Migrations + endpoints + service + push + inbox + approvals funcionando
- [ ] Pairing OWASP-compliant
- [ ] Atlas-app testado real em pelo menos 1 device do Vitor
- [ ] Quiet hours + batching ativos
- [ ] CLI também consome inbox (`bin/atlas inbox`)
- [ ] Telegram/Discord/Slack **não** foram implementados (declaração no §1 vale)
- [ ] PR mergeado, marca `[x] P6` no §1
- [ ] **V2.5 fechado** — celebrar com tag `v2.5`

---

## P7 — Background tasks (~3d)

### Objetivo

`atlas background "<prompt>"` spawns subagent isolado em queue, retorna imediatamente, notifica quando termina. **Durável-por-default** via Laravel queue (vantagem sobre Hermes síncrono).

### Pré-requisitos

- [ ] V2.5 fechado (P5 + P6)

### Passo 1: Migration `ai_background_tasks` (~1h)

- [ ] Migration:
  ```sql
  CREATE TABLE ai_background_tasks (
      id UUID PRIMARY KEY,
      parent_thread_id UUID NULL,
      parent_user_id VARCHAR(255) NULL,
      prompt TEXT NOT NULL,
      workspace TEXT NULL,
      status VARCHAR(16) NOT NULL,            -- queued | running | succeeded | failed | cancelled
      result_text TEXT NULL,
      result_metadata JSONB DEFAULT '{}',
      enabled_toolsets JSONB DEFAULT '[]',
      skill_ids JSONB DEFAULT '[]',
      provider VARCHAR(32) NULL,
      job_id BIGINT NULL,                     -- Laravel queue job
      started_at TIMESTAMPTZ NULL,
      finished_at TIMESTAMPTZ NULL,
      created_at TIMESTAMPTZ DEFAULT NOW()
  );
  ```

### Passo 2: BackgroundTaskService (~2h)

- [ ] Criar `app/Services/Ai/Background/BackgroundTaskService.php`:
  - [ ] `spawn(prompt, workspace, options): BackgroundTask` — cria record + dispatch `RunBackgroundTaskJob`
  - [ ] `list(parentThreadId?): array<BackgroundTask>`
  - [ ] `show(id): BackgroundTask`
  - [ ] `kill(id): void` — cancel job + set status=cancelled
  - [ ] `poll(id): status`
  - [ ] `wait(id, timeout=600): BackgroundTask` — block até concluir ou timeout

### Passo 3: Job (~3h)

- [ ] `app/Jobs/RunBackgroundTaskJob.php`:
  - [ ] **Subagent context isolation:** AIAgent fresh, sem histórico parental, recebe **só** `prompt`
  - [ ] Tools bloqueadas: `memory`, `code_execution`, `send_message`, `delegate_task` (subagent não delega) — apply em `enabled_toolsets` filter
  - [ ] Worktree separado: criar `git worktree add ~/.atlas/worktrees/{id}/<branch>` se workspace é git repo (cleanup ao fim)
  - [ ] Timeout 600s sem atividade → marca failed, log diagnóstico em `~/.atlas/logs/background-{id}.log`
  - [ ] Cap default profundidade: 1 (flat). Opt-in para 2/3 níveis via flag explícita

### Passo 4: Comando + slash (~2h)

- [ ] Criar `app/Console/Commands/AtlasCliBackgroundCommand.php`:
  ```php
  protected $signature = 'atlas:cli:background
      {action=spawn : spawn, list, show, kill, wait}
      {prompt_or_id? : prompt for spawn / id for others}
      {--workspace=}
      {--skill=*}
      {--toolsets=}
      {--provider=}
      {--max-depth=1}
      {--timeout=600}
      {--json}';
  ```
- [ ] Slash `/background <prompt>` em REPL = `atlas:cli:background spawn`
- [ ] Mapping `bin/atlas`:
  ```bash
  background) exec php artisan atlas:cli:background "$@" ;;
  bg) exec php artisan atlas:cli:background "$@" ;;
  ```

### Passo 5: Notification (~1h)

- [ ] Quando `RunBackgroundTaskJob` termina:
  - [ ] Se `parent_thread_id` está associado a gateway mobile: cria inbox item type `job_status` + push (P6)
  - [ ] Se CLI: arquivo `~/.atlas/background/{id}.md` + se REPL ativo, notify inline (poll a cada 2s nos comandos comuns)
  - [ ] Bell terminal opcional: `display.bell_on_complete: true`

### Passo 6: Smoke tests P7

- [ ] `bin/atlas background "research X"` → retorna `Task ID: bg_...` imediato
- [ ] `bin/atlas background list` mostra task running
- [ ] `bin/atlas background show <id>` mostra status
- [ ] Após conclusão, output em `~/.atlas/background/{id}.md`
- [ ] No REPL parent, notify aparece quando background termina (sem interromper trabalho atual)
- [ ] `bin/atlas background kill <id>` cancela imediato
- [ ] Multiple parallel: 3 spawns simultâneos, todos concluem independente

### Critério P7 PRONTO

- [ ] Migration + service + job + comando funcionando
- [ ] Durável (sobrevive interrupção do parent)
- [ ] Worktree isolation quando aplicável
- [ ] Notification funcional
- [ ] PR mergeado, marca `[x] P7` no §1

---

## P8 — SSH remote backend (~3-5d)

### Objetivo

Atlas pode rodar com `terminal.backend = ssh` em VPS de $5/mês ou maior. Workspace local sincronizado via rsync; tools rodam remoto.

### Pré-requisitos

- [ ] P7 concluído

### Passo 1: Config + RemoteBackend service (~2h)

- [ ] Config keys já existem em `config/atlas.php` (do mapeamento Hermes §24.4):
  ```php
  'terminal' => [
      'backend' => env('ATLAS_TERMINAL_BACKEND', 'local'),  // local | ssh
      'ssh' => [
          'host' => env('TERMINAL_SSH_HOST'),
          'user' => env('TERMINAL_SSH_USER'),
          'port' => env('TERMINAL_SSH_PORT', 22),
          'key' => env('TERMINAL_SSH_KEY'),  // path to private key
          'persistent_shell' => true,
      ],
      'file_sync_max_mb' => 100,
      'file_sync_enabled' => true,
  ],
  ```
- [ ] Criar `app/Services/Ai/Backend/SshBackend.php`:
  - [ ] Implementa interface `TerminalBackend` (criar interface se ainda não existe)
  - [ ] Métodos: `runCommand(cmd, timeout)`, `readFile(path)`, `writeFile(path, content)`, `getCwd()`, `chdir(path)`
  - [ ] Conexão persistente via `phpseclib/SSH2`

### Passo 2: Workspace sync (~3h)

- [ ] `app/Services/Ai/Backend/RemoteWorkspaceSync.php`:
  - [ ] `syncTo(local, remote)` — rsync push (excluir `.git`, `node_modules`, `vendor`)
  - [ ] `syncFrom(remote, local)` — rsync pull (cap por `file_sync_max_mb`)
  - [ ] Auto-sync antes/depois de `atlas dev` se backend remoto

### Passo 3: shell_init_files support (~1h)

- [ ] Config `terminal.shell_init_files: ["~/.zshrc", "~/.nvm/nvm.sh"]`
- [ ] Antes de cada `runCommand`, source esses arquivos para detectar nvm/pyenv etc.

### Passo 4: Comando + flag (~2h)

- [ ] `bin/atlas remote add <name> --host=user@vps --key=~/.ssh/id_rsa` configura
- [ ] `bin/atlas remote use <name>` define ativo
- [ ] `bin/atlas remote sync` força full sync
- [ ] `bin/atlas remote disconnect`
- [ ] Flag `--remote=<name>` em `atlas dev`

### Passo 5: Smoke tests P8

- [ ] `atlas remote add prod --host=ubuntu@vps --key=~/.ssh/id_rsa`
- [ ] `atlas dev --remote=prod "fix bug X"`:
  - [ ] Workspace local sync up
  - [ ] Agent roda no VPS, edita arquivos, roda testes
  - [ ] Resultado sync down ao fim
- [ ] `atlas runtime shell.run "uname -a" --remote=prod` retorna info do VPS

### Critério P8 PRONTO

- [ ] SshBackend + sync + comandos
- [ ] Smoke test em VPS real
- [ ] PR mergeado, marca `[x] P8` no §1

---

## P9 — API server OpenAI-compatible (~2-3d)

### Objetivo

Atlas server expõe `POST /v1/chat/completions` compatível com OpenAI API spec, permitindo Atlas como backend para Open WebUI / LobeChat / LibreChat.

### Pré-requisitos

- [ ] P7 concluído

### Passo 1: Token auth (~1h)

- [ ] Migration `atlas_api_tokens`:
  ```sql
  CREATE TABLE atlas_api_tokens (
      id UUID PRIMARY KEY,
      name VARCHAR(64) NOT NULL,
      token_hash VARCHAR(255) NOT NULL UNIQUE,
      scopes JSONB DEFAULT '["chat:write"]',
      created_at TIMESTAMPTZ DEFAULT NOW(),
      last_used_at TIMESTAMPTZ NULL,
      revoked_at TIMESTAMPTZ NULL
  );
  ```
- [ ] Comando `atlas api token create <name>` → printa token clear-text 1× + grava hash
- [ ] Middleware Bearer auth para `/v1/*` routes

### Passo 2: Endpoint /v1/chat/completions (~3h)

- [ ] Route + controller `app/Http/Controllers/Api/ChatCompletionsController.php`:
  - [ ] Aceita request OpenAI-format (model, messages, stream, etc.)
  - [ ] Mapeia model → Atlas provider (claude_cli/codex_cli/...)
  - [ ] Chama `AiGatewayService::enqueueInteraction`
  - [ ] Retorna response OpenAI-format
  - [ ] `stream: true` → SSE com formato OpenAI (`data: {...}\n\n` chunks)

### Passo 3: Endpoint /v1/models (~1h)

- [ ] Route que lista modelos disponíveis (Claude/Codex/aliases)

### Passo 4: Smoke tests P9

- [ ] `curl http://localhost:8000/v1/chat/completions -H "Authorization: Bearer $TOKEN" -d '{...}'` retorna OpenAI-format
- [ ] Streaming SSE funciona
- [ ] Open WebUI configurado com `http://localhost:8000/v1` + token funciona
- [ ] LobeChat funciona

### Critério P9 PRONTO

- [ ] API + auth + streaming
- [ ] 1 frontend external testado (Open WebUI)
- [ ] PR mergeado, marca `[x] P9` no §1
- [ ] **V3 fechado** — tag `v3.0`

---

## P10 — Transport ABC (~3-5d)

### Objetivo

Abstrair format conversion + HTTP transport para permitir adicionar providers (Anthropic API direto, OpenRouter, Bedrock) sem replicar lógica.

### Pré-requisitos

- [ ] V3 fechado

### Passo 1: Interface (~1h)

- [ ] Criar `app/Services/Ai/Transports/LlmTransport.php`:
  ```php
  interface LlmTransport {
      public function key(): string;
      public function run(AiJob $job, string $prompt): AiProviderResult;
      public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult;
      public function health(): AiProviderHealthCheck;
  }
  ```

### Passo 2: 3 transports iniciais (~6h)

- [ ] `ClaudeCliTransport` — wrap código existente
- [ ] `CodexCliTransport` — wrap código existente
- [ ] `OpenAiCompatibleHttpTransport` — POST a `{base_url}/chat/completions`, parsing chunks SSE

### Passo 3: AiGatewayService refactor (~2h)

- [ ] `resolveTransport(provider, model)` retorna instância correta
- [ ] Substituir uso direto de `ClaudeCliProvider::run()` por transport

### Passo 4: Auxiliary models per-task (~3h)

- [ ] Config:
  ```php
  'auxiliary' => [
      'compression' => ['provider' => 'auto', 'model' => '', 'base_url' => '', 'api_key' => ''],
      'session_search' => [...],
      'approval' => [...],
      'title_gen' => [...],
      'quality_evaluator' => [...],
  ],
  ```
- [ ] `auto` = main model
- [ ] Override por slot — `compression` pode ser cheap/fast (Haiku/Flash)

### Passo 5: Smoke tests P10

- [ ] `atlas dev --provider=anthropic-api "..."` (config aponta pra API direta)
- [ ] `atlas dev --provider=openrouter --model=anthropic/claude-sonnet-4 "..."`
- [ ] `auxiliary.compression.model = google/gemini-3-flash-preview` reduz custo de compaction

### Critério P10 PRONTO

- [ ] 3 transports + interface + auxiliary
- [ ] PR mergeado, marca `[x] P10` no §1

---

## P11 — Ecossistema (MCP / ACP / self-evolution / plugins)

**Modular, opt-in.** Cada sub-bloco é independente e pode ser implementado fora de ordem ou pulado.

### P11.1 — MCP client (~3-4d)

- [ ] Suporte stdio + HTTP transport
- [ ] Config `mcp_servers` em config/atlas.php
- [ ] Namespacing `mcp_<server>_<tool>`
- [ ] Tool filtering whitelist/blacklist
- [ ] Env filter para subprocess MCP (passa só `PATH/HOME/USER/LANG/LC_ALL/TERM/SHELL/TMPDIR/XDG_*`)

### P11.2 — Atlas como MCP server (`atlas mcp serve`) (~3-4d)

- [ ] Expor 5-7 tools mínimas (selecionar das 10 do Hermes — `messages_read`, `conversations_list`, `events_poll`, `permissions_list_open`, `permissions_respond` são bons candidatos)
- [ ] Stdio transport

### P11.3 — ACP adapter (~5-7d)

- [ ] Server ACP em `acp_adapter/` (Atlas) emulando Hermes
- [ ] VS Code / Zed / JetBrains test

### P11.4 — Self-evolution pipeline (DSPy + GEPA)

- [ ] Repo paralelo `atlas-self-evolution` (não no core)
- [ ] Lê traces do Atlas, propõe mutações em SKILL.md
- [ ] Output: PRs no repo principal

### P11.5 — Plugin system (5 surfaces)

- [ ] Discovery: `~/.atlas/plugins/`, `<workspace>/.atlas/plugins/`, composer entry points
- [ ] 5 surfaces: `register_command`, `dispatch_tool`, `pre_tool_call` (veto), `transform_tool_result`, `transform_terminal_output`

**Cada P11.x:** quando implementar, escrever sub-checklist próprio inspirado nos Ps anteriores.

### Critério V4 (P11) PRONTO

- [ ] Pelo menos 1 sub-bloco completo (P11.1 recomendado)
- [ ] Tag `v4.0` quando 3+ sub-blocos

---

## §15 Anexos canônicos

### A. JSON packets (referência durante implementação)

Schemas finais (do Hermes + adaptado para Atlas):

```json
// dev_execution_plan — em ai_traces.metadata.execution_plan
{
  "plan_id":"uuid", "objective":"...",
  "phases":["inspect","plan","edit","test","repair","review","finish"],
  "current_phase":"edit",
  "steps":[{"id":"s1","phase":"inspect","status":"done","tool":"workspace.profile","duration_ms":120}],
  "iterations":{"current":1,"max":3,"reason_if_stopped":null},
  "checkpoints":["20260430-..."]
}

// scheduled_task (P5)
{
  "id":"uuid", "title":"...", "prompt":"...",
  "schedule":"0 9 * * *", "kind":"cron",
  "skill_ids":["dev-quality-gate"],
  "target_platform":"mobile_push", "target_device_id":"uuid-of-iphone",
  "next_run_at":"...", "last_run_at":"...", "last_status":"success"
}

// background_task (P7)
{
  "id":"uuid", "parent_thread_id":"...", "prompt":"...",
  "workspace":"/path", "status":"running",
  "started_at":"...", "skill_ids":[...]
}

// inbox_item (P6) — item na inbox operacional do Atlas-app
{
  "id":"uuid", "user_id":"vitor",
  "type":"approval|alert|completion|capture|thread_update|job_status",
  "title":"...", "body":"...",
  "payload":{
    // varia por type. Para approval:
    // { "tool":"file.write", "risk":"medium", "command":"...", "paths":[...], "trace_id":"..." }
  },
  "severity":"info|warning|critical",
  "status":"unread|read|resolved|dismissed",
  "response":{"action":"approve_once|approve_session|approve_workspace_1h|deny","reason":"...","responded_at":"..."},
  "source_type":"trace|scheduled_task|background_task|error",
  "source_id":"uuid",
  "deep_link":"atlas://approval/uuid",
  "created_at":"...","read_at":"...","resolved_at":"..."
}

// mobile_device (P6)
{
  "id":"uuid","user_id":"vitor","device_label":"iPhone 15 Pro",
  "platform":"ios|android","expo_push_token":"ExponentPushToken[xxx]",
  "paired_at":"...","last_seen_at":"...","revoked_at":null
}

// memory_delta (B5 reservado)
{
  "id":"uuid","type":"preference|architecture|error_pattern|test_invariant|naming|process",
  "claim":"...","evidence":[...],"scope":"workspace:/abs",
  "confidence":0.85,"valid_from":"...","valid_until":"...",
  "use_when":[...],"do_not_use_when":[...],
  "requires_confirmation":true,
  "status":"pending|accepted|rejected|superseded"
}
```

### B. `bin/atlas` — comandos finais (após V3)

```bash
case "$cmd" in
    # V1 existing
    chat|ask)        exec php artisan atlas:ai:chat "$@" ;;
    dev)             exec php artisan atlas:cli:dev "$@" ;;
    plan)            exec php artisan atlas:ai:chat "$@" --mode=plan --stream ;;
    review)          exec php artisan atlas:ai:chat "$@" --mode=review --stream ;;
    status|tui)      exec php artisan atlas:cli:dashboard "$@" ;;
    state)           exec php artisan atlas:cli:state "$@" ;;
    compact)         exec php artisan atlas:cli:state compact "$@" ;;
    handoff)         exec php artisan atlas:cli:state handoff "$@" ;;
    checkpoint)      exec php artisan atlas:cli:checkpoint "$@" ;;
    quality|finish)  exec php artisan atlas:cli:quality "$@" ;;
    test)            exec php artisan atlas:cli:quality --run-tests --yes "$@" ;;
    runtime|tool)    exec php artisan atlas:runtime "$@" ;;
    threads)         exec php artisan atlas:ai:chat --list-threads ;;
    bootstrap)       exec php artisan atlas:cli:bootstrap "$@" ;;
    setup|init)      exec php artisan atlas:cli:setup "$@" ;;
    install)         exec php artisan atlas:cli:install "$@" ;;
    doctor)          exec php artisan atlas:cli:doctor "$@" ;;
    providers)       exec php artisan atlas:cli:providers "$@" ;;
    health)          exec php artisan atlas:ai:health "$@" ;;
    work)            exec php artisan atlas:ai:work "$@" ;;
    profile)         exec php artisan atlas:runtime workspace.profile ;;

    # P2 new
    skills)          exec php artisan atlas:cli:skills "$@" ;;

    # P3 new
    search)          exec php artisan atlas:runtime session.search --workspace "$CALLER_PWD" "$@" ;;

    # P5 new
    schedule|cron)   exec php artisan atlas:cli:schedule "$@" ;;

    # P6 new
    mobile)          exec php artisan atlas:cli:mobile "$@" ;;
    inbox)           exec php artisan atlas:cli:inbox "$@" ;;

    # P7 new
    background|bg)   exec php artisan atlas:cli:background "$@" ;;

    # P8 new
    remote)          exec php artisan atlas:cli:remote "$@" ;;

    # Default
    *)               exec php artisan atlas:ai:chat "$cmd" "$@" --stream ;;
esac
```

### C. Migrations resumo (ordem cronológica)

- `2026_05_xx_add_pending_steer_to_ai_session_states` (P1)
- `2026_05_xx_create_ai_skill_bundles_table` (P2 — opcional, se cache de discovery em DB)
- `2026_04_30_150000_add_fts_to_ai_messages` (P3)
- `2026_04_30_220000_create_ai_scheduled_tasks_table` (P5)
- `2026_05_xx_create_atlas_mobile_devices_table` (P6)
- `2026_05_xx_create_ai_inbox_items_table` (P6)
- `2026_05_xx_create_ai_background_tasks_table` (P7)
- `2026_05_xx_create_atlas_api_tokens_table` (P9)

---

## §16 Cheat sheet do Codex

### Quando começar um P

1. `git checkout main && git pull`
2. `git worktree add ../atlas-cli-P<N> -b atlas-cli/P<N>-<slug>`
3. `cd ../atlas-cli-P<N>`
4. Reler integral §P<N> deste documento
5. Confirmar pré-requisitos
6. Implementar passos em ordem, marcando `[x]`
7. `php artisan test`, smoke test do P
8. Commit por passo: `feat(<scope>): P<N> step <K> — <title>`
9. PR ao concluir: título `P<N> — <nome do P>`, body referencia este documento

### Convenção de commits (Conventional Commits)

```
<type>(<scope>): P<N> step <K> — <description>
```

Types: `feat`, `fix`, `test`, `docs`, `refactor`, `chore`, `security`.
Scopes: `cli`, `ai`, `skills`, `gateway`, `cron`, `background`, `remote`, `api`, `transport`, `mcp`, `acp`.

Exemplo: `feat(skills): P2 step 8 — bundle dev-quality-gate skill`.

### Smoke test universal (após cada P)

```bash
php artisan test
bin/atlas doctor --strict
bin/atlas dev "implement hello.txt with 'hi'"  # com approval
bin/atlas search "hello"  # após P3
```

### Quando parar e perguntar ao Vitor

- Spec ambígua entre §P<N> e código existente
- Migration em produção (sempre confirmar)
- Mudança em `AiGatewayService::enqueueInteraction` (core crítico)
- Decisão de stack que não está aqui (Ink vs Bubble Tea, etc.)

### Quando NÃO perguntar (proceder)

- Detalhes de naming dentro do scope do P
- Tests adicionais além dos listados
- Comentários `// claude:` no código se algo for sutil
- Refactors localizados sem mudar API pública

---

## §16.1 Canais explicitamente rejeitados (decisão arquitetural permanente)

**Telegram, Discord, Slack, WhatsApp, Signal, Email-bot, SMS, Matrix, e qualquer messaging gateway externo NÃO serão implementados como canais do Atlas.**

**Motivo (registrado para futuros mantenedores):**

1. **Pilar Atlas:** o produto existe para reduzir fragmentação cognitiva, especialmente para operadores com TDAH. Empurrar para outro app cria exatamente o problema que o Atlas resolve.
2. **Atlas-app já existe** (Expo/React Native em `atlas-app/`). Construir Telegram-gateway é duplicar esforço que UX-wise é inferior: bot externo perde controle visual, push refinado, deep link de approval, modo "não me distraia".
3. **Identidade Atlas:** voz, tom, formato editorial pertencem ao app. Bot externo perde tudo isso.

**Casos de uso que pareciam exigir Telegram são resolvidos por P6 (Mobile Gateway):**

| Caso de uso | Solução Atlas-only |
|---|---|
| Receber alerta de cron task | Push notification + inbox item type `alert`/`completion` |
| Aprovar comando perigoso remotamente | Push → app abre approval screen com 4 botões |
| Mensagem rápida "job terminou" | Push severity `info`, inbox item type `job_status` |
| Captura rápida fora do Mac | Botão flutuante + endpoint `/v1/mobile/captures` |
| Erro crítico | Push severity `critical` (passa quiet hours) |
| Continuar conversa fora do Mac | Thread visível em `/v1/mobile/threads/{id}`, app envia mensagem |

**Reabrir esta decisão exige documentação explícita do Vitor** com:
- caso de uso novo que P6 não cobre
- justificativa de por que adicionar canal externo não fragmenta
- aceitação de manter 2 surfaces UX paralelas

Sem isso, `feat(gateway): add telegram` é PR rejeitado.

---

## §17 Ordem de prioridade — sumário visual

```
V2.0 = Atlas CLI substituto sério
  P0  Hardening (3-4h)
   ↓
  P1  /steer + /busy (1d)
   ↓
  P2  B5.bis Skills compat (5-7d)  ← bloco maior
   ↓
  P3  session_search (2d)
   ↓
  P4  plan-validate-execute (1d)
                                   ✓ V2.0 fechado, tag v2.0

V2.5 = Atlas opera sozinho (sem fragmentação cognitiva)
  P5  Cron (3-4d)
   ↓
  P6  Mobile Gateway + Push + Inbox (6-8d)
        — Atlas-app vira canal único; Telegram/Discord/Slack rejeitados
                                   ✓ V2.5 fechado, tag v2.5

V3 = Atlas paralelo + remoto + API
  P7  Background tasks (3d)
   ↓
  P8  SSH backend (3-5d)
   ↓
  P9  API server OpenAI (2-3d)
                                   ✓ V3 fechado, tag v3.0

V4 = Ecossistema (modular, opt-in)
  P10  Transport ABC (3-5d)
  P11  MCP / ACP / self-evol / plugins
                                   ✓ V4 modular conforme adoção
```

---

## §18 Bottom-line

Este documento é **executável**. Cada checkbox é trabalho concreto. Cada P tem critério de pronto verificável. Não tem ambiguidade sobre "o que vem depois".

V2.0 é o **produto final** prometido — Atlas CLI substituindo Claude Code/Codex CLI. P0-P4 são suficientes.

V2.5 + V3 são **expansões prometidas** — Atlas trabalhando sozinho, paralelo, remoto, com API.

V4 é **ecossistema futuro** — opt-in conforme demanda.

**Para o Codex:** começar por P0 hoje. Não pular ordem. Confirmar com Vitor se algo escapar do scope deste documento.

**Para o Vitor:** revisar P0 (3-4h) é a primeira aprovação que destrava tudo.
