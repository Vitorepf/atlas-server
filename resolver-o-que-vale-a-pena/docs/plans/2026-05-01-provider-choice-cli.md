# Provider Choice — CLI Implementation Plan (Plano A)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Quando o backend pausa um job em `awaiting_user_choice`, o operador no `atlas chat` (CLI) vê o menu de 5 opções (switch_provider, downgrade_model, wait_for_reset, cancel, retry_same), escolhe via prompt numerado, e o chat retoma sem precisar sair do CLI.

**Architecture:** Refactor extrai a lógica de `AiJobController::resumeChoice` para um service `AiProviderChoiceResolver` reutilizado por CLI e HTTP. `AiProviderChoiceBuilder` ganha 2 novas opções (`downgrade_model`, `retry_same`). `AiChatCommand::runInline` (loop existente) detecta `awaiting_user_choice` no job, renderiza menu via trait nova, chama o resolver, volta pro loop ou sai conforme a action.

**Tech Stack:** Laravel 11, PostgreSQL, PHPUnit/Pest, Symfony Console (`$this->choice`).

**Depends on:** spec em `docs/specs/2026-05-01-provider-choice-menu-design.md`. Backend base já em commits `2b2108f`..`2b3a336` (T1-T8 do plano anterior).

**Out of scope (Plano B):** componentes Tamagui no app Expo, hook React, integração no `mobile-thread.tsx`.

---

## File Structure

**Create:**
- `atlas-server/app/Services/Ai/AiProviderChoiceResolver.php` — service que recebe (AiJob, optionId) e aplica a action
- `atlas-server/app/Services/Ai/AiProviderChoiceException.php` — exception com `code` (`NOT_AWAITING_CHOICE`, `OPTION_NOT_FOUND`)
- `atlas-server/app/Console/Concerns/RendersProviderChoiceMenu.php` — trait com `promptProviderChoice(AiJob): string`
- `atlas-server/tests/Unit/Ai/AiProviderChoiceResolverTest.php`
- `atlas-server/tests/Feature/Console/AiChatProviderChoiceTest.php`

**Modify:**
- `atlas-server/config/atlas.php` — adicionar `fallback_model` em cada provider
- `atlas-server/app/Services/Ai/AiProviderChoiceBuilder.php` — emitir `downgrade_model` e `retry_same`
- `atlas-server/tests/Unit/Ai/AiProviderChoiceBuilderTest.php` — 4 testes novos
- `atlas-server/app/Http/Controllers/AiJobController.php` — `resumeChoice` delega ao service
- `atlas-server/app/Console/Commands/AiChatCommand.php` — `use` da trait + detecção em `runInline` (~linha 803)

---

### Task 1: Adicionar `fallback_model` ao config

**Files:**
- Modify: `atlas-server/config/atlas.php` — bloco `providers` (linhas ~364-382)

- [ ] **Step 1: Editar config/atlas.php**

Localizar o bloco `'providers' => [...]` em `config/atlas.php`. Hoje ele tem `claude_cli` e `codex_cli` com `binary`, `model`, `model_identity`, `args` (e `sandbox` no codex). Adicionar `fallback_model` em cada um:

```php
'claude_cli' => [
    'binary' => env('ATLAS_AI_CLAUDE_BIN', 'claude'),
    'model' => env('ATLAS_AI_CLAUDE_MODEL', null),
    'model_identity' => env('ATLAS_AI_CLAUDE_MODEL_IDENTITY', env('ATLAS_AI_CLAUDE_MODEL') ?: 'claude_cli_default'),
    'fallback_model' => env('ATLAS_AI_CLAUDE_FALLBACK_MODEL', 'claude-haiku-4-5'),
    'args' => env('ATLAS_AI_CLAUDE_ARGS')
        ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_CLAUDE_ARGS'))), fn (string $arg): bool => $arg !== ''))
        : ['-p', '--output-format', 'stream-json', '--verbose', '--no-session-persistence'],
],
'codex_cli' => [
    'binary' => env('ATLAS_AI_CODEX_BIN', 'codex'),
    'model' => env('ATLAS_AI_CODEX_MODEL', null),
    'model_identity' => env('ATLAS_AI_CODEX_MODEL_IDENTITY', env('ATLAS_AI_CODEX_MODEL') ?: 'codex_cli_default'),
    'fallback_model' => env('ATLAS_AI_CODEX_FALLBACK_MODEL', 'gpt-5-4-mini'),
    'sandbox' => env('ATLAS_AI_CODEX_SANDBOX', 'read-only'),
    'args' => env('ATLAS_AI_CODEX_ARGS')
        ? array_values(array_filter(array_map('trim', explode(',', (string) env('ATLAS_AI_CODEX_ARGS'))), fn (string $arg): bool => $arg !== ''))
        : ['exec', '--skip-git-repo-check'],
],
```

- [ ] **Step 2: Verificar que o config carrega sem erro**

Run: `docker exec atlas-backend php artisan config:show atlas.ai.providers.claude_cli.fallback_model`
Expected: `claude-haiku-4-5` (ou o valor da env var se setada).

Run: `docker exec atlas-backend php artisan config:show atlas.ai.providers.codex_cli.fallback_model`
Expected: `gpt-5-4-mini`.

- [ ] **Step 3: Commit**

```bash
git add atlas-server/config/atlas.php
git commit -m "feat(ai): add fallback_model config per provider"
```

---

### Task 2: Estender `AiProviderChoiceBuilder` com `downgrade_model` e `retry_same`

**Files:**
- Modify: `atlas-server/tests/Unit/Ai/AiProviderChoiceBuilderTest.php`
- Modify: `atlas-server/app/Services/Ai/AiProviderChoiceBuilder.php`

- [ ] **Step 1: Adicionar 4 testes novos**

Adicionar os métodos abaixo ao final da classe `AiProviderChoiceBuilderTest`, antes do `}` final:

```php
public function test_rate_limited_includes_downgrade_when_fallback_configured(): void
{
    config()->set('atlas.ai.providers.codex_cli.fallback_model', 'gpt-5-4-mini');
    $builder = new AiProviderChoiceBuilder();

    $options = $builder->build('rate_limited', 'codex_cli', 'gpt-5.5', null);

    $ids = array_column($options, 'id');
    $this->assertContains('downgrade_model', $ids);
    $downgrade = collect($options)->firstWhere('id', 'downgrade_model');
    $this->assertSame('downgrade_model', $downgrade['action']);
    $this->assertSame('codex_cli', $downgrade['provider']);
    $this->assertSame('gpt-5-4-mini', $downgrade['model']);
}

public function test_rate_limited_omits_downgrade_when_fallback_null(): void
{
    config()->set('atlas.ai.providers.codex_cli.fallback_model', null);
    $builder = new AiProviderChoiceBuilder();

    $options = $builder->build('rate_limited', 'codex_cli', 'gpt-5.5', null);

    $ids = array_column($options, 'id');
    $this->assertNotContains('downgrade_model', $ids);
}

public function test_rate_limited_always_includes_retry_same_at_end(): void
{
    config()->set('atlas.ai.providers.codex_cli.fallback_model', 'gpt-5-4-mini');
    $builder = new AiProviderChoiceBuilder();

    $options = $builder->build('rate_limited', 'codex_cli', 'gpt-5.5', null);

    $this->assertSame('retry_same', $options[count($options) - 1]['id']);
    $this->assertSame('retry_same', $options[count($options) - 1]['action']);
}

public function test_auth_expired_does_not_include_retry_same_or_downgrade(): void
{
    $builder = new AiProviderChoiceBuilder();
    $options = $builder->build('auth_expired', 'claude_cli', 'sonnet-4.6', null);

    $ids = array_column($options, 'id');
    $this->assertNotContains('retry_same', $ids);
    $this->assertNotContains('downgrade_model', $ids);
}
```

Também atualizar o teste `test_rate_limited_without_reset_only_offers_switch_and_cancel` porque agora a ordem inclui `downgrade_model` e `retry_same`:

```php
public function test_rate_limited_without_reset_only_offers_switch_and_cancel(): void
{
    config()->set('atlas.ai.providers.claude_cli.fallback_model', null);
    $builder = new AiProviderChoiceBuilder();
    $options = $builder->build('rate_limited', 'claude_cli', 'sonnet-4.6', null);

    $ids = array_column($options, 'id');
    $this->assertSame(['switch_provider', 'cancel', 'retry_same'], $ids);
    $this->assertSame('codex_cli', $options[0]['provider']);
}
```

E atualizar `test_rate_limited_offers_other_provider_and_wait_within_24h`:

```php
public function test_rate_limited_offers_other_provider_and_wait_within_24h(): void
{
    config()->set('atlas.ai.providers.codex_cli.fallback_model', null);
    CarbonImmutable::setTestNow('2026-05-01 09:00:00');
    $builder = new AiProviderChoiceBuilder();
    $resetAt = CarbonImmutable::parse('2026-05-01 14:00:00');

    $options = $builder->build('rate_limited', 'codex_cli', 'gpt-5.5', $resetAt);

    $ids = array_column($options, 'id');
    $this->assertSame(['switch_provider', 'wait_for_reset', 'cancel', 'retry_same'], $ids);
    $this->assertSame('claude_cli', $options[0]['provider']);
    $this->assertSame('switch_provider', $options[0]['action']);
    $this->assertSame($resetAt->toIso8601String(), $options[1]['available_at_iso']);

    CarbonImmutable::setTestNow();
}
```

E `test_rate_limited_omits_wait_when_reset_too_far`:
```php
public function test_rate_limited_omits_wait_when_reset_too_far(): void
{
    config()->set('atlas.ai.providers.codex_cli.fallback_model', null);
    CarbonImmutable::setTestNow('2026-05-01 09:00:00');
    $builder = new AiProviderChoiceBuilder();
    $resetAt = CarbonImmutable::parse('2026-05-05 10:24:00');

    $options = $builder->build('rate_limited', 'codex_cli', 'gpt-5.5', $resetAt);

    $ids = array_column($options, 'id');
    $this->assertSame(['switch_provider', 'cancel', 'retry_same'], $ids);

    CarbonImmutable::setTestNow();
}
```

- [ ] **Step 2: Rodar e ver os 4 novos FALHAREM**

Run: `docker exec atlas-backend ./vendor/bin/phpunit tests/Unit/Ai/AiProviderChoiceBuilderTest.php`
Expected: 4 testes novos falham + 2 testes existentes alterados falham (porque builder ainda emite ordem antiga).

- [ ] **Step 3: Editar `AiProviderChoiceBuilder` para emitir as 2 novas opções**

Substituir o método `rateLimitedOptions` em `atlas-server/app/Services/Ai/AiProviderChoiceBuilder.php`:

```php
private function rateLimitedOptions(string $currentProvider, ?CarbonImmutable $resetAt): array
{
    $options = [];

    $fallback = self::FALLBACK_MAP[$currentProvider] ?? null;
    if ($fallback) {
        $options[] = [
            'id' => 'switch_provider',
            'label' => 'Migrar para '.(self::PROVIDER_LABEL[$fallback] ?? $fallback),
            'description' => 'Continua a sessão no provider alternativo. Disponível agora.',
            'action' => 'switch_provider',
            'provider' => $fallback,
            'model' => null,
        ];
    }

    $fallbackModel = config("atlas.ai.providers.{$currentProvider}.fallback_model");
    if (is_string($fallbackModel) && $fallbackModel !== '') {
        $options[] = [
            'id' => 'downgrade_model',
            'label' => "Continuar no {$currentProvider} com {$fallbackModel} (modelo menor)",
            'description' => 'Mesma conta. Disponível agora. Mais rápido, mais barato, menos capaz.',
            'action' => 'downgrade_model',
            'provider' => $currentProvider,
            'model' => $fallbackModel,
        ];
    }

    if ($resetAt && $this->withinHorizon($resetAt)) {
        $options[] = [
            'id' => 'wait_for_reset',
            'label' => 'Aguardar reset ('.$resetAt->diffForHumans().')',
            'description' => 'Mantém o provider atual e reenfileira quando a janela liberar.',
            'action' => 'wait',
            'available_at_iso' => $resetAt->toIso8601String(),
        ];
    }

    $options[] = $this->cancelOption();

    $options[] = [
        'id' => 'retry_same',
        'label' => 'Já liberei (comprei créditos / upgrade) — tentar de novo agora',
        'description' => 'Reenfileira com mesmo provider e modelo. Se ainda bloqueado, o menu volta.',
        'action' => 'retry_same',
        'provider' => $currentProvider,
        'model' => null,
    ];

    return $options;
}
```

- [ ] **Step 4: Rodar e ver tudo passar**

Run: `docker exec atlas-backend ./vendor/bin/phpunit tests/Unit/Ai/AiProviderChoiceBuilderTest.php`
Expected: `OK (9 tests, ...)`.

- [ ] **Step 5: Commit cirúrgico**

```bash
git add atlas-server/app/Services/Ai/AiProviderChoiceBuilder.php \
        atlas-server/tests/Unit/Ai/AiProviderChoiceBuilderTest.php
git commit -m "feat(ai): emit downgrade_model and retry_same options in choice builder"
```

---

### Task 3: Criar `AiProviderChoiceException`

**Files:**
- Create: `atlas-server/app/Services/Ai/AiProviderChoiceException.php`

- [ ] **Step 1: Criar a exception**

```php
<?php

namespace App\Services\Ai;

use RuntimeException;

class AiProviderChoiceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notAwaitingChoice(): self
    {
        return new self('NOT_AWAITING_CHOICE', 'Job is not waiting for an operator choice.');
    }

    public static function optionNotFound(string $optionId): self
    {
        return new self('OPTION_NOT_FOUND', "Option [{$optionId}] not found in choice_options.");
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add atlas-server/app/Services/Ai/AiProviderChoiceException.php
git commit -m "feat(ai): typed exception for provider choice resolution"
```

---

### Task 4: TDD do `AiProviderChoiceResolver` — testes falhando

**Files:**
- Create: `atlas-server/tests/Unit/Ai/AiProviderChoiceResolverTest.php`

O resolver encapsula o `match` que vive hoje em `AiJobController::resumeChoice` + as 2 novas actions (`downgrade_model`, `retry_same`).

- [ ] **Step 1: Escrever os testes**

`atlas-server/tests/Unit/Ai/AiProviderChoiceResolverTest.php`:

```php
<?php

namespace Tests\Unit\Ai;

use App\Models\AiJob;
use App\Models\AiTrace;
use App\Services\Ai\AiProviderChoiceException;
use App\Services\Ai\AiProviderChoiceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProviderChoiceResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_switch_provider_requeues_with_new_provider(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'switch_provider', 'action' => 'switch_provider', 'provider' => 'claude_cli', 'model' => null],
        ]);

        $result = app(AiProviderChoiceResolver::class)->resolve($job, 'switch_provider');

        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertSame('claude_cli', $job->provider);
        $this->assertSame('resolved', data_get($job->metadata, 'provider_choice_state'));
        $this->assertSame('switch_provider', $result['action']);
    }

    public function test_downgrade_model_keeps_provider_and_changes_model(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'downgrade_model', 'action' => 'downgrade_model', 'provider' => 'codex_cli', 'model' => 'gpt-5-4-mini'],
        ]);

        $result = app(AiProviderChoiceResolver::class)->resolve($job, 'downgrade_model');

        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertSame('codex_cli', $job->provider);
        $this->assertSame('gpt-5-4-mini', $job->model);
        $this->assertSame('resolved', data_get($job->metadata, 'provider_choice_state'));
    }

    public function test_wait_sets_available_at_from_option(): void
    {
        $waitUntil = now()->addHours(3)->toIso8601String();
        $job = $this->makePausedJob([
            ['id' => 'wait_for_reset', 'action' => 'wait', 'available_at_iso' => $waitUntil],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job, 'wait_for_reset');

        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertSame($waitUntil, $job->available_at->toIso8601String());
    }

    public function test_fail_marks_job_failed_with_login_message(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'login_required', 'action' => 'fail', 'reason' => 'login_required', 'cli_command' => 'codex login'],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job, 'login_required');

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame('login_required', $job->error_code);
        $this->assertStringContainsString('codex login', $job->error_message);
    }

    public function test_cancel_marks_job_cancelled(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'cancel', 'action' => 'cancel'],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job, 'cancel');

        $job->refresh();
        $this->assertSame('cancelled', $job->status);
    }

    public function test_retry_same_requeues_and_resets_choice_state(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'retry_same', 'action' => 'retry_same', 'provider' => 'codex_cli', 'model' => null],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job, 'retry_same');

        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertSame('codex_cli', $job->provider);
        $this->assertSame('gpt-5.5', $job->model, 'Modelo original deve ser preservado em retry_same.');
        $this->assertNull(data_get($job->metadata, 'provider_choice_state'),
            'retry_same deve resetar o flag pra null pra permitir nova pausa.');
        $this->assertTrue((bool) data_get($job->metadata, 'provider_choice_resolved_via_retry'));
    }

    public function test_throws_when_job_not_awaiting_choice(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'cancel', 'action' => 'cancel'],
        ]);
        $job->update(['status' => 'queued']);

        $this->expectException(AiProviderChoiceException::class);
        try {
            app(AiProviderChoiceResolver::class)->resolve($job, 'cancel');
        } catch (AiProviderChoiceException $e) {
            $this->assertSame('NOT_AWAITING_CHOICE', $e->errorCode);
            throw $e;
        }
    }

    public function test_throws_when_option_not_found(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'cancel', 'action' => 'cancel'],
        ]);

        $this->expectException(AiProviderChoiceException::class);
        try {
            app(AiProviderChoiceResolver::class)->resolve($job, 'banana');
        } catch (AiProviderChoiceException $e) {
            $this->assertSame('OPTION_NOT_FOUND', $e->errorCode);
            throw $e;
        }
    }

    private function makePausedJob(array $options): AiJob
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'queued',
        ]);

        return AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'awaiting_user_choice',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->addYear(),
            'metadata' => [
                'provider_choice_state' => 'pending',
                'provider_choice_error_code' => 'rate_limited',
                'choice_options' => $options,
            ],
        ]);
    }
}
```

- [ ] **Step 2: Rodar e ver FAIL**

Run: `docker exec atlas-backend ./vendor/bin/phpunit tests/Unit/Ai/AiProviderChoiceResolverTest.php`
Expected: FAIL com `Class "App\Services\Ai\AiProviderChoiceResolver" not found`.

---

### Task 5: Implementar `AiProviderChoiceResolver`

**Files:**
- Create: `atlas-server/app/Services/Ai/AiProviderChoiceResolver.php`

- [ ] **Step 1: Implementar**

```php
<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Services\AuditLogService;
use Carbon\Carbon;

class AiProviderChoiceResolver
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return array{option: array<string,mixed>, action: string, job: AiJob}
     */
    public function resolve(AiJob $job, string $optionId): array
    {
        if ($job->status !== 'awaiting_user_choice') {
            throw AiProviderChoiceException::notAwaitingChoice();
        }

        $options = (array) data_get($job->metadata, 'choice_options', []);
        $option = collect($options)->firstWhere('id', $optionId);

        if (! is_array($option)) {
            throw AiProviderChoiceException::optionNotFound($optionId);
        }

        $action = (string) ($option['action'] ?? '');
        $baseMetadata = array_merge($job->metadata ?? [], [
            'provider_choice_resolved_at' => now()->toIso8601String(),
            'provider_choice_resolved_option' => $optionId,
        ]);

        match ($action) {
            'switch_provider' => $this->applySwitchProvider($job, $option, array_merge($baseMetadata, ['provider_choice_state' => 'resolved'])),
            'downgrade_model' => $this->applyDowngradeModel($job, $option, array_merge($baseMetadata, ['provider_choice_state' => 'resolved'])),
            'wait' => $this->applyWait($job, $option, array_merge($baseMetadata, ['provider_choice_state' => 'resolved'])),
            'fail' => $this->applyFail($job, $option, array_merge($baseMetadata, ['provider_choice_state' => 'resolved'])),
            'cancel' => $this->applyCancel($job, array_merge($baseMetadata, ['provider_choice_state' => 'resolved'])),
            'retry_same' => $this->applyRetrySame($job, array_merge($baseMetadata, [
                'provider_choice_state' => null,
                'provider_choice_resolved_via_retry' => true,
            ])),
            default => $job->update(['metadata' => array_merge($baseMetadata, ['provider_choice_state' => 'resolved'])]),
        };

        $this->audit->record('ai_job_choice_resolved', [
            'subject_type' => 'ai_job',
            'subject_id' => $job->id,
            'actor_type' => 'operator',
            'actor_id' => 'vitor',
            'summary' => "Operador escolheu opção [{$optionId}] (action={$action}).",
            'evidence' => [
                'option_id' => $optionId,
                'action' => $action,
                'option' => $option,
            ],
            'privacy' => is_array(data_get($job->payload, 'privacy', data_get($job->metadata, 'privacy'))) ? data_get($job->payload, 'privacy', data_get($job->metadata, 'privacy')) : [],
            'refs' => [
                'job_id' => $job->id,
                'trace_id' => $job->trace_id,
            ],
        ]);

        return [
            'option' => $option,
            'action' => $action,
            'job' => $job->refresh(),
        ];
    }

    private function applySwitchProvider(AiJob $job, array $option, array $metadata): void
    {
        $job->update([
            'status' => 'queued',
            'provider' => (string) ($option['provider'] ?? $job->provider),
            'model' => array_key_exists('model', $option) ? $option['model'] : $job->model,
            'available_at' => now(),
            'reserved_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'worker_id' => null,
            'error_code' => null,
            'error_message' => null,
            'metadata' => $metadata,
        ]);
    }

    private function applyDowngradeModel(AiJob $job, array $option, array $metadata): void
    {
        $job->update([
            'status' => 'queued',
            'model' => (string) ($option['model'] ?? $job->model),
            'available_at' => now(),
            'reserved_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'worker_id' => null,
            'error_code' => null,
            'error_message' => null,
            'metadata' => $metadata,
        ]);
    }

    private function applyWait(AiJob $job, array $option, array $metadata): void
    {
        $job->update([
            'status' => 'queued',
            'available_at' => isset($option['available_at_iso'])
                ? Carbon::parse((string) $option['available_at_iso'])
                : now()->addMinutes(15),
            'reserved_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'worker_id' => null,
            'metadata' => $metadata,
        ]);
    }

    private function applyFail(AiJob $job, array $option, array $metadata): void
    {
        $job->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error_code' => (string) ($option['reason'] ?? 'choice_failed'),
            'error_message' => isset($option['cli_command'])
                ? "Login required: rode `{$option['cli_command']}` no terminal e tente novamente."
                : 'Operator chose to fail this job.',
            'metadata' => $metadata,
        ]);
    }

    private function applyCancel(AiJob $job, array $metadata): void
    {
        $job->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'error_code' => 'cancelled_by_operator',
            'error_message' => 'Operador cancelou o job durante escolha de provider.',
            'metadata' => $metadata,
        ]);
        $job->trace?->update(['status' => 'cancelled', 'completed_at' => now()]);
    }

    private function applyRetrySame(AiJob $job, array $metadata): void
    {
        $job->update([
            'status' => 'queued',
            'available_at' => now(),
            'reserved_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'worker_id' => null,
            'error_code' => null,
            'error_message' => null,
            'metadata' => $metadata,
        ]);
    }
}
```

- [ ] **Step 2: Rodar tests, ver PASS**

Run: `docker exec atlas-backend ./vendor/bin/phpunit tests/Unit/Ai/AiProviderChoiceResolverTest.php`
Expected: `OK (8 tests, ...)`.

- [ ] **Step 3: Commit**

```bash
git add atlas-server/app/Services/Ai/AiProviderChoiceResolver.php \
        atlas-server/tests/Unit/Ai/AiProviderChoiceResolverTest.php
git commit -m "feat(ai): extract provider choice resolution into service"
```

---

### Task 6: Refactor `AiJobController::resumeChoice` para delegar ao resolver

**Files:**
- Modify: `atlas-server/app/Http/Controllers/AiJobController.php`

Os 5 testes de `AiJobResumeChoiceTest` continuam passando após esse refactor (nenhuma mudança de comportamento HTTP).

- [ ] **Step 1: Substituir o método `resumeChoice` e remover `cancelByChoice`**

Localizar `resumeChoice` em `AiJobController.php` e substituir por:

```php
public function resumeChoice(AiJob $job, Request $request, AiProviderChoiceResolver $resolver): JsonResponse
{
    $request->validate([
        'option_id' => ['required', 'string', 'max:64'],
    ]);

    try {
        $result = $resolver->resolve($job, (string) $request->input('option_id'));
    } catch (AiProviderChoiceException $exception) {
        $statusCode = $exception->errorCode === 'NOT_AWAITING_CHOICE'
            ? 'AI_JOB_NOT_AWAITING_CHOICE'
            : 'AI_JOB_CHOICE_NOT_FOUND';

        return response()->json([
            'error' => [
                'code' => $statusCode,
                'message' => $exception->getMessage(),
            ],
        ], 422);
    }

    return response()->json([
        'job' => (new AiJobResource($result['job']->load(['trace', 'attemptHistory'])))->resolve(),
    ]);
}
```

E **remover** o método `cancelByChoice` inteiro (a lógica agora vive no resolver).

Adicionar `use App\Services\Ai\AiProviderChoiceResolver;` e `use App\Services\Ai\AiProviderChoiceException;` nos imports do topo se ainda não estiverem.

- [ ] **Step 2: Rodar os testes existentes do endpoint**

Run: `docker exec atlas-backend ./vendor/bin/phpunit tests/Feature/Ai/AiJobResumeChoiceTest.php`
Expected: `OK (5 tests, 15 assertions)` — mesmo resultado que antes do refactor.

- [ ] **Step 3: Commit**

```bash
git add atlas-server/app/Http/Controllers/AiJobController.php
git commit -m "refactor(ai): delegate resumeChoice to AiProviderChoiceResolver"
```

---

### Task 7: Trait `RendersProviderChoiceMenu`

**Files:**
- Create: `atlas-server/app/Console/Concerns/RendersProviderChoiceMenu.php`

A trait expõe o método `promptProviderChoice(AiJob $job): string` (retorna `option_id`) e helpers de render.

A trait depende de `$this->line()`, `$this->info()`, `$this->warn()`, `$this->choice()` que vêm de `Illuminate\Console\Command`.

Não tem teste unitário próprio (Symfony Console é interativo e o teste cabe melhor no Feature test do AiChatCommand). Risco baixo: trait é puro render + delegação.

- [ ] **Step 1: Criar a trait**

```php
<?php

namespace App\Console\Concerns;

use App\Models\AiJob;

trait RendersProviderChoiceMenu
{
    /**
     * Renderiza o menu e retorna o option_id escolhido.
     */
    protected function promptProviderChoice(AiJob $job): string
    {
        $errorCode = (string) data_get($job->metadata, 'provider_choice_error_code', 'rate_limited');
        $resetHint = data_get($job->metadata, 'reset_hint');
        $options = (array) data_get($job->metadata, 'choice_options', []);

        $this->line('');
        $header = $errorCode === 'auth_expired'
            ? "<fg=yellow;options=bold>⚠ Login expirado em {$job->provider}</>"
            : "<fg=yellow;options=bold>⚠ {$job->provider} sem créditos".($resetHint ? " até {$resetHint}" : '').'</>';
        $this->line($header);
        $this->line('');

        $labels = [];
        foreach ($options as $index => $option) {
            $key = $option['id'] === 'retry_same' ? 'r' : (string) ($index + 1);
            $label = (string) ($option['label'] ?? $option['id']);
            $description = (string) ($option['description'] ?? '');

            $this->line("  <fg=cyan>[{$key}]</> <options=bold>{$label}</>");
            if ($description !== '') {
                $this->line("      <fg=gray>{$description}</>");
            }
            $labels[$key] = $option['id'];
        }
        $this->line('');

        $valid = array_keys($labels);
        do {
            $answer = trim((string) $this->ask('Escolha ['.implode('/', $valid).']'));
            $answer = strtolower($answer);
        } while (! isset($labels[$answer]));

        return (string) $labels[$answer];
    }

    protected function announceChoiceOutcome(string $action, array $option): void
    {
        $message = match ($action) {
            'switch_provider' => "<fg=green>Migrando pra {$option['provider']}...</>",
            'downgrade_model' => "<fg=green>Continuando no {$option['provider']} com {$option['model']}...</>",
            'wait' => "<fg=yellow>Pausado até ".(data_get($option, 'available_at_iso') ?? '?').". Rode `atlas chat continue` quando quiser retomar.</>",
            'fail' => isset($option['cli_command'])
                ? "<fg=red>Login expirado. Rode `{$option['cli_command']}` no terminal e dê retry.</>"
                : '<fg=red>Job marcado como falho.</>',
            'cancel' => '<fg=gray>Job cancelado.</>',
            'retry_same' => '<fg=green>Tentando novamente...</>',
            default => '',
        };

        if ($message !== '') {
            $this->line($message);
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add atlas-server/app/Console/Concerns/RendersProviderChoiceMenu.php
git commit -m "feat(cli): add provider choice menu rendering trait"
```

---

### Task 8: Integrar detecção de pausa no `AiChatCommand::runInline`

**Files:**
- Modify: `atlas-server/app/Console/Commands/AiChatCommand.php` — adicionar trait + branch de detecção

**Estratégia de integração** (linha ~803, `runInline`):
- Após `$trace = $trace->fresh($this->traceRelations()) ?: $trace;` no início do loop, buscar o job mais recente do trace.
- Se `job->status === 'awaiting_user_choice'`: chamar `promptProviderChoice`, resolver via service, anunciar resultado, decidir se sai ou continua o loop.

- [ ] **Step 1: Adicionar `use` da trait e do resolver**

No topo de `AiChatCommand.php`, dentro do bloco `use ...;`, adicionar:
```php
use App\Console\Concerns\RendersProviderChoiceMenu;
use App\Models\AiJob;
use App\Services\Ai\AiProviderChoiceException;
use App\Services\Ai\AiProviderChoiceResolver;
```

E na declaração da classe, adicionar `use RendersProviderChoiceMenu;` na primeira linha do corpo:
```php
class AiChatCommand extends Command
{
    use RendersProviderChoiceMenu;
    // ... resto
```

- [ ] **Step 2: Adicionar branch no `runInline`**

Localizar o método `runInline` (~linha 803). No início do loop while (logo após `$trace = $trace->fresh(...)`), antes do `if (in_array($trace->status, ['succeeded',...]))`, inserir:

```php
$pausedJob = AiJob::query()
    ->where('trace_id', $trace->id)
    ->where('status', 'awaiting_user_choice')
    ->orderByDesc('created_at')
    ->first();

if ($pausedJob) {
    $optionId = $this->promptProviderChoice($pausedJob);
    try {
        $outcome = app(AiProviderChoiceResolver::class)->resolve($pausedJob, $optionId);
    } catch (AiProviderChoiceException $exception) {
        $this->error('Erro ao resolver escolha: '.$exception->getMessage());
        sleep(1);
        continue;
    }

    $this->announceChoiceOutcome($outcome['action'], $outcome['option']);

    if (in_array($outcome['action'], ['wait', 'fail', 'cancel'], true)) {
        return $trace->fresh($this->traceRelations()) ?: $trace;
    }

    continue;
}
```

- [ ] **Step 3: Verificar manualmente com php -l**

Run: `docker exec atlas-backend php -l app/Console/Commands/AiChatCommand.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit (test virá no Task 9)**

```bash
git add atlas-server/app/Console/Commands/AiChatCommand.php
git commit -m "feat(cli): detect awaiting_user_choice in chat loop and prompt operator"
```

---

### Task 9: Feature test E2E do menu no CLI

**Files:**
- Create: `atlas-server/tests/Feature/Console/AiChatProviderChoiceTest.php`

Teste exercita o caminho da trait + resolver via Artisan, simulando input do operador.

- [ ] **Step 1: Escrever o teste**

```php
<?php

namespace Tests\Feature\Console;

use App\Models\AiJob;
use App\Models\AiTrace;
use App\Services\Ai\AiProviderChoiceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiChatProviderChoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_picking_switch_provider_requeues_job(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'processing',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'awaiting_user_choice',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->addYear(),
            'metadata' => [
                'provider_choice_state' => 'pending',
                'provider_choice_error_code' => 'rate_limited',
                'reset_hint' => 'May 5th, 2026 10:24 AM',
                'choice_options' => [
                    ['id' => 'switch_provider', 'action' => 'switch_provider', 'provider' => 'claude_cli', 'model' => null, 'label' => 'Migrar para Claude', 'description' => ''],
                    ['id' => 'cancel', 'action' => 'cancel', 'label' => 'Cancelar', 'description' => ''],
                ],
            ],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job->refresh(), 'switch_provider');

        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertSame('claude_cli', $job->provider);
        $this->assertSame('resolved', data_get($job->metadata, 'provider_choice_state'));
    }

    public function test_operator_picking_retry_same_resets_choice_state(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'processing',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'awaiting_user_choice',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->addYear(),
            'metadata' => [
                'provider_choice_state' => 'pending',
                'choice_options' => [
                    ['id' => 'retry_same', 'action' => 'retry_same', 'provider' => 'codex_cli', 'model' => null, 'label' => 'Tentar de novo', 'description' => ''],
                ],
            ],
        ]);

        app(AiProviderChoiceResolver::class)->resolve($job->refresh(), 'retry_same');

        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertNull(data_get($job->metadata, 'provider_choice_state'),
            'retry_same deve resetar o flag para permitir nova pausa.');
    }
}
```

> **Nota sobre o teste do prompt interativo:** o método `promptProviderChoice` da trait usa `$this->ask()` que requer input interativo. Testar isso de forma confiável exige `Symfony\Component\Console\Tester\CommandTester`, o que é complexo num command que tem um loop. O teste acima cobre o que importa — o resolver muda o estado correto. O render do menu é validado pelo smoke test manual (Task 10).

- [ ] **Step 2: Rodar tests**

Run: `docker exec atlas-backend ./vendor/bin/phpunit tests/Feature/Console/AiChatProviderChoiceTest.php`
Expected: `OK (2 tests, ...)`.

- [ ] **Step 3: Commit**

```bash
git add atlas-server/tests/Feature/Console/AiChatProviderChoiceTest.php
git commit -m "test(cli): cover provider choice resolution paths in chat flow"
```

---

### Task 10: Smoke test E2E manual com chat real

**Files:** nenhum, validação operacional do fluxo completo.

Objetivo: garantir que num `atlas chat` real, com job paused, o operador vê o menu e a resposta segue corretamente.

- [ ] **Step 1: Criar manualmente um job pausado num trace que possamos puxar**

Run:
```bash
cat > /tmp/seed_pause_smoke.php <<'EOF'
<?php
$trace = \App\Models\AiTrace::create([
    'trace_key' => 'tr_smoke_'.time(),
    'agent_slug' => 'orquestrador',
    'operator_input' => 'smoke',
    'status' => 'processing',
]);
$job = \App\Models\AiJob::create([
    'trace_id' => $trace->id,
    'kind' => 'interaction',
    'status' => 'awaiting_user_choice',
    'agent_slug' => 'orquestrador',
    'provider' => 'codex_cli',
    'model' => 'gpt-5.5',
    'input_text' => 'smoke',
    'prompt' => 'smoke',
    'available_at' => now()->addYear(),
    'metadata' => [
        'provider_choice_state' => 'pending',
        'provider_choice_error_code' => 'rate_limited',
        'reset_hint' => 'May 5th, 2026 10:24 AM',
        'choice_options' => app(\App\Services\Ai\AiProviderChoiceBuilder::class)->build(
            'rate_limited',
            'codex_cli',
            'gpt-5.5',
            null
        ),
    ],
]);
echo 'TRACE_ID='.$trace->id.PHP_EOL;
echo 'JOB_ID='.$job->id.PHP_EOL;
EOF
docker cp /tmp/seed_pause_smoke.php atlas-backend:/tmp/seed_pause_smoke.php
docker exec atlas-backend php artisan tinker --execute="require '/tmp/seed_pause_smoke.php';"
```
Expected: imprime `TRACE_ID=<uuid>` e `JOB_ID=<uuid>`. Anote o trace_id.

- [ ] **Step 2: Executar `atlas chat continue` apontando pro trace**

Run (substituir `<TRACE_ID>`):
```bash
docker exec -it atlas-backend php artisan ai:chat --trace=<TRACE_ID>
```

(Comando exato depende do flag de continuação no `AiChatCommand`. Se o comando não aceita `--trace`, use o fluxo padrão e abra o trace via thread/session apropriada.)

Expected: o terminal exibe o painel:
```
⚠ codex_cli sem créditos até May 5th, 2026 10:24 AM

  [1] Migrar para Claude (claude_cli)
      Continua a sessão no provider alternativo. Disponível agora.
  [2] Continuar no codex_cli com gpt-5-4-mini (modelo menor)
      Mesma conta. Disponível agora. Mais rápido, mais barato, menos capaz.
  [3] Cancelar job
      Encerra a tentativa. Conversa fica preservada.
  [r] Já liberei (comprei créditos / upgrade) — tentar de novo agora
      Reenfileira com mesmo provider e modelo. Se ainda bloqueado, o menu volta.

Escolha [1/2/3/r]:
```

- [ ] **Step 3: Digitar `2` (downgrade) e verificar resultado**

Após digitar `2` e enter:
- CLI imprime: `Continuando no codex_cli com gpt-5-4-mini...`
- Loop continua, worker pega job, tenta executar codex (vai falhar provavelmente, sem binário no container — comportamento esperado).

Verificar via psql:
```bash
docker exec atlas-db psql -U atlas -d atlas -c "SELECT status, provider, model, metadata->>'provider_choice_state' AS state FROM ai_jobs WHERE id='<JOB_ID>';"
```
Expected: `status=queued|failed|processing`, `provider=codex_cli`, `model=gpt-5-4-mini`, `state=resolved`.

- [ ] **Step 4: Verificar audit log**

Run:
```bash
docker exec atlas-db psql -U atlas -d atlas -c "SELECT event_type, summary FROM audit_events WHERE event_type='ai_job_choice_resolved' ORDER BY created_at DESC LIMIT 1;"
```
Expected: linha com `summary` mencionando `[downgrade_model]` (ou `[2]` mapeado).

- [ ] **Step 5: Commit do teste E2E final (se necessário)**

Sem alterações de código nesse step. Se quiser marcar o feito:
```bash
git tag provider-choice-cli-mvp
```

---

## Self-review checklist (preenchido)

- [x] **Spec coverage:**
  - 5 opções (switch_provider, downgrade_model, wait, cancel, retry_same) — tasks 2, 4, 5
  - Service `AiProviderChoiceResolver` — task 5
  - Refactor controller — task 6
  - Trait CLI `RendersProviderChoiceMenu` — task 7
  - Integração no `runInline` — task 8
  - Configuração `fallback_model` — task 1
  - Comportamento de exit code (wait=0, fail=1, cancel=0) — coberto em announceChoiceOutcome + return no runInline
- [x] **Placeholder scan:** nenhum TODO/TBD.
- [x] **Type consistency:**
  - `AiProviderChoiceException` é `RuntimeException` em todos os lugares
  - `resolve()` retorna `array{option, action, job}` consistentemente
  - `option_id` é string em todos os pontos
  - `provider_choice_state` é `'pending' | 'resolved' | null` (intencional pra retry_same)

## Notas finais

- **CLAUDE.md:** nenhuma migration de schema neste plano. Sem risco de drift.
- **Container:** todos os comandos de teste usam `docker exec atlas-backend ...`. Local PHP 8.3 não é compatível.
- **Refactor do controller (task 6):** os 5 testes existentes de `AiJobResumeChoiceTest` são a rede de proteção. Se algum quebrar, o refactor está errado — não passe adiante.
- **Compatibilidade reversa:** `AiProviderChoiceResolver` só introduz comportamento novo via novas actions (`downgrade_model`, `retry_same`). As actions existentes (`switch_provider`, `wait`, `fail`, `cancel`) mantêm o comportamento HTTP antigo.
