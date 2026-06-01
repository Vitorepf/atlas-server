---
id: atlas-minimax-m27-governed-executor-v1
type: engineering_knowledge
title: Atlas MiniMax M2.7 Governed Executor v1
status: active
implementation_state: available_disabled_by_default
evidence_refs:
  - symbol: AtlasMinimaxM27RuntimeExecutor
  - test: AtlasMinimaxM27RuntimeExecutorTest
category: programming-forge
priority: 96
summary: Executor governado para MiniMax M2.7 no Atlas Forge. Dois drivers (HTTP direto e CLI Python subprocess). Token Plan Key e o modo auth canonico; paygo bloqueado por default. Highspeed bloqueado por default mesmo com Token Plan Key. MiniMax e provider governado, nao autoridade — Atlas Decide decide quando usar. Segue contrato atlas-minimax-first-24h-flow-v1 (sharding, context pack, patch pequeno).
human_summary: Executa MiniMax M2.7 como worker governado barato para o loop 24/7, com dois drivers (HTTP e CLI), controles de quota e bloqueios de highspeed/paygo/overflow.
human_what: Dois drivers para invocar MiniMax M2.7 via HTTP API (Anthropic-compatible endpoint) ou via Python subprocess CLI adapter.
human_purpose: Habilitar MiniMax M2.7 como worker de baixo custo para tarefas de engenharia no loop 24h sem expor o operador a overflow de credito ou highspeed nao autorizado.
human_input: Recebe spec fatiada, context pack, patch scope, budget, Atlas Decide receipt e flags de autorizacao do operador.
human_output: Entrega resultado de execucao governado, evidence pack, error mapping canonico e bloqueio honesto quando gates nao passam.
human_change_when: Mexa quando MiniMax API mudar endpoint/auth, quando Atlas Decide mudar policy de uso, quando sharding ou context pack evoluirem, ou quando highspeed for desbloqueado pelo operador.
human_block_when: Bloqueie quando code quiser usar paygo sem ATLAS_MINIMAX_CREDITS_OVERFLOW_ENABLED=true, invocar highspeed sem ATLAS_MINIMAX_ALLOW_HIGHSPEED=true, usar output gigante, reivindicar completion sem evidence real, ou bypassar Atlas Decide.
tags:
  - atlas-ai
  - minimax
  - m27
  - provider-drivers
  - governed
  - token-plan-key
  - forge
  - worker
decisions:
  - MiniMax M2.7 e provider governado; Atlas Decide decide quando usar, nao o driver.
  - Token Plan Key e o modo auth canonico (ATLAS_MINIMAX_TOKEN_PLAN_KEY). Paygo bloqueado por default.
  - MiniMax-M2.7-highspeed nao e autorizado por Token Plan Key (confirmado na doc oficial). Requer paygo + ATLAS_MINIMAX_ALLOW_HIGHSPEED=true explicito.
  - Credits overflow bloqueado por default (ATLAS_MINIMAX_CREDITS_OVERFLOW_ENABLED=false).
  - Driver HTTP usa Laravel Http facade diretamente contra https://api.minimax.io/anthropic/v1/messages.
  - Driver CLI usa Python subprocess adapter em runtimes/python/minimax_m27/adapter.py; ANTHROPIC_BASE_URL e injetado so no subprocess, nunca no env global.
  - CLI NAO modifica ~/.claude/settings.json; env vars sao isoladas ao subprocess.
  - Error mapping canonico: 401/403 auth_failed, 429 rate_limit, 429+quota quota_exhausted, 503 model_unavailable, timeout timeout.
  - MiniMax NAO substitui Codex GPT-5.5 como judge premium.
  - NAO usar para completion claim; NAO usar com output gigante.
  - Workers candidatos para loop 24/7 so apos smoke e evidence real.
  - Segue contrato atlas-minimax-first-24h-flow-v1: sharding, context pack, patch pequeno.
maintenance:
  - Atualizar este doc antes de alterar AtlasMinimaxM27RuntimeExecutor, AtlasMinimaxM27CliRuntimeExecutor, AtlasForgeMinimaxM27InvocationDriver, AtlasForgeMinimaxM27CliInvocationDriver ou runtimes/python/minimax_m27/adapter.py.
  - Atualizar quando MiniMax mudar politica de Token Plan Key, highspeed, endpoint ou rate limits.
  - Atualizar quando Atlas Decide mudar policy de roteamento para minimax_m27 ou minimax_m27_cli.
related_paths:
  - docs/engineering-knowledge-base/atlas-current-provider-stack-v1.md
  - docs/engineering-knowledge-base/atlas-minimax-first-24h-flow-v1.md
  - docs/engineering-knowledge-base/atlas-token-economy-runtime.md
  - docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
  - docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md
  - docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
  - app/Services/Ai/Programming/AtlasMinimaxM27RuntimeExecutor.php
  - app/Services/Ai/Programming/AtlasMinimaxM27CliRuntimeExecutor.php
  - app/Services/Ai/Programming/AtlasForgeMinimaxM27InvocationDriver.php
  - app/Services/Ai/Programming/AtlasForgeMinimaxM27CliInvocationDriver.php
  - runtimes/python/minimax_m27/adapter.py
  - config/atlas.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-minimax-m27-governed-executor-v1
graph_title: Atlas MiniMax M2.7 Governed Executor v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-forge-governed-provider-invocation-v1
graph_status: active
graph_source: repo
owner: programming
canonical_source: docs/engineering-knowledge-base/atlas-minimax-m27-governed-executor-v1.md
risk_level: high
ai_entrypoints:
  - Leia este doc antes de alterar drivers MiniMax, adapter Python, config de auth ou policy de highspeed.
ai_usage_notes:
  - MiniMax e worker; Atlas Decide e a autoridade. Nunca invoque direto sem receipt.
  - Token Plan Key nao autoriza highspeed. Highspeed requer paygo + flag explicita.
  - CLI nao toca ~/.claude/settings.json. Env vars so no subprocess.
quality_gates:
  - atlas-decide-receipt-present
  - token-plan-key-configured
  - no-paygo-without-explicit-flag
  - no-highspeed-without-explicit-flag
  - no-overflow-without-explicit-flag
  - patch-scope-within-contract
---

# Atlas MiniMax M2.7 Governed Executor v1

## Resumo

MiniMax M2.7 e um provider externo governado disponivel no Atlas Forge como worker de baixo custo para tarefas de engenharia no loop 24/7. O executor expoe dois drivers:

- **minimax_m27** — driver HTTP direto via Laravel Http facade contra endpoint Anthropic-compatible da MiniMax
- **minimax_m27_cli** — driver CLI via Python subprocess adapter que injeta `ANTHROPIC_BASE_URL` apenas no processo filho

Ambos os drivers sao desabilitados por default (`implementation_state: available_disabled_by_default`). A habilitacao requer configuracao explicita no `config/atlas.php` sob `atlas.ai.providers.minimax_m27` e `atlas.ai.providers.minimax_m27_cli`.

MiniMax nao tem autoridade de decisao no Atlas. Atlas Decide decide quando e como usar MiniMax como worker.

---

## Papel no Atlas

MiniMax M2.7 ocupa o slot de **worker barato e de alto throughput** na topologia de providers do Atlas Forge. Seu papel e processar tarefas fatiadas de engenharia (leitura de contexto, geracao de patches pequenos, sumarizacao estruturada) dentro do contrato definido em `atlas-minimax-first-24h-flow-v1`.

O que MiniMax NAO e:
- Nao e judge premium (Codex GPT-5.5 ocupa esse papel)
- Nao e executor de completion claim
- Nao e autoridade sobre qualquer decisao de arquitetura ou deploy
- Nao substitui Claude Code ou Codex como produto

A posicao de MiniMax na hierarquia de providers e: worker candidato para loop 24/7 apos smoke test + evidence real aprovados pelo operador.

---

## Onde Se Encaixa

```
Atlas Decide (autoridade de roteamento)
    └─ Forge Provider Topology
        └─ AtlasForgeMinimaxM27InvocationDriver (HTTP)
        │       └─ AtlasMinimaxM27RuntimeExecutor
        │               └─ Laravel Http facade → https://api.minimax.io/anthropic/v1/messages
        └─ AtlasForgeMinimaxM27CliInvocationDriver (CLI)
                └─ AtlasMinimaxM27CliRuntimeExecutor
                        └─ Python subprocess
                                └─ runtimes/python/minimax_m27/adapter.py
                                        └─ ANTHROPIC_BASE_URL injetado so no subprocess
```

O driver HTTP e preferencial para invocacao direta via API. O driver CLI e alternativa para fluxos que precisam do adapter Python (ex.: integracao com tooling que usa Claude SDK Python com base URL customizada).

---

## Contratos

### Auth

| Modo | Env Var | Status |
|------|---------|--------|
| Token Plan Key | `ATLAS_MINIMAX_TOKEN_PLAN_KEY` | Canonico (habilitado quando configurado) |
| Paygo | `ATLAS_MINIMAX_PAYGO_KEY` | Bloqueado por default |

Paygo so e desbloqueado com `ATLAS_MINIMAX_CREDITS_OVERFLOW_ENABLED=true` explicito no env. Sem essa flag, qualquer tentativa de usar paygo retorna `auth_mode_blocked`.

### Highspeed

MiniMax-M2.7-highspeed **nao e autorizado por Token Plan Key** — confirmado na documentacao oficial da MiniMax. Highspeed requer:
1. Paygo ativo (`ATLAS_MINIMAX_PAYGO_KEY` configurado)
2. `ATLAS_MINIMAX_ALLOW_HIGHSPEED=true` explicito

Sem ambas as condicoes, o driver retorna `model_highspeed_blocked` antes de qualquer invocacao.

### Credits Overflow

`ATLAS_MINIMAX_CREDITS_OVERFLOW_ENABLED=false` por default. Quando `false`, o driver bloqueia toda invocacao via paygo, prevenindo overflow de credito nao autorizado.

### Contrato de Tarefa (minimax-first-24h-flow-v1)

Todo job enviado para MiniMax deve respeitar:
- **Sharding**: contexto grande deve ser fatiado antes da invocacao; nunca enviar repo inteiro cru
- **Context Pack**: usar context pack estruturado (ownership map, spec seed, patch scope) em vez de dump bruto
- **Patch pequeno**: output maximo de uma invocacao e um patch atomico; NAO gerar output gigante
- **Sem completion claim**: o driver nunca promove completion claim; evidence e produzida separadamente

---

## Fluxo

### Driver HTTP (minimax_m27)

```
1. AtlasForgeMinimaxM27InvocationDriver.invoke()
2. Gate: Atlas Decide receipt presente?        → nao → blocked(atlas_decide_receipt_required)
3. Gate: Token Plan Key configurado?           → nao → blocked(auth_not_configured)
4. Gate: Paygo necessario?                     → sim e overflow=false → blocked(paygo_overflow_blocked)
5. Gate: Highspeed solicitado?                 → sim e flag=false → blocked(model_highspeed_blocked)
6. AtlasMinimaxM27RuntimeExecutor.execute()
7. Laravel Http::post('https://api.minimax.io/anthropic/v1/messages', payload)
8. Resposta → error mapping → resultado governado
9. Evidence appended (output sha256, tokens, status)
```

### Driver CLI (minimax_m27_cli)

```
1. AtlasForgeMinimaxM27CliInvocationDriver.invoke()
2. [mesmos gates 2-5 acima]
3. AtlasMinimaxM27CliRuntimeExecutor.execute()
4. Build env: { ANTHROPIC_BASE_URL: 'https://api.minimax.io/anthropic/v1', ANTHROPIC_API_KEY: token_plan_key }
5. subprocess.run(['python', 'runtimes/python/minimax_m27/adapter.py', ...args], env=isolated_env)
6. NUNCA modifica ~/.claude/settings.json
7. NUNCA expoe env vars MiniMax para o processo pai
8. Output capturado → error mapping → resultado governado
9. Evidence appended
```

### Error Mapping Canonico

| Condicao | Codigo canonico |
|----------|----------------|
| HTTP 401 ou 403 | `auth_failed` |
| HTTP 429 sem quota signal | `rate_limit` |
| HTTP 429 com quota signal no body | `quota_exhausted` |
| HTTP 503 | `model_unavailable` |
| Timeout de rede ou subprocess | `timeout` |
| Subprocess exit != 0 sem mapeamento | `cli_adapter_error` |

---

## Regras para IA

1. **MiniMax nao e autoridade.** Atlas Decide decide quando usar. Sem receipt de Atlas Decide, o driver bloqueia.
2. **Token Plan Key nao autoriza highspeed.** Nunca assuma que Token Plan Key habilita highspeed — e bloqueado por design.
3. **CLI nao toca settings.json.** O adapter Python injeta env vars so no subprocess. Nunca modifique `~/.claude/settings.json` via esse driver.
4. **Paygo bloqueado por default.** Nao ative `ATLAS_MINIMAX_CREDITS_OVERFLOW_ENABLED=true` sem instrucao explicita do operador.
5. **Sem completion claim.** O resultado de uma invocacao MiniMax nao e completion claim. Evidence real separada e obrigatoria.
6. **Sem output gigante.** Invocacoes devem respeitar o contrato de patch pequeno do `atlas-minimax-first-24h-flow-v1`.
7. **Workers 24/7 so apos smoke.** Nao escalone MiniMax para loop continuo sem smoke test e evidence real aprovados.
8. **MiniMax nao substitui Codex GPT-5.5.** Para julgamento premium, use Codex GPT-5.5.
9. **Antes de alterar qualquer driver ou adapter:** leia este doc + `atlas-forge-governed-provider-invocation-v1.md` + `atlas-minimax-first-24h-flow-v1.md`.
10. **Configuracao em config/atlas.php.** As chaves `atlas.ai.providers.minimax_m27` e `atlas.ai.providers.minimax_m27_cli` governam habilitacao, timeout, modelo default e flags de highspeed/overflow.

---

## Escopo de Implementacao

### Habilitado por default
Nada. `implementation_state: available_disabled_by_default`.

### Requer configuracao explicita para habilitar

```php
// config/atlas.php
'providers' => [
    'minimax_m27' => [
        'enabled'                  => env('ATLAS_MINIMAX_M27_ENABLED', false),
        'token_plan_key'           => env('ATLAS_MINIMAX_TOKEN_PLAN_KEY'),
        'paygo_key'                => env('ATLAS_MINIMAX_PAYGO_KEY'),
        'allow_highspeed'          => env('ATLAS_MINIMAX_ALLOW_HIGHSPEED', false),
        'credits_overflow_enabled' => env('ATLAS_MINIMAX_CREDITS_OVERFLOW_ENABLED', false),
        'model_default'            => 'MiniMax-M2.7',
        'endpoint'                 => 'https://api.minimax.io/anthropic/v1/messages',
        'timeout_seconds'          => 120,
    ],
    'minimax_m27_cli' => [
        'enabled'       => env('ATLAS_MINIMAX_M27_CLI_ENABLED', false),
        'adapter_path'  => base_path('runtimes/python/minimax_m27/adapter.py'),
        'python_bin'    => env('ATLAS_MINIMAX_PYTHON_BIN', 'python3'),
        'timeout_seconds' => 180,
    ],
],
```

### O que o driver HTTP faz
- Invoca `https://api.minimax.io/anthropic/v1/messages` via Laravel Http facade
- Passa `anthropic-version` e `x-api-key` headers com Token Plan Key
- Captura output, sha256, tokens usados
- Mapeia erros para codigos canonicos
- Nunca promove completion claim

### O que o driver CLI faz
- Constroi env isolado com `ANTHROPIC_BASE_URL` e `ANTHROPIC_API_KEY` (Token Plan Key)
- Executa `runtimes/python/minimax_m27/adapter.py` via subprocess
- Captura stdout/stderr, sha256
- Mapeia exit code e stderr para codigos canonicos
- Nunca toca `~/.claude/settings.json`

### O que o adapter Python faz
- Recebe task via argv ou stdin JSON
- Usa Anthropic Python SDK com `ANTHROPIC_BASE_URL` customizado
- Retorna resultado estruturado via stdout JSON
- Nao persiste nada; e stateless

---

## Dependencias

| Dependencia | Tipo | Nota |
|-------------|------|------|
| Atlas Decide | Autoridade | Receipt obrigatorio antes de invocacao |
| Forge Provider Topology | Roteamento | Minimax e um arm na topologia |
| atlas-forge-governed-provider-invocation-v1 | Contrato pai | 13 gates de invocacao se aplicam |
| atlas-minimax-first-24h-flow-v1 | Contrato de fluxo | Sharding, context pack, patch pequeno |
| atlas-token-economy-runtime | Budget | Controle de tokens e quota |
| Laravel Http facade | Runtime | Driver HTTP |
| Python 3 + Anthropic SDK | Runtime | Driver CLI |

---

## Evidencias

Para evidencia de funcionamento real, o operador deve executar smoke test manual e registrar no Evidence Ledger antes de habilitar para loop 24/7:

```bash
# Smoke test driver HTTP (dry-run)
php artisan atlas:forge:provider invoke \
  --driver=minimax_m27 \
  --dry-run \
  --task="summarize: hello world" \
  --json

# Smoke test driver CLI (dry-run)
php artisan atlas:forge:provider invoke \
  --driver=minimax_m27_cli \
  --dry-run \
  --task="summarize: hello world" \
  --json
```

Status atual: `available_disabled_by_default`. Sem evidence de execucao real registrada. Workers 24/7 bloqueados ate smoke aprovado.

---

## Riscos

| Risco | Severidade | Mitigacao |
|-------|-----------|-----------|
| Highspeed ativado inadvertidamente com paygo | Alto | Flag `ATLAS_MINIMAX_ALLOW_HIGHSPEED` + `CREDITS_OVERFLOW_ENABLED` independentes; ambas necessarias |
| Env vars MiniMax vazando para processo Claude Code do operador | Alto | Subprocess isolado; nunca modifica settings.json ou env global |
| Completion claim promovido sem evidence | Alto | Driver nunca promove claim; proibido por contrato |
| Output gigante esgotando quota | Medio | Contrato de patch pequeno; sharding obrigatorio via first-24h-flow |
| Model unavailable 503 nao tratado como bloqueio | Medio | Error mapping canonico; driver retorna `model_unavailable`, nao silencia |
| Paygo ativado via drift de config | Medio | `CREDITS_OVERFLOW_ENABLED=false` hard default; requer flag explicita |

---

## Exemplos

### Invocacao governada (HTTP driver, Token Plan Key)

```php
// Correto: Atlas Decide receipt presente, Token Plan Key configurado,
// highspeed=false, overflow=false, tarefa fatiada
$result = $driver->invoke([
    'driver'        => 'minimax_m27',
    'decide_receipt'=> $receiptId,
    'model'         => 'MiniMax-M2.7',        // nao highspeed
    'context_pack'  => $contextPack,           // nao dump bruto
    'task'          => $slicedSpec,            // fatiado
    'max_tokens'    => 2048,                   // patch pequeno
]);
// $result->status nunca e 'completed' sem evidence real separada
```

### Invocacao CLI isolada (subprocess)

```python
# runtimes/python/minimax_m27/adapter.py
# ANTHROPIC_BASE_URL e ANTHROPIC_API_KEY chegam via env do subprocess
# ~/.claude/settings.json nunca e tocado
import os, anthropic, json, sys

client = anthropic.Anthropic(
    base_url=os.environ['ANTHROPIC_BASE_URL'],
    api_key=os.environ['ANTHROPIC_API_KEY'],
)
task = json.loads(sys.stdin.read())
response = client.messages.create(
    model=task.get('model', 'MiniMax-M2.7'),
    max_tokens=task.get('max_tokens', 2048),
    messages=task['messages'],
)
print(json.dumps({'content': response.content[0].text, 'usage': response.usage.model_dump()}))
```

### Bloqueio esperado: highspeed sem paygo

```php
// Incorreto: highspeed solicitado sem paygo + flag
$result = $driver->invoke([
    'driver'  => 'minimax_m27',
    'model'   => 'MiniMax-M2.7-highspeed',
]);
// $result->status == 'blocked'
// $result->reason == 'model_highspeed_blocked'
// Nenhuma chamada HTTP e feita
```

---

## Proximas Acoes

1. **[operador]** Configurar `ATLAS_MINIMAX_TOKEN_PLAN_KEY` no `.env` local antes de qualquer uso.
2. **[operador]** Executar smoke test manual dos dois drivers e registrar evidence no Evidence Ledger.
3. **[operador]** Decidir via Atlas Decide se minimax_m27 entra na topologia de workers do loop 24/7.
4. **[engenharia]** Implementar `AtlasMinimaxM27RuntimeExecutor`, `AtlasMinimaxM27CliRuntimeExecutor`, `AtlasForgeMinimaxM27InvocationDriver`, `AtlasForgeMinimaxM27CliInvocationDriver` seguindo este contrato.
5. **[engenharia]** Implementar `runtimes/python/minimax_m27/adapter.py` com isolamento de env vars.
6. **[engenharia]** Adicionar tests cobrindo: auth_failed, rate_limit, quota_exhausted, model_unavailable, timeout, model_highspeed_blocked, paygo_overflow_blocked, subprocess_isolation.
7. **[engenharia]** Registrar minimax_m27 e minimax_m27_cli como arms na Forge Provider Topology.
8. **[futuro]** Avaliar highspeed so depois de paygo e overflow habilitados explicitamente pelo operador com Atlas Decide receipt documentando a decisao.
