# AOBG N1 — Atlas Open Brain Gateway: a biblioteca, em produto

Estado: **PRODUTO, LIGADO NO DEV** (2026-06-10). Front door + write-back + multi-project + hook full-brain, todos provados LIVE contra o pgsql de dev (custo zero — read tools são DB local). Não commitado (decisão do operador).

> Doc de operador. A árvore canônica (`docs/engineering-knowledge-base`) exige frontmatter de cartografia; este doc vive em `dissecar/` de propósito, fora do contrato de grafo.

## O que é o gateway

AOBG é **a porta única** pela qual qualquer IA externa (Claude Code / Codex / Cursor), em **qualquer projeto**, fala com o cérebro do Atlas — nos **dois sentidos**:

- **PUSH (cérebro → IA):** "o que o cérebro já sabe sobre esta tarefa?" — um pack provider-bound, read-only, custo zero.
- **WRITE-BACK (IA → cérebro):** "registra o que eu fiz / proponho este learning" — entrada **não-confiável**, que nunca escreve memória canônica direto e nunca auto-promove.

A regra-mãe (lição load-bearing da sessão): **não se constrói um segundo motor de contexto.** O gateway é uma camada de **montagem** sobre os três cérebros já provados — ele os funde sob um orçamento e um workspace, e expõe os dois writers já gated sob uma fronteira de entrada hostil. Nada novo de retrieval ou de escrita foi inventado.

## A biblioteca = 1 porta de leitura + 1 porta de escrita + 1 status, em 3 transportes

| Transporte | Como o consumidor chama | Arquivo |
|---|---|---|
| **MCP** (53 tools) | `atlas_context_pack` / `atlas_record_outcome` / `atlas_propose_learning` / `atlas_workspace_status` | `app/Services/Ai/AtlasOpenBrainMcpService.php` |
| **CLI** | `atlas:context-pack` / `atlas:aobg:record-outcome` / `atlas:aobg:propose-learning` / `atlas:aobg:workspace` | `app/Console/Commands/AtlasContextPackCommand.php` + `AtlasAobg*Command.php` |
| **Hook Claude Code** | `UserPromptSubmit` → injeta o pack full-brain | `.claude/hooks/atlas-ctx.sh` |

Os três transportes chamam os MESMOS três serviços:

- `app/Services/Ai/AtlasOpenBrainContextPackService.php` — o front door (PUSH).
- `app/Services/Ai/AtlasOpenBrainWriteBackService.php` — a porta de escrita governada.
- `app/Services/Ai/AtlasAobgWorkspaceOnboardingService.php` — o status multi-project.

## O front door (PUSH) — funde os 3 cérebros provados

`AtlasOpenBrainContextPackService::packFor($task, $opts)` monta UM pack `atlas.aobg.context_pack.v1` somando três seções independentes:

1. **code-graph** — `CodeGraphContextRetriever::packFor()` (BM25 + E-3, escopado ao workspace). O sub-budget de chars vira token-budget a ~4 chars/token.
2. **reality graph (AURG)** — `AtlasRealityGraphQueryService::query($task, ['provider_bound' => true])`. `provider_bound` é **forçado, não-relaxável** aqui: a saída cruza para uma IA externa, então domínios sensíveis e tudo alcançável só por eles ficam **estruturalmente** fora (excluídos pela própria query, não pós-filtrados).
3. **memória semântica** — `AtlasHybridMemoryRetrievalService::recall()`, que já retorna SÓ projeções **redigidas** provider-safe (`AtlasMemoryPrivacyService` + flag `external_ai_allowed`).

Cada seção é construída **independente e fail-safe**: qualquer uma degrada a vazio honesto (sem cérebro/tabela → vazio, nunca fabricado). O serviço **nunca lança** — recall é best-effort, não um portão.

### O orçamento é um teto REAL sobre o output medido (corrigido em F5)

Bug encontrado e fechado nesta finalização: o retriever de código orça por **tokens de signature**, mas o pack também carrega `id` + `file_path` de cada símbolo (não contados pelo token-budget). Sem um trim final, um pack com total apertado **estourava o teto** (medido: 7551 chars para um `--budget=6000`). `AtlasOpenBrainContextPackService::enforceTotalCeiling()` agora apara os itens de código de menor ranking primeiro (a fonte do overflow), depois memória, até `estimated_chars <= total_chars`. Cada seção presente mantém **pelo menos o top hit** (nunca esvazia). Pós-fix, LIVE: `--budget=6000` → 5929 chars; `--budget=800` → 601; `--budget=200` → 209 (o piso de 1 item/seção). Teste: `test_total_budget_is_a_real_ceiling_on_the_measured_pack`.

## A porta de escrita (WRITE-BACK) — entrada hostil, nunca auto-promove

`AtlasOpenBrainWriteBackService` trata todo input externo como **hostil** e aplica o **piso de entrada ANTES** de delegar:

- **provider-safety:** qualquer `privacy_class` ≠ `normal` (private/sensitive/secret/cyber/…) é **rejeitado** (`rejected_provider_unsafe`); `external_ai_allowed=false` é honrado; labels são re-redigidas com `AtlasSecurity` (defesa em profundidade).
- **size:** payloads acima dos caps (`config atlas.aobg.write_back.*`) são **rejeitados, não truncados** — uma sessão externa em loop não inunda o cérebro.
- **shape:** campos obrigatórios validados; faltando → erro honesto.

Aceito, delega aos dois writers JÁ gated:

- `record_outcome` → `AtlasRealityGraphIngestionService::recordMissionOutcome()` — nó mission+evidence provider-safe, idempotente por hash, **um BRANCH, nunca um merge** (`merged:false` explícito).
- `propose_learning` → `AtlasLearningProposalService::propose()` — roda o `AtlasCaptureQualityGate` (rejeita ruído + dedup) e **sempre** aterrissa `status='proposed'`; `apply`/`auto_apply` são rejeitados pela própria pipeline. Envelope: `applied:false, auto_promoted:false, requires_human_review:true`.

`failOpen`: uma falha de store degrada a `ok:false` — nunca quebra a sessão externa. **Toda** tentativa (aceita ou rejeitada) grava um receipt append-only identity-only em `storage/atlas/governance/aobg_write_back.jsonl` (chaves: action/id/kind/status/reason — sem summary/body/request).

## Multi-project — auto-escopado, sem vazamento cross-workspace

Workspace resolvido UMA vez por chamada, de um `workspace` explícito (path OU id) OU do `cwd` do chamador, via `CodeGraphWorkspaceIdentity`.

Bug encontrado e fechado em F5: o `workspace` documentado como "path OU id" só funcionava com **path**. Passar um **id** já-resolvido (ex.: `atlas-server`) caía no `resolve()`, que faz `realpath()`, falhava, e **derivava um id novo e vazio** (`atlas-server-a17a6563`, 0 símbolos) — escopando para um grafo vazio um workspace com 114k símbolos. Novo `CodeGraphWorkspaceIdentity::resolveWorkspaceOrId()`: um path existente resolve via `resolve()`; um id estável (token normalizado sem separador, ou `base::sub` de monorepo) passa **verbatim**. Os 3 serviços AOBG usam-no para o arg `workspace` (o `cwd` continua sempre path). Teste: `tests/Unit/CodeGraph/CodeGraphWorkspaceIdentityResolveOrIdTest.php`. LIVE pós-fix: `atlas-server`→114318 símbolos, `gold-dbfc1eec`→20, path não indexado→`needs_onboarding:true`, overlap de arquivos entre workspaces = 0.

`atlas_workspace_status` responde honestamente `{workspace_id, indexed, symbols, last_index, needs_onboarding}` e **OFERECE** o comando de index — mas **não roda** index pesado de repo arbitrário implicitamente (gated por `config atlas.aobg.auto_onboard`, default `false`).

## O hook full-brain (upgrade sobre o code-graph-only)

`.claude/hooks/atlas-ctx.sh` ANTES injetava só `atlas:ctx` (símbolos BM25, construído antes dos Saltos 1-3). Agora chama `atlas:context-pack --json` e injeta o markdown do pack **fundido** quando a soma das 3 contagens de seção > 0. Contrato inalterado: best-effort, **nunca** um portão (qualquer falta de jq/php/erro → `exit 0` silencioso). Anchora no `CLAUDE_PROJECT_DIR` para auto-escopar ao workspace do projeto chamador.

Prova LIVE (dry-run): `echo '{"prompt":"..."}' | CLAUDE_PROJECT_DIR=$(pwd) bash .claude/hooks/atlas-ctx.sh` emitiu um `additionalContext` com as 3 seções — `## Code graph` (17 itens), `## Reality graph` (cabeçalho, vazio honesto p/ a query), `## Memory` (5 itens). **HOOK_FULL_BRAIN: YES.**

## As 3 stanzas de registro (exatas)

Claude Code já está ligado in-repo via `.mcp.json` (sem edição global):

```json
{ "mcpServers": { "atlas-open-brain": { "command": "bin/atlas", "args": ["open-brain", "mcp"] } } }
```

Codex (`~/.codex/config.toml`):

```toml
[mcp_servers.atlas-open-brain]
command = "/Users/vitorepf/develop/Atlas/atlas-server/bin/atlas"
args = ["open-brain", "mcp"]
```

Cursor (`~/.cursor/mcp.json`): a mesma entrada `mcpServers."atlas-open-brain"`.

`scripts/setup-aobg.sh` imprime essas stanzas + um diff seco por default (não toca config nenhuma); `--install` aplica de forma **idempotente** (pula se já registrado) com `.bak` timestamped. Os configs globais externos são do operador para aplicar — o build só escreve `.mcp.json` + o hook in-repo. Verificar o servidor: `bin/atlas open-brain mcp --describe`.

## O piso provider-safety (não-negociável)

Todo byte que o gateway entrega cruza para uma IA externa, então:

- AURG sempre `provider_bound=true` (sensível/secret excluído por construção).
- Memória só projeção redigida (`AtlasMemoryPrivacyService`); body cru nunca sai.
- Evidência só ids/hashes.
- Write-back: entrada não-`normal` rejeitada; receipts identity-only.

Prova LIVE: memória `secret` + `sensitive` com título casando a query → conteúdo **NÃO** entrou no pack (`SECRET_CONTENT_LEAKED=NO`); payload `privacy_class=secret` no write-back → `rejected_provider_unsafe`. Limpeza: `REMAINING=0`.

## Limites honestos (anti-over-claim)

- O pack é **curated top-K**, não onisciência — ele se rotula `"curated top-K (not exhaustive)"` (`HONESTY_LABEL`). É a menor fatia útil, não um dump do cérebro.
- Seção vazia é honesta ("o cérebro não tem nada aqui"), nunca fabricada; o markdown distingue vazio de não-consultado.
- Configs externos (Codex/Cursor) precisam do operador rodar `scripts/setup-aobg.sh --install` — o build não toca config global.
- `atlas_record_outcome` grava um **branch**, nunca um merge; `propose_learning` é sempre `pending_review` — **revisão humana** é o único caminho para memória canônica.
- Custo: read tools = DB local, zero provider/HTTP (provado por ausência de `Http::`/`AiProviderManager`/`->generate(` nos serviços).

## Comandos

```bash
php artisan atlas:context-pack "tarefa" [--workspace=path|id] [--budget=6000] [--json]
php artisan atlas:aobg:record-outcome ...      # write-back: registra o que a sessão fez
php artisan atlas:aobg:propose-learning ...     # write-back: propõe um learning (pending_review)
php artisan atlas:aobg:workspace [status|onboard] [--cwd=] [--workspace=] [--json]
php artisan atlas:open-brain:mcp --once          # servidor MCP (tools/list, tools/call)
bin/atlas open-brain mcp --describe              # config de registro + workspace resolvido
scripts/setup-aobg.sh [--print|--install]        # registrar nos configs Codex/Cursor
echo '{"prompt":"..."}' | .claude/hooks/atlas-ctx.sh   # dry-run do hook full-brain
```

## Config (`config/atlas.php` → `aobg`)

```
budget_chars=6000  code_budget_chars=2500  memory_budget_chars=2000
auto_onboard=false        # index pesado de repo arbitrário = decisão do operador
write_back.max_*          # caps de tamanho (provider-safety + never-auto-promote são estruturais, não config)
```

## Provas LIVE desta finalização (números reais)

- MCP `tools/list`: **53 tools**; os 4 AOBG presentes.
- `atlas:context-pack "memoria semantica embedding decisao" --json`: code=18, reality=0 (vazio honesto — AURG direto também 0, `unranked_below_threshold`), memory=5; `provider_bound=true`; estimated=5929/total=6000.
- Provider-safety: secret+sensitive casando a query → `SECRET_CONTENT_LEAKED=NO`, `REMAINING=0`.
- Write-back: `status=pending_review, applied=false, auto_promoted=false`, `CANONICAL_MEMORY_DELTA=0`, DB status `proposed`; sensível → `rejected_provider_unsafe`; receipts identity-only.
- Multi-project: `atlas-server`=114318 símbolos, `gold-dbfc1eec`=20, overlap=0, `DISTINCT_RESULTS=YES`.
- Hook: `HOOK_FULL_BRAIN=YES` (3 seções injetadas).
- Bateria de regressão: **100 testes / 895 assertions** no surface AOBG (5 arquivos verdes). AURG: 41/42 verdes; o 1 vermelho (`test_ranking_falls_back_honestly_without_python_runtime`, mock `File::exists`/venv) é **pré-existente** — provado idêntico com minha edição revertida; não toca workspace identity.
- `php -l` limpo nos 4 serviços; zero chamadas provider/HTTP.
