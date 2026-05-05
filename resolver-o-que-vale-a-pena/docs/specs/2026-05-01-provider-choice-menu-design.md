# Provider Choice Menu — CLI + App Design

**Status:** Approved (2026-05-01)
**Depende de:** `docs/plans/2026-05-01-provider-choice-on-limit.md` (backend já implementado em commits `2b2108f`..`2b3a336`)

## Goal

Quando o backend pausa um job em `awaiting_user_choice` (rate limit ou auth expired), o operador precisa ver as opções e escolher. Hoje o backend grava as opções em `ai_jobs.metadata.choice_options` e expõe via `AiJobResource`, mas nenhuma surface consome — o operador fica olhando um chat travado sem saber que existe uma decisão pendente.

Este spec cobre os dois consumidores: a CLI (`atlas chat`, onde o cenário do print original aconteceu) e o app Expo (`mobile-thread.tsx`, secundário).

## Non-Goals

- Backoff exponencial (gap separado).
- Detecção preventiva de "approaching rate limit" (gap separado).
- Notificação push pro operador quando job pausa em background (futuro; o operador vê quando volta pra surface).
- Suportar múltiplos jobs pausados simultaneamente com fila de menus (raro; primeiro pausado domina).

## UX

### CLI — bloqueante

O operador está em `atlas chat`. O chat polla `trace->status` num loop com `sleep(1)`. Hoje os estados terminais são `succeeded`, `failed`, `cancelled` — adicionamos detecção de `awaiting_user_choice` no `AiJob` subjacente.

Quando detectado, o loop pausa e renderiza:

```
⚠ Codex gpt-5.5 sem créditos até 05/05 10:24

  [1] Migrar para Claude Sonnet 4.6 (claude_cli)
      Provider alternativo full-power. Disponível agora.

  [2] Continuar no Codex com gpt-5.4-mini (modelo menor)
      Mesma conta Codex. Disponível agora. Mais rápido, mais barato, menos capaz.

  [3] Aguardar reset (em 4 dias)
      Pausa o chat. Retoma com gpt-5.5 quando liberar. Nada roda nesse meio tempo.
      (Some da lista se reset for > 24h ou desconhecido.)

  [4] Cancelar
      Encerra a tentativa. Conversa fica preservada.

  [r] Já liberei (comprei créditos / upgrade de plano) — tentar de novo agora
      Reenfileira com mesmo provider e modelo. Se ainda estiver bloqueado,
      o menu volta.

  Escolha [1-4 ou r]:
```

Comportamento por ação:
- `switch_provider` → CLI imprime `Migrando pra <provider>...`, volta pro loop, conversa continua.
- `downgrade_model` → CLI imprime `Continuando no <provider> com <modelo_menor>...`, volta pro loop. Sem volta automática pro modelo maior — operador roda `atlas chat upgrade-model` manualmente quando quiser.
- `wait` → CLI imprime `Pausado até <data>. Rode \`atlas chat continue --trace <id>\` quando quiser retomar.` e sai com exit 0.
- `fail` (login_required) → CLI imprime `Login expirado. Rode \`<cli_command>\` no terminal e \`atlas chat retry --trace <id>\`.` e sai com exit 1.
- `cancel` → CLI imprime `Job cancelado.` e sai com exit 0.
- `retry_same` → CLI imprime `Tentando novamente...`, volta pro loop. Se provider bater rate limit de novo, menu retorna (resolver reseta `provider_choice_state` apenas para esta action).

Esc / Ctrl+C antes de escolher: trata igual a `cancel`.

A opção [3] é condicional: só aparece quando `provider_reset_at` é conhecido E o reset é < 24h. Para resets distantes (4 dias no exemplo) ou desconhecidos, ela some — bloquear chat por dias é UX ruim.

### App — sheet bottom-anchored

`mobile-thread.tsx` já polla o job. Quando o resource retorna `awaiting_user_choice === true`, monta um Sheet Tamagui ancorado embaixo:

- Header: `<provider> sem créditos` ou `Login expirado` (pelo `provider_choice_error_code`).
- Sub-header: `<reset_hint>` se houver.
- Lista de cards, um por opção, com `label` em destaque e `description` abaixo.
- Botão primário "Cancelar" no rodapé sempre, mesmo que `cancel` já esteja na lista (atalho UX).

Tap → POST `/ai/jobs/:id/resume-choice` → sheet fecha → polling continua e mostra novo estado.

`wait` no app não força sair: o thread continua aberto, o polling segue, e quando o `available_at` vencer e o worker rodar, o app vê normal.

## Architecture

### Compartilhado (servidor)

**Novo:** `App\Services\Ai\AiProviderChoiceResolver`

```
AiProviderChoiceResolver::resolve(AiJob $job, string $optionId): array
  return [
    'option' => array,         // a opção escolhida
    'action' => string,        // switch_provider | wait | fail | cancel
    'job' => AiJob,            // job atualizado
  ]
```

Encapsula:
- Validação de `$job->status === 'awaiting_user_choice'`
- Lookup da opção em `metadata.choice_options`
- Aplicação da ação (mesmo `match` que vive hoje em `AiJobController::resumeChoice`)
- Merge de `metadata.provider_choice_state = 'resolved'`
- Audit log via `AuditLogService`

Lança `AiProviderChoiceException` com `code` (`NOT_AWAITING_CHOICE`, `OPTION_NOT_FOUND`) — o caller decide se vira HTTP 422 ou erro CLI.

**Refactor:** `AiJobController::resumeChoice` passa a chamar `AiProviderChoiceResolver::resolve()` e mapear exceptions pra JSON 422. Os 5 testes de `AiJobResumeChoiceTest` continuam válidos sem mudança.

**Modificação:** `AiProviderChoiceBuilder` ganha 2 novas opções:
- `downgrade_model` — emitida quando há um modelo menor configurado pra esse provider. Vem antes da opção `wait`. Action: `downgrade_model`.
- `retry_same` — sempre emitida no fim da lista (após `cancel`). Action: `retry_same`.

A lógica do `downgrade_model` lê de `config('atlas.ai.providers.<provider>.fallback_model')`. Se o config não tem fallback, a opção não aparece.

**Modificação:** `config/atlas.php`:
```php
'claude_cli' => [
    // ... existente
    'fallback_model' => env('ATLAS_AI_CLAUDE_FALLBACK_MODEL', 'claude-haiku-4-5'),
],
'codex_cli' => [
    // ... existente
    'fallback_model' => env('ATLAS_AI_CODEX_FALLBACK_MODEL', 'gpt-5-4-mini'),
],
```

**Resolver actions adicionais** (em `AiProviderChoiceResolver::resolve`):
- `downgrade_model`: `job.update(['model' => $option['model'], 'status' => 'queued', 'available_at' => now(), ...])`. Mantém `provider`. Marca `provider_choice_state = 'resolved'`.
- `retry_same`: `job.update(['status' => 'queued', 'available_at' => now(), 'metadata' => merge(metadata, ['provider_choice_state' => null, 'provider_choice_resolved_via_retry' => true])])`. Reseta o flag intencionalmente — se rate limit voltar, menu re-aparece.

### CLI

**Novo:** `App\Console\Concerns\RendersProviderChoiceMenu` (trait)

```
promptProviderChoice(AiJob $job): string
  // renderiza painel formatado
  // captura escolha via $this->choice() do Symfony
  // retorna option_id (string)
```

A trait usa `$this->line()`, `$this->info()`, `$this->choice()` que vêm de `Illuminate\Console\Command`. Compatível com qualquer command que `use`-ar a trait.

**Modificação:** `AiChatCommand::runInline()` (linha 803):
- Antes de `sleep(1)`, refresh do job mais recente do trace.
- Se `job->status === 'awaiting_user_choice'`, sai do loop temporariamente:
  1. `$optionId = $this->promptProviderChoice($job)`
  2. `$result = app(AiProviderChoiceResolver::class)->resolve($job, $optionId)`
  3. Imprime mensagem por ação (switch_provider / wait / fail / cancel).
  4. Se ação é `wait`/`fail`/`cancel`: return ou exit. Se `switch_provider`: continue loop.

### App

**Novo:** `atlas-app/components/ProviderChoiceSheet.tsx` — componente Tamagui que recebe `{ jobId, options, errorCode, resetHint, onResolved }` e renderiza o sheet. Usa `useTamagui` tokens da paleta existente (luxo silencioso).

**Novo:** hook `useProviderChoice(jobId)` em `atlas-app/lib/hooks/useProviderChoice.ts`:
- Recebe job atual do polling existente.
- Se `awaiting_user_choice === true`, retorna `{ shouldShow: true, options, errorCode, resetHint, resolve(optionId) }`.
- `resolve` faz POST `/ai/jobs/:id/resume-choice` e invalida cache do polling.

**Modificação:** `atlas-app/app/mobile-thread.tsx` consome o hook e renderiza o `ProviderChoiceSheet` condicionalmente.

## Data flow (CLI feliz)

```
operador digita prompt
  → AiChatCommand::runInline cria trace + job
  → AiWorker pega job
  → Codex CLI retorna stderr "usage limit, try again at May 5th"
  → RunsCliProcesses extrai reset_at, retorna AiProviderResult{errorCode='rate_limited', metadata={...}}
  → AiWorker.completeAttempt detecta, chama pauseForChoice
    → ai_jobs.status='awaiting_user_choice', metadata.choice_options=[...], stream event provider_choice_required
  → AiChatCommand::runInline (próximo tick do loop) detecta job.status
    → render menu via RendersProviderChoiceMenu trait
    → operador digita "1"
    → AiProviderChoiceResolver::resolve(job, 'switch_provider')
    → ai_jobs.provider='claude_cli', status='queued', available_at=now()
    → loop continua
  → AiWorker pega job de novo
  → Claude CLI executa
  → trace.status='succeeded'
  → CLI imprime resposta
```

## Error handling

**CLI:**
- Se `AiProviderChoiceResolver` lança exception: imprime erro em vermelho, oferece menu de novo (1 retry), depois exit 1.
- Se job vira `cancelled` enquanto o menu está aberto (concorrência): detecta no resolve, fecha menu, imprime `Job cancelado por outro processo`, sai.
- Se polling falha por DB lock: tenta 3x com backoff curto (já é o pattern existente em `runInline`).

**App:**
- Se POST falha: toast de erro, sheet permanece aberto.
- Se job mudou enquanto sheet abre (race com worker): refetch e re-render baseado no novo estado.

## Testing

**Server:**
- `tests/Unit/Ai/AiProviderChoiceResolverTest.php` — testes unitários cobrindo cada action + exceptions. Reaproveita as 5 cenas de `AiJobResumeChoiceTest`.
- `AiJobResumeChoiceTest` continua passando sem mudanças (controller delega pro service).

**CLI:**
- `tests/Feature/Console/AiChatCommandProviderChoiceTest.php` — usa `Artisan::call` com input simulado, verifica que escolher `1` mexe o status do job pra `queued` com novo provider. Testa também o caminho `wait` (sai com exit 0 e mensagem correta).

**App:**
- `atlas-app/__tests__/components/ProviderChoiceSheet.test.tsx` — render snapshot + click handler.
- `atlas-app/__tests__/hooks/useProviderChoice.test.ts` — verifica detecção e POST.

## Open questions / assumptions

- **App auth para POST:** assumimos que o app já manda `X-Atlas-Token` ou JWT no fetch existente; se não, precisa adicionar (escopo do app, não bloqueia CLI).
- **Múltiplos jobs pausados:** se o operador tem 3 conversas e 2 pausam, o app mostra o sheet do thread que está aberto. CLI só sabe do trace que está rodando. Comportamento por design.
- **i18n:** mensagens são em português (consistente com o backend). Se um dia tiver i18n, é trabalho separado.
- **Volta automática pro modelo grande:** decidido NÃO implementar. Operador volta manualmente via opção [r] ou via comando `atlas chat upgrade-model` (escopo do plano A). Auto-revert exigia scheduler dedicado — desproporcional ao ganho.
- **Lista de fallback models:** uma única configuração estática por provider. Não tenta detectar dinamicamente do stderr do CLI (Codex às vezes oferece, Claude não — frágil). Configurável por env (`ATLAS_AI_CODEX_FALLBACK_MODEL`, `ATLAS_AI_CLAUDE_FALLBACK_MODEL`) caso o operador queira mudar.

## Implementação por etapas (planos separados)

1. **Plano A:** refactor backend pra `AiProviderChoiceResolver` + integração CLI completa. Esse plano roda primeiro.
2. **Plano B:** componentes app + hook + integração no `mobile-thread.tsx`. Roda depois.

Cada plano produz software funcional sozinho — backend resolve via service no Plano A, app pluga no Plano B.
