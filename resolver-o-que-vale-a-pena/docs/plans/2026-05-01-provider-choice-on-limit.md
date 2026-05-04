# Provider Choice on Rate Limit / Auth Expired — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Quando um provider CLI (claude_cli ou codex_cli) bater `rate_limited` ou `auth_expired`, o Atlas para o job num novo estado `awaiting_user_choice`, persiste opções viáveis (modelo menor no mesmo provider, fallback no outro provider, aguardar reset, cancelar) e expõe um endpoint pro operador escolher — em vez do retry burro de 5 min na mesma config.

**Architecture:**
- Parser de stderr extrai `provider_reset_at` quando o provider informa janela ("Try again at May 5th 10:24"). Vira metadata do `AiProviderResult`.
- `AiWorker.completeAttempt` ganha branch antes da lógica de retry: se `errorCode ∈ {rate_limited, auth_expired}` e o job ainda não foi pausado, calcula opções via `AiProviderChoiceBuilder`, grava em `ai_jobs.metadata.choice_options`, marca status `awaiting_user_choice`, emite stream event `provider_choice_required`. Não consome attempt nessa pausa.
- Novo endpoint `POST /api/ai/jobs/{job}/resume-choice` recebe `{option_id}`, aplica overrides (provider/model/wait_until) e reenfileira (`status='queued'`, `available_at` apropriado). Emite `provider_choice_resolved`.
- TUI/app vai consumir o stream event num plano separado.

**Tech Stack:** Laravel 11, PostgreSQL, PHPUnit/Pest, Symfony Process, Carbon.

**Out of scope (planos separados):** backoff exponencial, integração de health snapshot com claim de job, renderização do menu na TUI/app, "approaching rate limit" preventivo.

---

## File Structure

**Create:**
- `atlas-server/database/migrations/2026_05_01_120000_allow_ai_job_awaiting_user_choice.php` — relaxa CHECK do status
- `atlas-server/database/migrations/2026_05_01_121000_allow_provider_choice_worker_events.php` — adiciona event_types
- `atlas-server/app/Services/Ai/Concerns/RateLimitParser.php` — trait/helper estático com regex de data
- `atlas-server/app/Services/Ai/AiProviderChoiceBuilder.php` — monta lista de opções
- `atlas-server/tests/Unit/Ai/RateLimitParserTest.php`
- `atlas-server/tests/Unit/Ai/AiProviderChoiceBuilderTest.php`
- `atlas-server/tests/Feature/Ai/AiWorkerProviderChoiceTest.php`
- `atlas-server/tests/Feature/Ai/AiJobResumeChoiceTest.php`

**Modify:**
- `atlas-server/app/Services/Ai/Concerns/RunsCliProcesses.php` — enriquece metadata do `AiProviderResult` com `provider_reset_at`/`reset_hint`
- `atlas-server/app/Services/Ai/AiWorker.php:547-558` — branch novo antes do retry path
- `atlas-server/app/Http/Controllers/AiJobController.php` — novo método `resumeChoice`
- `atlas-server/routes/api.php:212` — registra rota
- `atlas-server/app/Http/Resources/AiJobResource.php` — expõe `choice_options` e `provider_reset_at` no payload

---

### Task 1: Migration — adicionar status `awaiting_user_choice` em `ai_jobs`

**Files:**
- Create: `atlas-server/database/migrations/2026_05_01_120000_allow_ai_job_awaiting_user_choice.php`

A constraint atual (`2026_04_28_060000_create_ai_gateway_tables.php:83-89`) é:
```sql
CHECK (status IN ('queued','processing','succeeded','failed','cancelled'))
```

Precisa virar:
```sql
CHECK (status IN ('queued','processing','succeeded','failed','cancelled','awaiting_user_choice'))
```

Mesmo padrão da migration `2026_04_29_190000_allow_ai_worker_job_cancelled_events.php`: DROP CONSTRAINT IF EXISTS + ADD CONSTRAINT.

- [ ] **Step 1: Criar a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ai_jobs
            DROP CONSTRAINT IF EXISTS ai_jobs_status_check;
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ai_jobs
            ADD CONSTRAINT ai_jobs_status_check
            CHECK (status IN (
                'queued',
                'processing',
                'succeeded',
                'failed',
                'cancelled',
                'awaiting_user_choice'
            ));
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ai_jobs
            DROP CONSTRAINT IF EXISTS ai_jobs_status_check;
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ai_jobs
            ADD CONSTRAINT ai_jobs_status_check
            CHECK (status IN (
                'queued',
                'processing',
                'succeeded',
                'failed',
                'cancelled'
            ));
        SQL);
    }
};
```

- [ ] **Step 2: Rodar a migration localmente**

Run: `cd atlas-server && docker compose run --rm --no-deps backend php artisan migrate --force`
Expected: `2026_05_01_120000_allow_ai_job_awaiting_user_choice ............ DONE`

- [ ] **Step 3: Validar a constraint via psql**

Run:
```bash
docker exec atlas-db psql -U atlas -d atlas -c "\d+ ai_jobs" | grep status_check
```
Expected: aparece `awaiting_user_choice` na lista.

- [ ] **Step 4: Commit**

```bash
git add atlas-server/database/migrations/2026_05_01_120000_allow_ai_job_awaiting_user_choice.php
git commit -m "feat(ai): add awaiting_user_choice status to ai_jobs"
```

---

### Task 2: Migration — adicionar event_types `provider_choice_*` em `ai_worker_events`

**Files:**
- Create: `atlas-server/database/migrations/2026_05_01_121000_allow_provider_choice_worker_events.php`

A versão vigente da CHECK é a de `2026_04_29_190000_allow_ai_worker_job_cancelled_events.php`. Adicionar `provider_choice_required` e `provider_choice_resolved`.

- [ ] **Step 1: Criar a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ai_worker_events
            DROP CONSTRAINT IF EXISTS ai_worker_events_event_type_check;
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ai_worker_events
            ADD CONSTRAINT ai_worker_events_event_type_check
            CHECK (event_type IN (
                'worker_started',
                'worker_heartbeat',
                'worker_stopped',
                'job_claimed',
                'job_succeeded',
                'job_failed',
                'job_requeued',
                'job_cancelled',
                'provider_unavailable',
                'auth_expired',
                'rate_limited',
                'timeout',
                'cli_error',
                'health_check',
                'provider_choice_required',
                'provider_choice_resolved'
            ));
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ai_worker_events
            DROP CONSTRAINT IF EXISTS ai_worker_events_event_type_check;
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE ai_worker_events
            ADD CONSTRAINT ai_worker_events_event_type_check
            CHECK (event_type IN (
                'worker_started',
                'worker_heartbeat',
                'worker_stopped',
                'job_claimed',
                'job_succeeded',
                'job_failed',
                'job_requeued',
                'job_cancelled',
                'provider_unavailable',
                'auth_expired',
                'rate_limited',
                'timeout',
                'cli_error',
                'health_check'
            ));
        SQL);
    }
};
```

- [ ] **Step 2: Rodar a migration**

Run: `cd atlas-server && docker compose run --rm --no-deps backend php artisan migrate --force`
Expected: `2026_05_01_121000_allow_provider_choice_worker_events ............ DONE`

- [ ] **Step 3: Commit**

```bash
git add atlas-server/database/migrations/2026_05_01_121000_allow_provider_choice_worker_events.php
git commit -m "feat(ai): allow provider_choice_* worker event types"
```

---

### Task 3: `RateLimitParser` — extrair `reset_at` do stderr

**Files:**
- Create: `atlas-server/app/Services/Ai/Concerns/RateLimitParser.php`
- Test: `atlas-server/tests/Unit/Ai/RateLimitParserTest.php`

Padrões a reconhecer (vistos em prod no Codex/Claude):
1. `try again at May 5th, 2026 10:24 AM` (Codex)
2. `try again at 2026-05-05T10:24:00Z` (ISO)
3. `try again in 47 minutes` (delta relativo)
4. `try again in 2h 15m` (delta humano)
5. `reset at 2026-05-05 10:24` (Anthropic)

Saída: `['provider_reset_at' => ?CarbonImmutable, 'reset_hint' => ?string]`. `reset_hint` é a string original (pra mostrar pro operador). `provider_reset_at` é o Carbon parsed (ou null se só conseguimos o hint mas não a data).

- [ ] **Step 1: Escrever o teste failing**

`atlas-server/tests/Unit/Ai/RateLimitParserTest.php`:
```php
<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Concerns\RateLimitParser;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class RateLimitParserTest extends TestCase
{
    public function test_parses_codex_style_absolute_date(): void
    {
        $stderr = "You've hit your usage limit. Visit https://chatgpt.com/codex/settings/usage to purchase more credits or try again at May 5th, 2026 10:24 AM.";
        $result = RateLimitParser::extract('', $stderr);

        $this->assertSame('May 5th, 2026 10:24 AM', $result['reset_hint']);
        $this->assertInstanceOf(CarbonImmutable::class, $result['provider_reset_at']);
        $this->assertSame('2026-05-05 10:24:00', $result['provider_reset_at']->format('Y-m-d H:i:s'));
    }

    public function test_parses_iso_8601_reset(): void
    {
        $stderr = 'Rate limited. Reset at 2026-05-05T10:24:00Z.';
        $result = RateLimitParser::extract('', $stderr);

        $this->assertNotNull($result['provider_reset_at']);
        $this->assertSame('2026-05-05 10:24:00', $result['provider_reset_at']->format('Y-m-d H:i:s'));
    }

    public function test_parses_relative_minutes_using_now(): void
    {
        CarbonImmutable::setTestNow('2026-05-01 09:00:00');
        $stderr = 'Rate limited. Try again in 47 minutes.';
        $result = RateLimitParser::extract('', $stderr);

        $this->assertSame('2026-05-01 09:47:00', $result['provider_reset_at']->format('Y-m-d H:i:s'));
        CarbonImmutable::setTestNow();
    }

    public function test_parses_relative_compact_hours_minutes(): void
    {
        CarbonImmutable::setTestNow('2026-05-01 09:00:00');
        $stderr = 'Try again in 2h 15m.';
        $result = RateLimitParser::extract('', $stderr);

        $this->assertSame('2026-05-01 11:15:00', $result['provider_reset_at']->format('Y-m-d H:i:s'));
        CarbonImmutable::setTestNow();
    }

    public function test_returns_null_when_no_reset_present(): void
    {
        $stderr = 'Rate limited. Please slow down.';
        $result = RateLimitParser::extract('', $stderr);

        $this->assertNull($result['provider_reset_at']);
        $this->assertNull($result['reset_hint']);
    }

    public function test_uses_stdout_when_stderr_empty(): void
    {
        $stdout = 'try again at 2026-05-05 10:24';
        $result = RateLimitParser::extract($stdout, '');

        $this->assertNotNull($result['provider_reset_at']);
    }
}
```

- [ ] **Step 2: Rodar — deve falhar**

Run: `cd atlas-server && ./vendor/bin/phpunit tests/Unit/Ai/RateLimitParserTest.php`
Expected: FAIL com `Class "App\Services\Ai\Concerns\RateLimitParser" not found`.

- [ ] **Step 3: Implementar**

`atlas-server/app/Services/Ai/Concerns/RateLimitParser.php`:
```php
<?php

namespace App\Services\Ai\Concerns;

use Carbon\CarbonImmutable;
use Throwable;

final class RateLimitParser
{
    /**
     * @return array{provider_reset_at: ?CarbonImmutable, reset_hint: ?string}
     */
    public static function extract(string $stdout, string $stderr): array
    {
        $text = $stdout."\n".$stderr;

        // 1. "May 5th, 2026 10:24 AM" or "May 5th 10:24 AM" (year optional, ordinal optional)
        if (preg_match('/(?:try\s+again\s+at|reset(?:\s+at)?)\s+([A-Z][a-z]+\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?\s+\d{1,2}:\d{2}(?:\s*(?:AM|PM))?)/i', $text, $m) === 1) {
            return self::result($m[1], self::parseDate(self::normalizeOrdinals($m[1])));
        }

        // 2. ISO 8601: "2026-05-05T10:24:00Z" or "2026-05-05 10:24"
        if (preg_match('/(?:try\s+again\s+at|reset(?:\s+at)?)\s+(\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}(?::\d{2})?(?:Z|[+\-]\d{2}:?\d{2})?)/i', $text, $m) === 1) {
            return self::result($m[1], self::parseDate($m[1]));
        }

        // 3. Relative: "try again in 47 minutes" / "in 2 hours" / "in 30 seconds"
        if (preg_match('/try\s+again\s+in\s+(\d+)\s+(second|minute|hour|day)s?/i', $text, $m) === 1) {
            $delta = (int) $m[1];
            $unit = strtolower($m[2]).'s';
            $reset = CarbonImmutable::now()->add($unit, $delta);

            return self::result($m[0], $reset);
        }

        // 4. Compact relative: "try again in 2h 15m" / "in 1h" / "in 45m"
        if (preg_match('/try\s+again\s+in\s+(?:(\d+)h\s*)?(?:(\d+)m)?(?:\s|\.|$)/i', $text, $m) === 1) {
            $hours = (int) ($m[1] ?? 0);
            $mins = (int) ($m[2] ?? 0);
            if ($hours > 0 || $mins > 0) {
                $reset = CarbonImmutable::now()->addHours($hours)->addMinutes($mins);

                return self::result($m[0], $reset);
            }
        }

        return ['provider_reset_at' => null, 'reset_hint' => null];
    }

    private static function normalizeOrdinals(string $value): string
    {
        return preg_replace('/(\d+)(st|nd|rd|th)\b/i', '$1', $value) ?? $value;
    }

    private static function parseDate(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{provider_reset_at: ?CarbonImmutable, reset_hint: ?string}
     */
    private static function result(string $hint, ?CarbonImmutable $resetAt): array
    {
        return [
            'provider_reset_at' => $resetAt,
            'reset_hint' => trim($hint) === '' ? null : trim($hint),
        ];
    }
}
```

- [ ] **Step 4: Rodar — deve passar**

Run: `cd atlas-server && ./vendor/bin/phpunit tests/Unit/Ai/RateLimitParserTest.php`
Expected: `OK (6 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add atlas-server/app/Services/Ai/Concerns/RateLimitParser.php \
        atlas-server/tests/Unit/Ai/RateLimitParserTest.php
git commit -m "feat(ai): parse provider rate-limit reset hints from stderr"
```

---

### Task 4: Enriquecer `AiProviderResult.metadata` com `provider_reset_at` no `RunsCliProcesses`

**Files:**
- Modify: `atlas-server/app/Services/Ai/Concerns/RunsCliProcesses.php:111-122`

Hoje a construção do `AiProviderResult` no fim de `runProcessStreaming` deixa `metadata: []` por padrão. Precisamos chamar o parser e popular o metadata quando `errorCode === 'rate_limited'` ou `errorCode === 'auth_expired'` (auth não tem reset_at, mas queremos ainda assim padronizar e injetar o `reset_hint` quando houver — pode ser null).

- [ ] **Step 1: Importar o parser**

Em `RunsCliProcesses.php`, no topo:
```php
use App\Services\Ai\Concerns\RateLimitParser;
```

(Note: a trait já está em `App\Services\Ai\Concerns`, então o `use` é o nome relativo: `use RateLimitParser;` causaria conflito com o nome da trait. Use o FQN completo via alias: `use RateLimitParser as ProviderRateLimitParser;` se precisar; ou simplesmente referenciar como `\App\Services\Ai\Concerns\RateLimitParser::extract(...)`. Use a forma com alias.)

```php
use App\Services\Ai\Concerns\RateLimitParser as ProviderRateLimitParser;
```

- [ ] **Step 2: Construir metadata enriquecido antes do return final**

Substituir o bloco atual `RunsCliProcesses.php:111-121`:

```php
return new AiProviderResult(
    ok: $process->isSuccessful() && $errorCode === null,
    output: $output,
    command: $this->redactCommand($command),
    exitCode: $process->getExitCode(),
    durationMs: $durationMs,
    stdout: $stdout,
    stderr: $stderr,
    errorCode: $errorCode,
    errorMessage: $errorCode ? AtlasSecurity::redactString(trim($stderr) ?: trim($stdout) ?: $errorCode) : null,
);
```

Por:

```php
$metadata = [];
if ($errorCode === 'rate_limited') {
    $rate = ProviderRateLimitParser::extract($stdout, $stderr);
    $metadata['provider_reset_at'] = $rate['provider_reset_at']?->toIso8601String();
    $metadata['reset_hint'] = $rate['reset_hint'];
}

return new AiProviderResult(
    ok: $process->isSuccessful() && $errorCode === null,
    output: $output,
    command: $this->redactCommand($command),
    exitCode: $process->getExitCode(),
    durationMs: $durationMs,
    stdout: $stdout,
    stderr: $stderr,
    errorCode: $errorCode,
    errorMessage: $errorCode ? AtlasSecurity::redactString(trim($stderr) ?: trim($stdout) ?: $errorCode) : null,
    metadata: $metadata,
);
```

- [ ] **Step 3: Rodar suite de testes pra garantir que não quebrou nada**

Run: `cd atlas-server && ./vendor/bin/phpunit tests/Unit/`
Expected: PASS (sem novas falhas).

- [ ] **Step 4: Commit**

```bash
git add atlas-server/app/Services/Ai/Concerns/RunsCliProcesses.php
git commit -m "feat(ai): expose rate-limit reset metadata on provider result"
```

---

### Task 5: `AiProviderChoiceBuilder` — gera as opções pro operador

**Files:**
- Create: `atlas-server/app/Services/Ai/AiProviderChoiceBuilder.php`
- Test: `atlas-server/tests/Unit/Ai/AiProviderChoiceBuilderTest.php`

Contrato: `build(string $errorCode, string $currentProvider, ?string $currentModel, ?CarbonImmutable $resetAt): array<int, array>`. Cada opção tem:
- `id`: slug único estável (`switch_provider`, `wait_for_reset`, `retry_now`, `cancel`)
- `label`: texto curto pro operador
- `description`: tradeoff técnico
- `action`: `switch_provider` | `wait` | `retry` | `cancel`
- `provider`, `model`: pra `switch_provider` (qual usar)
- `available_at_iso`: pra `wait` (quando reenfileirar)

Regras:
- `rate_limited`: oferece (a) trocar pro outro provider (default 1, posição 1); (b) aguardar até o reset SE `resetAt` é válido E ≤ 24h no futuro; (c) cancelar.
- `auth_expired`: NÃO oferece trocar de provider automaticamente (login problema). Oferece (a) marcar como precisa-de-login (fica `failed`, mensagem clara); (b) cancelar. Se o outro provider está autenticado, pode oferecer fallback — mas no MVP **não** oferecemos, deixamos o operador rodar `claude login` / `codex login` e dar retry.
- Default sempre é a opção 1 (mais próxima do que o operador esperava).

- [ ] **Step 1: Escrever o teste failing**

`atlas-server/tests/Unit/Ai/AiProviderChoiceBuilderTest.php`:
```php
<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiProviderChoiceBuilder;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AiProviderChoiceBuilderTest extends TestCase
{
    public function test_rate_limited_offers_other_provider_and_wait_within_24h(): void
    {
        CarbonImmutable::setTestNow('2026-05-01 09:00:00');
        $builder = new AiProviderChoiceBuilder();
        $resetAt = CarbonImmutable::parse('2026-05-01 14:00:00');

        $options = $builder->build('rate_limited', 'codex_cli', 'gpt-5.5', $resetAt);

        $ids = array_column($options, 'id');
        $this->assertSame(['switch_provider', 'wait_for_reset', 'cancel'], $ids);
        $this->assertSame('claude_cli', $options[0]['provider']);
        $this->assertSame('switch_provider', $options[0]['action']);
        $this->assertSame($resetAt->toIso8601String(), $options[1]['available_at_iso']);

        CarbonImmutable::setTestNow();
    }

    public function test_rate_limited_omits_wait_when_reset_too_far(): void
    {
        CarbonImmutable::setTestNow('2026-05-01 09:00:00');
        $builder = new AiProviderChoiceBuilder();
        $resetAt = CarbonImmutable::parse('2026-05-05 10:24:00');

        $options = $builder->build('rate_limited', 'codex_cli', 'gpt-5.5', $resetAt);

        $ids = array_column($options, 'id');
        $this->assertSame(['switch_provider', 'cancel'], $ids);

        CarbonImmutable::setTestNow();
    }

    public function test_rate_limited_without_reset_only_offers_switch_and_cancel(): void
    {
        $builder = new AiProviderChoiceBuilder();
        $options = $builder->build('rate_limited', 'claude_cli', 'sonnet-4.6', null);

        $ids = array_column($options, 'id');
        $this->assertSame(['switch_provider', 'cancel'], $ids);
        $this->assertSame('codex_cli', $options[0]['provider']);
    }

    public function test_auth_expired_offers_login_required_and_cancel(): void
    {
        $builder = new AiProviderChoiceBuilder();
        $options = $builder->build('auth_expired', 'claude_cli', 'sonnet-4.6', null);

        $ids = array_column($options, 'id');
        $this->assertSame(['login_required', 'cancel'], $ids);
        $this->assertSame('fail', $options[0]['action']);
    }

    public function test_unsupported_error_returns_only_cancel(): void
    {
        $builder = new AiProviderChoiceBuilder();
        $options = $builder->build('cli_error', 'claude_cli', null, null);

        $this->assertCount(1, $options);
        $this->assertSame('cancel', $options[0]['id']);
    }
}
```

- [ ] **Step 2: Rodar — deve falhar**

Run: `cd atlas-server && ./vendor/bin/phpunit tests/Unit/Ai/AiProviderChoiceBuilderTest.php`
Expected: FAIL com `Class "App\Services\Ai\AiProviderChoiceBuilder" not found`.

- [ ] **Step 3: Implementar**

`atlas-server/app/Services/Ai/AiProviderChoiceBuilder.php`:
```php
<?php

namespace App\Services\Ai;

use Carbon\CarbonImmutable;

class AiProviderChoiceBuilder
{
    private const FALLBACK_MAP = [
        'claude_cli' => 'codex_cli',
        'codex_cli' => 'claude_cli',
    ];

    private const PROVIDER_LABEL = [
        'claude_cli' => 'Claude (claude_cli)',
        'codex_cli' => 'Codex (codex_cli)',
    ];

    private const WAIT_HORIZON_HOURS = 24;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(string $errorCode, string $currentProvider, ?string $currentModel, ?CarbonImmutable $resetAt): array
    {
        return match ($errorCode) {
            'rate_limited' => $this->rateLimitedOptions($currentProvider, $resetAt),
            'auth_expired' => $this->authExpiredOptions($currentProvider),
            default => [$this->cancelOption()],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
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

        return $options;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function authExpiredOptions(string $currentProvider): array
    {
        $cliName = match ($currentProvider) {
            'claude_cli' => 'claude login',
            'codex_cli' => 'codex login',
            default => 'login',
        };

        return [
            [
                'id' => 'login_required',
                'label' => "Marcar como bloqueado por login ({$cliName})",
                'description' => 'Falha o job com instrução clara. Operador roda o login no terminal e pede retry.',
                'action' => 'fail',
                'reason' => 'login_required',
                'cli_command' => $cliName,
            ],
            $this->cancelOption(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelOption(): array
    {
        return [
            'id' => 'cancel',
            'label' => 'Cancelar job',
            'description' => 'Encerra a tentativa. Conversa fica preservada.',
            'action' => 'cancel',
        ];
    }

    private function withinHorizon(CarbonImmutable $resetAt): bool
    {
        $now = CarbonImmutable::now();
        if ($resetAt->lessThanOrEqualTo($now)) {
            return false;
        }

        return $resetAt->diffInHours($now) < self::WAIT_HORIZON_HOURS;
    }
}
```

- [ ] **Step 4: Rodar — deve passar**

Run: `cd atlas-server && ./vendor/bin/phpunit tests/Unit/Ai/AiProviderChoiceBuilderTest.php`
Expected: `OK (5 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add atlas-server/app/Services/Ai/AiProviderChoiceBuilder.php \
        atlas-server/tests/Unit/Ai/AiProviderChoiceBuilderTest.php
git commit -m "feat(ai): build operator choice list for rate_limited and auth_expired"
```

---

### Task 6: `AiWorker.completeAttempt` — pausar em `awaiting_user_choice` antes do retry

**Files:**
- Modify: `atlas-server/app/Services/Ai/AiWorker.php` — construtor e `completeAttempt`
- Test: `atlas-server/tests/Feature/Ai/AiWorkerProviderChoiceTest.php`

Hoje a falha cai direto no bloco `AiWorker.php:547-558`. Vamos inserir uma branch antes: se `errorCode ∈ {rate_limited, auth_expired}` AND ainda não foi pausado anteriormente neste job (idempotência via metadata flag), entra no caminho `awaiting_user_choice`.

**Marcador de idempotência**: `metadata.provider_choice_state = 'pending'` ao pausar; `'resolved'` quando o operador escolheu. Se já está `resolved` e a tentativa pós-resume falha de novo com o mesmo errorCode, vai pro caminho de falha normal — não loop infinito de menus.

- [ ] **Step 1: Escrever o teste failing (Feature test)**

`atlas-server/tests/Feature/Ai/AiWorkerProviderChoiceTest.php`:
```php
<?php

namespace Tests\Feature\Ai;

use App\Models\AiJob;
use App\Models\AiTrace;
use App\Models\AiWorkerEvent;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\AiWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiWorkerProviderChoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_limited_job_pauses_with_choice_options(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->subSecond(),
            'max_attempts' => 3,
        ]);

        $this->mockProviderManagerWith(new AiProviderResult(
            ok: false,
            output: '',
            command: ['codex'],
            exitCode: 1,
            durationMs: 100,
            stdout: '',
            stderr: "You've hit your usage limit. Try again at May 5th, 2026 10:24 AM.",
            errorCode: 'rate_limited',
            errorMessage: 'usage limit',
            metadata: [
                'provider_reset_at' => '2026-05-05T10:24:00+00:00',
                'reset_hint' => 'May 5th, 2026 10:24 AM',
            ],
        ));

        app(AiWorker::class)->runNext();

        $job->refresh();
        $this->assertSame('awaiting_user_choice', $job->status);
        $this->assertSame('pending', data_get($job->metadata, 'provider_choice_state'));
        $this->assertNotEmpty(data_get($job->metadata, 'choice_options'));
        $this->assertSame('switch_provider', data_get($job->metadata, 'choice_options.0.id'));
        $this->assertSame('claude_cli', data_get($job->metadata, 'choice_options.0.provider'));

        $event = AiWorkerEvent::where('event_type', 'provider_choice_required')->first();
        $this->assertNotNull($event);
        $this->assertSame($job->id, $event->ai_job_id);
    }

    public function test_pause_does_not_consume_extra_attempts(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->subSecond(),
            'max_attempts' => 3,
        ]);

        $this->mockProviderManagerWith(new AiProviderResult(
            ok: false,
            output: '',
            command: ['codex'],
            exitCode: 1,
            durationMs: 100,
            stdout: '',
            stderr: 'rate limit',
            errorCode: 'rate_limited',
            errorMessage: 'rate limit',
        ));

        app(AiWorker::class)->runNext();

        $job->refresh();
        $this->assertSame(1, $job->attempts, 'Pausa não deve gastar attempts extras.');
    }

    public function test_resolved_choice_followed_by_same_error_falls_through_to_failure(): void
    {
        $trace = AiTrace::create([
            'trace_key' => 'tr_'.uniqid(),
            'agent_slug' => 'orquestrador',
            'operator_input' => 'olá',
            'status' => 'queued',
        ]);

        $job = AiJob::create([
            'trace_id' => $trace->id,
            'kind' => 'interaction',
            'status' => 'queued',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_text' => 'olá',
            'prompt' => 'olá',
            'available_at' => now()->subSecond(),
            'max_attempts' => 1,
            'metadata' => ['provider_choice_state' => 'resolved'],
        ]);

        $this->mockProviderManagerWith(new AiProviderResult(
            ok: false,
            output: '',
            command: ['codex'],
            exitCode: 1,
            durationMs: 100,
            stdout: '',
            stderr: 'rate limit',
            errorCode: 'rate_limited',
            errorMessage: 'rate limit',
        ));

        app(AiWorker::class)->runNext();

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertNotSame('awaiting_user_choice', $job->status);
    }

    private function mockProviderManagerWith(AiProviderResult $result): void
    {
        $provider = new class($result) implements AiProvider {
            public function __construct(private readonly AiProviderResult $result) {}

            public function runStreaming($job, string $prompt, ?callable $onEvent = null): AiProviderResult
            {
                return $this->result;
            }

            public function key(): string { return 'codex_cli'; }

            public function health(): \App\Services\Ai\AiProviderHealthCheck
            {
                return new \App\Services\Ai\AiProviderHealthCheck('codex_cli', 'online', 'mock');
            }
        };

        $manager = $this->createMock(AiProviderManager::class);
        $manager->method('get')->willReturn($provider);
        $manager->method('keys')->willReturn(['claude_cli', 'codex_cli']);
        $this->app->instance(AiProviderManager::class, $manager);
    }
}
```

> **Nota:** o método `key()` e `health()` na interface `AiProvider` podem ter assinaturas diferentes — antes de rodar este teste leia `app/Services/Ai/AiProvider.php` e ajuste o mock pra bater com a interface real. Se `AiProvider` é só `runStreaming`, o restante do mock pode sair. Não invente métodos que a interface não tem.

- [ ] **Step 2: Rodar — deve falhar**

Run: `cd atlas-server && ./vendor/bin/phpunit tests/Feature/Ai/AiWorkerProviderChoiceTest.php`
Expected: FAIL — provavelmente `awaiting_user_choice` não bate, status continua `queued`.

- [ ] **Step 3: Injetar `AiProviderChoiceBuilder` no construtor do `AiWorker`**

Em `AiWorker.php:21-35`, adicionar `private readonly AiProviderChoiceBuilder $choices,` ao construtor:
```php
public function __construct(
    private readonly AiProviderManager $providers,
    private readonly AiWorkerLogger $logger,
    private readonly AiStreamRecorder $stream,
    private readonly AiPermissionEngine $permissions,
    private readonly AiCouncilCoordinator $council,
    private readonly AiConversationRecorder $conversation,
    private readonly AiSessionStateService $states,
    private readonly AiQualityEvaluator $quality,
    private readonly AiQualityActionService $qualityActions,
    private readonly CaptureSemanticClarifier $clarifier,
    private readonly AiProviderModelResolver $models,
    private readonly AuditLogService $audit,
    private readonly JobResultInboxEmitter $jobResults,
    private readonly AiProviderChoiceBuilder $choices,
) {}
```

Laravel auto-resolve concrete classes, sem binding extra necessário.

- [ ] **Step 4: Adicionar branch `awaiting_user_choice` no `completeAttempt`**

Logo antes do bloco atual `AiWorker.php:547`:
```php
$nonRetryable = in_array($result->errorCode, ['permission_denied'], true);
$finalFailure = $nonRetryable || $job->attempts >= $job->max_attempts;
```

Inserir:
```php
if ($this->shouldPauseForChoice($job, $result)) {
    return $this->pauseForChoice($job, $attempt, $result, $workerId);
}

$nonRetryable = in_array($result->errorCode, ['permission_denied'], true);
$finalFailure = $nonRetryable || $job->attempts >= $job->max_attempts;
// ... bloco atual continua
```

- [ ] **Step 5: Adicionar os métodos `shouldPauseForChoice` e `pauseForChoice`**

No final da classe `AiWorker` (antes do `}` final):
```php
private function shouldPauseForChoice(AiJob $job, AiProviderResult $result): bool
{
    if (! in_array($result->errorCode, ['rate_limited', 'auth_expired'], true)) {
        return false;
    }

    if ($this->isCouncilJob($job)) {
        return false;
    }

    return data_get($job->metadata, 'provider_choice_state') !== 'resolved';
}

private function pauseForChoice(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
{
    $resetAtIso = data_get($result->metadata, 'provider_reset_at');
    $resetAt = is_string($resetAtIso) ? \Carbon\CarbonImmutable::parse($resetAtIso) : null;

    $options = $this->choices->build(
        errorCode: (string) $result->errorCode,
        currentProvider: (string) ($attempt->provider ?: $job->provider),
        currentModel: $job->model,
        resetAt: $resetAt,
    );

    $metadata = array_merge($job->metadata ?? [], [
        'provider_choice_state' => 'pending',
        'provider_choice_error_code' => $result->errorCode,
        'provider_choice_offered_at' => now()->toIso8601String(),
        'provider_reset_at' => $resetAtIso,
        'reset_hint' => data_get($result->metadata, 'reset_hint'),
        'choice_options' => $options,
    ]);

    $attempt->update([
        'status' => 'failed',
        'exit_code' => $result->exitCode,
        'duration_ms' => $result->durationMs,
        'stdout_excerpt' => \Illuminate\Support\Str::limit($result->stdout, 4000, '...'),
        'stderr_excerpt' => \Illuminate\Support\Str::limit($result->stderr, 4000, '...'),
        'error_code' => $result->errorCode,
        'error_message' => $result->errorMessage,
        'finished_at' => now(),
        'metadata' => $result->metadata,
    ]);

    $job->update([
        'status' => 'awaiting_user_choice',
        'available_at' => now()->addYear(),  // não pega na fila até resume-choice mover
        'reserved_at' => null,
        'started_at' => null,
        'worker_id' => null,
        'error_code' => $result->errorCode,
        'error_message' => $result->errorMessage,
        'metadata' => $metadata,
    ]);

    $this->emitStreamEvent(
        $job,
        $attempt,
        'provider_choice',
        'provider_choice_required',
        $result->errorMessage ?: $result->errorCode,
        [
            'error_code' => $result->errorCode,
            'options' => $options,
            'provider_reset_at' => $resetAtIso,
            'reset_hint' => data_get($result->metadata, 'reset_hint'),
        ],
        'system',
        null,
    );

    $this->logger->event(
        eventType: 'provider_choice_required',
        message: 'AI job paused awaiting operator choice on provider failure.',
        severity: 'warning',
        provider: $attempt->provider,
        job: $job,
        attempt: $attempt,
        metadata: [
            'error_code' => $result->errorCode,
            'option_ids' => array_column($options, 'id'),
        ],
        workerId: $workerId,
    );

    return $job->refresh()->load(['trace', 'attemptHistory']);
}
```

> **Validação cruzada:** confira a assinatura real de `$this->logger->event(...)` em `app/Services/Ai/AiWorkerLogger.php` antes de copiar — os argumentos nomeados acima espelham os usos existentes em `AiWorker.php:251-264` e `:565-574`. Se a assinatura for diferente, ajuste.

- [ ] **Step 6: Rodar — deve passar**

Run: `cd atlas-server && ./vendor/bin/phpunit tests/Feature/Ai/AiWorkerProviderChoiceTest.php`
Expected: `OK (3 tests, ...)`.

- [ ] **Step 7: Rodar suite inteira pra garantir não regredimos nada**

Run: `cd atlas-server && ./vendor/bin/phpunit`
Expected: PASS (mesmo número de testes verdes que antes + os 3 novos).

- [ ] **Step 8: Commit**

```bash
git add atlas-server/app/Services/Ai/AiWorker.php \
        atlas-server/tests/Feature/Ai/AiWorkerProviderChoiceTest.php
git commit -m "feat(ai): pause job in awaiting_user_choice on rate_limited/auth_expired"
```

---

### Task 7: Endpoint `POST /api/ai/jobs/{job}/resume-choice`

**Files:**
- Modify: `atlas-server/app/Http/Controllers/AiJobController.php`
- Modify: `atlas-server/routes/api.php:212`
- Test: `atlas-server/tests/Feature/Ai/AiJobResumeChoiceTest.php`

Contrato: payload `{"option_id": "switch_provider"}`. Servidor:
- Valida que job está em `awaiting_user_choice`.
- Procura a opção em `metadata.choice_options` pelo `id`.
- Aplica a ação:
  - `switch_provider`: seta `provider = option.provider`, `model = null` (deixa o `AiProviderModelResolver` resolver), `available_at = now()`, `status = queued`.
  - `wait`: `available_at = option.available_at_iso`, `status = queued`, mantém provider/model.
  - `fail`: `status = failed`, `finished_at = now()`, `error_message = 'Login required: ' . cli_command`.
  - `cancel`: chama o método `cancel` existente.
- Marca `metadata.provider_choice_state = 'resolved'` e adiciona `provider_choice_resolved_at`.
- Emite stream event `provider_choice_resolved`.
- Audit log.

- [ ] **Step 1: Escrever o teste failing**

`atlas-server/tests/Feature/Ai/AiJobResumeChoiceTest.php`:
```php
<?php

namespace Tests\Feature\Ai;

use App\Models\AiJob;
use App\Models\AiTrace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiJobResumeChoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resume_with_switch_provider_requeues_with_new_provider(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'switch_provider', 'action' => 'switch_provider', 'provider' => 'claude_cli', 'model' => null],
            ['id' => 'cancel', 'action' => 'cancel'],
        ]);

        $response = $this->postJson("/api/ai/jobs/{$job->id}/resume-choice", [
            'option_id' => 'switch_provider',
        ]);

        $response->assertOk();
        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertSame('claude_cli', $job->provider);
        $this->assertSame('resolved', data_get($job->metadata, 'provider_choice_state'));
        $this->assertTrue($job->available_at->lessThanOrEqualTo(now()->addSecond()));
    }

    public function test_resume_with_wait_sets_available_at(): void
    {
        $waitUntil = now()->addHours(3)->toIso8601String();

        $job = $this->makePausedJob([
            ['id' => 'wait_for_reset', 'action' => 'wait', 'available_at_iso' => $waitUntil],
            ['id' => 'cancel', 'action' => 'cancel'],
        ]);

        $response = $this->postJson("/api/ai/jobs/{$job->id}/resume-choice", [
            'option_id' => 'wait_for_reset',
        ]);

        $response->assertOk();
        $job->refresh();
        $this->assertSame('queued', $job->status);
        $this->assertSame($waitUntil, $job->available_at->toIso8601String());
    }

    public function test_resume_with_cancel_marks_job_cancelled(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'switch_provider', 'action' => 'switch_provider', 'provider' => 'claude_cli'],
            ['id' => 'cancel', 'action' => 'cancel'],
        ]);

        $response = $this->postJson("/api/ai/jobs/{$job->id}/resume-choice", [
            'option_id' => 'cancel',
        ]);

        $response->assertOk();
        $job->refresh();
        $this->assertSame('cancelled', $job->status);
    }

    public function test_resume_rejects_unknown_option(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'switch_provider', 'action' => 'switch_provider', 'provider' => 'claude_cli'],
        ]);

        $response = $this->postJson("/api/ai/jobs/{$job->id}/resume-choice", [
            'option_id' => 'banana',
        ]);

        $response->assertStatus(422);
        $this->assertSame('AI_JOB_CHOICE_NOT_FOUND', $response->json('error.code'));
    }

    public function test_resume_rejects_when_not_awaiting_choice(): void
    {
        $job = $this->makePausedJob([
            ['id' => 'cancel', 'action' => 'cancel'],
        ]);
        $job->update(['status' => 'queued', 'metadata' => ['provider_choice_state' => 'resolved']]);

        $response = $this->postJson("/api/ai/jobs/{$job->id}/resume-choice", [
            'option_id' => 'cancel',
        ]);

        $response->assertStatus(422);
        $this->assertSame('AI_JOB_NOT_AWAITING_CHOICE', $response->json('error.code'));
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

- [ ] **Step 2: Rodar — deve falhar**

Run: `cd atlas-server && ./vendor/bin/phpunit tests/Feature/Ai/AiJobResumeChoiceTest.php`
Expected: FAIL — `404 Not Found` (rota não existe).

- [ ] **Step 3: Adicionar a rota**

Em `atlas-server/routes/api.php`, logo abaixo da linha 212 (`Route::post('/ai/jobs/{job}/cancel', ...)`):
```php
    Route::post('/ai/jobs/{job}/resume-choice', [AiJobController::class, 'resumeChoice']);
```

- [ ] **Step 4: Implementar o método `resumeChoice` em `AiJobController`**

Adicionar ao `atlas-server/app/Http/Controllers/AiJobController.php` (depois do método `cancel`):
```php
public function resumeChoice(AiJob $job, Request $request, AuditLogService $audit, AiCouncilCoordinator $council): JsonResponse
{
    $request->validate([
        'option_id' => ['required', 'string', 'max:64'],
    ]);

    if ($job->status !== 'awaiting_user_choice') {
        return response()->json([
            'error' => [
                'code' => 'AI_JOB_NOT_AWAITING_CHOICE',
                'message' => 'Job is not waiting for an operator choice.',
            ],
        ], 422);
    }

    $optionId = (string) $request->input('option_id');
    $options = (array) data_get($job->metadata, 'choice_options', []);
    $option = collect($options)->firstWhere('id', $optionId);

    if (! is_array($option)) {
        return response()->json([
            'error' => [
                'code' => 'AI_JOB_CHOICE_NOT_FOUND',
                'message' => "Option [{$optionId}] not found in choice_options.",
            ],
        ], 422);
    }

    $action = (string) ($option['action'] ?? '');
    $metadata = array_merge($job->metadata ?? [], [
        'provider_choice_state' => 'resolved',
        'provider_choice_resolved_at' => now()->toIso8601String(),
        'provider_choice_resolved_option' => $optionId,
    ]);

    match ($action) {
        'switch_provider' => $job->update([
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
        ]),
        'wait' => $job->update([
            'status' => 'queued',
            'available_at' => isset($option['available_at_iso'])
                ? \Carbon\Carbon::parse((string) $option['available_at_iso'])
                : now()->addMinutes(15),
            'reserved_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'worker_id' => null,
            'metadata' => $metadata,
        ]),
        'fail' => $job->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error_code' => (string) ($option['reason'] ?? 'choice_failed'),
            'error_message' => isset($option['cli_command'])
                ? "Login required: rode `{$option['cli_command']}` no terminal e tente novamente."
                : 'Operator chose to fail this job.',
            'metadata' => $metadata,
        ]),
        'cancel' => $this->cancelByChoice($job, $metadata, $council),
        default => $job->update([
            'metadata' => $metadata,
        ]),
    };

    $audit->record('ai_job_choice_resolved', [
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
        'privacy' => $this->privacyFromJob($job),
        'refs' => [
            'job_id' => $job->id,
            'trace_id' => $job->trace_id,
        ],
    ]);

    return response()->json([
        'job' => (new AiJobResource($job->refresh()->load(['trace', 'attemptHistory'])))->resolve(),
    ]);
}

private function cancelByChoice(AiJob $job, array $metadata, AiCouncilCoordinator $council): void
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
```

> **Verificar:** o método `privacyFromJob` já existe no controller (espelha o padrão usado em `retry()`). Se não existir, crie como `private function privacyFromJob(AiJob $job): array { $privacy = data_get($job->payload, 'privacy', data_get($job->metadata, 'privacy')); return is_array($privacy) ? $privacy : []; }`. O método `isCouncilJob` também já existe (visto em `cancel()`).

- [ ] **Step 5: Rodar — deve passar**

Run: `cd atlas-server && ./vendor/bin/phpunit tests/Feature/Ai/AiJobResumeChoiceTest.php`
Expected: `OK (5 tests, ...)`.

- [ ] **Step 6: Rodar suite inteira**

Run: `cd atlas-server && ./vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add atlas-server/app/Http/Controllers/AiJobController.php \
        atlas-server/routes/api.php \
        atlas-server/tests/Feature/Ai/AiJobResumeChoiceTest.php
git commit -m "feat(ai): add /api/ai/jobs/{job}/resume-choice endpoint"
```

---

### Task 8: Expor `choice_options` e `provider_reset_at` no `AiJobResource`

**Files:**
- Modify: `atlas-server/app/Http/Resources/AiJobResource.php`

Sem isso a TUI/app não consegue ler as opções do `metadata`.

- [ ] **Step 1: Ler o resource atual**

Run: `cat atlas-server/app/Http/Resources/AiJobResource.php`

(O Step 2 abaixo assume que o resource hoje já espalha campos do metadata por meio de `toArray`. Localize onde o array é montado.)

- [ ] **Step 2: Adicionar os campos derivados**

No retorno do método `toArray` do `AiJobResource`, adicionar:
```php
'awaiting_user_choice' => $this->status === 'awaiting_user_choice',
'choice_options' => data_get($this->metadata, 'choice_options'),
'provider_choice_state' => data_get($this->metadata, 'provider_choice_state'),
'provider_choice_error_code' => data_get($this->metadata, 'provider_choice_error_code'),
'provider_reset_at' => data_get($this->metadata, 'provider_reset_at'),
'reset_hint' => data_get($this->metadata, 'reset_hint'),
```

- [ ] **Step 3: Atualizar o teste de Task 7 para checar a resposta**

Em `AiJobResumeChoiceTest::test_resume_with_switch_provider_requeues_with_new_provider`, depois do `assertOk`, acrescentar:
```php
$response->assertJsonPath('job.provider_choice_state', 'resolved');
```

- [ ] **Step 4: Rodar testes**

Run: `cd atlas-server && ./vendor/bin/phpunit tests/Feature/Ai/AiJobResumeChoiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add atlas-server/app/Http/Resources/AiJobResource.php \
        atlas-server/tests/Feature/Ai/AiJobResumeChoiceTest.php
git commit -m "feat(ai): expose choice_options and reset hints on job resource"
```

---

### Task 9: Smoke test manual end-to-end

**Files:** nenhum, validação operacional.

Objetivo: garantir que o fluxo completo funciona contra Postgres real, não só nos testes.

- [ ] **Step 1: Subir backend**

Run: `cd atlas-server && docker compose up -d backend queue`
Expected: containers `atlas-backend` e `atlas-queue` rodando.

- [ ] **Step 2: Criar um job que vai rate-limitar via tinker**

Run: `docker exec -it atlas-backend php artisan tinker`

No tinker:
```php
$trace = \App\Models\AiTrace::create([
    'trace_key' => 'tr_smoke_'.now()->timestamp,
    'agent_slug' => 'orquestrador',
    'operator_input' => 'smoke',
    'status' => 'queued',
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
        'choice_options' => [
            ['id' => 'switch_provider', 'action' => 'switch_provider', 'provider' => 'claude_cli'],
            ['id' => 'cancel', 'action' => 'cancel'],
        ],
    ],
]);

echo $job->id;
exit
```

Anote o UUID retornado.

- [ ] **Step 3: Chamar o endpoint**

Run (substituir `<UUID>`):
```bash
curl -s -X POST http://localhost:8000/api/ai/jobs/<UUID>/resume-choice \
  -H 'Content-Type: application/json' \
  -d '{"option_id":"switch_provider"}' | jq .
```
Expected: JSON com `job.status = "queued"`, `job.provider = "claude_cli"`, `job.provider_choice_state = "resolved"`.

- [ ] **Step 4: Verificar no banco**

Run:
```bash
docker exec atlas-db psql -U atlas -d atlas -c \
  "SELECT id, status, provider, metadata->>'provider_choice_state' AS state FROM ai_jobs ORDER BY created_at DESC LIMIT 1;"
```
Expected: `status = queued`, `provider = claude_cli`, `state = resolved`.

- [ ] **Step 5: Verificar audit log**

Run:
```bash
docker exec atlas-db psql -U atlas -d atlas -c \
  "SELECT event_type, summary FROM audit_events WHERE event_type='ai_job_choice_resolved' ORDER BY created_at DESC LIMIT 1;"
```
Expected: uma linha com `summary` mencionando `[switch_provider]`.

- [ ] **Step 6: (Se 1-5 passaram) Commit final / tag**

```bash
git tag provider-choice-mvp
```

---

## Notas finais para o engenheiro

- **CLAUDE.md do projeto** proíbe `INSERT INTO migrations` e edição manual da tabela `migrations`. Se ao rodar Task 1/Task 2 o backend reclamar de constraint conflitante, **não pule** — torne a migration idempotente (já está, via `DROP CONSTRAINT IF EXISTS`). Se ainda assim quebrar, leia `CLAUDE.md:1-31` e siga o caminho "fresh > reparo" em dev.
- **Triggers de `updated_at`**: nenhuma tabela nova foi criada. As alterações são em CHECK constraints — não precisa adicionar trigger.
- **Council jobs** (kind=council, payload.execution_policy=dual_review): o `shouldPauseForChoice` retorna `false` pra eles — council tem coordenação dupla e o handoff de provider precisaria considerar o `council_role`. Fica fora deste MVP.
- **Reentrância**: o flag `metadata.provider_choice_state` evita pausar duas vezes. Depois que o operador resolveu, se a falha repetir, vai pelo retry padrão (3 attempts default → falha permanente). É o comportamento intencional.
- **Backoff exponencial** e **detecção de "approaching limit"** ficam pra planos futuros. Este plano não os cobre — o engenheiro não deve adicioná-los aqui.
- **TUI/app**: o stream event `provider_choice_required` carrega o payload de opções. O cliente (CLI Atlas, app Expo) precisa renderizar — fora de escopo.

## Self-review checklist (preenchido)

- [x] Cobertura: cada gap do brainstorm vira task — extração de data (T3+T4), opções (T5), pausa (T6), resume (T7+T8), exposição UI (T8).
- [x] Sem placeholders TODO/TBD.
- [x] Tipos consistentes: `provider_reset_at` é string ISO no JSON e Carbon no PHP; `choice_options[*].id` é string; `option.action` ∈ {switch_provider, wait, fail, cancel}.
- [x] Idempotência das migrations confere com regra do `CLAUDE.md`.
- [x] Cada task termina em commit isolado e testável.
