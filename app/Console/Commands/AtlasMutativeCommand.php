<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Atlas Cognition Operating System — Absorcao 3 (Doctor + Repair Modes 3-Tier).
 *
 * Schema canon: `atlas.command.three_tier_envelope.v1`.
 * Doc canon mae: `atlas-cognition-operating-system.md`.
 * Doc absorcao: `atlas-external-memory-pattern-absorptions-v1.md` (Absorcao 3).
 *
 * Padrao Doctor 3-Tier inspirado em engram:
 *  - plan     -> mostra exatamente o que faria, sem tocar nada (read-only)
 *  - dry-run  -> executa em transacao, rollback no fim (provas de viabilidade)
 *  - apply    -> executa de verdade. Exige `--check CODE` + `--confirm`.
 *
 * Subclasses declaram:
 *  - `mutativeName()`            nome canonico do comando para logging/envelope
 *  - `availableCheckCodes()`     lista de codigos validos para `--check`
 *  - `planActions(...)`          array<string> descrevendo o que faria
 *  - `dryRunActions(...)`        ações executadas dentro de transacao
 *  - `applyActions(...)`         ações executadas de verdade
 *  - `rollbackRef(...)`          referencia canonica de rollback (URI/ID/comando)
 *
 * Cognitive immune compliance:
 *  - Default mode = plan (read-only, seguro).
 *  - apply exige `--check` matching `availableCheckCodes()` + flag `--apply` ou `--confirm`.
 *  - Envelope assinado com sha256 deterministico antes de mutar.
 *
 * Audit:
 *  - Cada execucao emite envelope canonico em stdout (com `--json`) ou tabela
 *    legivel. Subclasse pode persistir envelope em atlas_ledger_events.
 *
 * Phase 1 (esta versao):
 *  - Abstract base + envelope canonico + 3 modes + check_code + rollback_ref.
 *  - Aplicacao em comandos legacy via heran a (incremental rollout).
 *
 * Phase 2 (proximo AP):
 *  - Hook automatico para persistir envelope em atlas_ledger_events com receipt.
 *  - Comando Atlas Console base que tudo mutativo herda.
 *  - Validation automatica de `forbidden_changes` no frontmatter do doc dono.
 */
abstract class AtlasMutativeCommand extends Command
{
    public const MODE_PLAN = 'plan';
    public const MODE_DRY_RUN = 'dry-run';
    public const MODE_APPLY = 'apply';

    public const ALLOWED_MODES = [
        self::MODE_PLAN,
        self::MODE_DRY_RUN,
        self::MODE_APPLY,
    ];

    public const STATUS_PLANNED = 'planned';
    public const STATUS_DRY_RUN_OK = 'dry_run_ok';
    public const STATUS_DRY_RUN_FAILED = 'dry_run_failed';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_APPLY_FAILED = 'apply_failed';
    public const STATUS_BLOCKED_MISSING_CHECK = 'blocked_missing_check';
    public const STATUS_BLOCKED_INVALID_CHECK = 'blocked_invalid_check';
    public const STATUS_BLOCKED_NOT_CONFIRMED = 'blocked_not_confirmed';
    public const STATUS_BLOCKED_INVALID_MODE = 'blocked_invalid_mode';

    /**
     * Nome canonico do comando para logging/envelope. Subclasse deve sobrescrever.
     */
    abstract protected function mutativeName(): string;

    /**
     * Codigos de check aceitos para `--check`. Vazio significa que apply nao
     * exige check explicito (raro; recomendado declarar pelo menos um).
     *
     * @return array<int,string>
     */
    abstract protected function availableCheckCodes(): array;

    /**
     * Plan: descricao read-only do que faria. NUNCA toca DB/filesystem/network.
     *
     * @return array<int,string>|array<string,mixed>
     */
    abstract protected function planActions(array $context): array;

    /**
     * Dry-run: executa em transacao DB; rollback obrigatorio no fim.
     * Retorna mesmo shape de applyActions mas sem efeito persistente.
     *
     * @return array<string,mixed>
     */
    abstract protected function dryRunActions(array $context): array;

    /**
     * Apply: executa de verdade. So chamado apos validacoes.
     *
     * @return array<string,mixed>
     */
    abstract protected function applyActions(array $context): array;

    /**
     * Referencia canonica de rollback (URI/ID/comando reverso).
     * Pode ser null para operacoes sem rollback definido (caller decide).
     */
    protected function rollbackRef(array $context): ?string
    {
        return null;
    }

    /**
     * Contexto opcional para cada subclass passar dados entre planActions/applyActions.
     */
    protected function buildContext(): array
    {
        return [];
    }

    /**
     * Handle final, comum a toda subclass.
     */
    final protected function handleMutative(): int
    {
        $mode = $this->resolveMode();
        $checkCode = $this->resolveCheckCode();
        $jsonOutput = (bool) $this->option('json');
        $context = $this->buildContext();
        $envelope = $this->envelope($mode, $checkCode);

        // Validacao de mode.
        if (! in_array($mode, self::ALLOWED_MODES, true)) {
            $envelope['status'] = self::STATUS_BLOCKED_INVALID_MODE;
            $envelope['reason'] = "mode '$mode' nao permitido (use: plan | dry-run | apply)";

            return $this->emit($envelope, $jsonOutput, self::FAILURE);
        }

        if ($mode === self::MODE_PLAN) {
            $envelope['status'] = self::STATUS_PLANNED;
            $envelope['actions'] = $this->planActions($context);
            $envelope['rollback_ref'] = $this->rollbackRef($context);

            return $this->emit($envelope, $jsonOutput, self::SUCCESS);
        }

        // Para dry-run e apply, exigir check_code se houver lista.
        $available = $this->availableCheckCodes();
        if ($available !== [] && $checkCode === null) {
            $envelope['status'] = self::STATUS_BLOCKED_MISSING_CHECK;
            $envelope['reason'] = '--check CODE obrigatorio para dry-run e apply';
            $envelope['available_checks'] = $available;

            return $this->emit($envelope, $jsonOutput, self::FAILURE);
        }
        if ($available !== [] && $checkCode !== null && ! in_array($checkCode, $available, true)) {
            $envelope['status'] = self::STATUS_BLOCKED_INVALID_CHECK;
            $envelope['reason'] = "check '$checkCode' nao reconhecido";
            $envelope['available_checks'] = $available;

            return $this->emit($envelope, $jsonOutput, self::FAILURE);
        }

        if ($mode === self::MODE_DRY_RUN) {
            try {
                $result = DB::transaction(function () use ($context): array {
                    $r = $this->dryRunActions($context);
                    // Forcar rollback obrigatorio mesmo em sucesso. Lancamos exception controlada.
                    DB::rollBack();

                    return $r;
                });

                $envelope['status'] = self::STATUS_DRY_RUN_OK;
                $envelope['actions'] = $result;
                $envelope['rollback_ref'] = $this->rollbackRef($context);

                return $this->emit($envelope, $jsonOutput, self::SUCCESS);
            } catch (\Throwable $e) {
                $envelope['status'] = self::STATUS_DRY_RUN_FAILED;
                $envelope['reason'] = $e->getMessage();
                $envelope['rollback_ref'] = $this->rollbackRef($context);

                return $this->emit($envelope, $jsonOutput, self::FAILURE);
            }
        }

        // mode == apply.
        $confirmed = (bool) ($this->option('confirm') ?? false);
        if (! $confirmed) {
            $envelope['status'] = self::STATUS_BLOCKED_NOT_CONFIRMED;
            $envelope['reason'] = '--confirm obrigatorio em mode=apply (proibido apply silencioso)';

            return $this->emit($envelope, $jsonOutput, self::FAILURE);
        }

        try {
            $result = $this->applyActions($context);

            $envelope['status'] = self::STATUS_APPLIED;
            $envelope['actions'] = $result;
            $envelope['rollback_ref'] = $this->rollbackRef($context);

            return $this->emit($envelope, $jsonOutput, self::SUCCESS);
        } catch (\Throwable $e) {
            $envelope['status'] = self::STATUS_APPLY_FAILED;
            $envelope['reason'] = $e->getMessage();
            $envelope['rollback_ref'] = $this->rollbackRef($context);

            return $this->emit($envelope, $jsonOutput, self::FAILURE);
        }
    }

    protected function resolveMode(): string
    {
        $raw = (string) ($this->option('mode') ?? self::MODE_PLAN);
        $normalized = Str::of($raw)->lower()->trim()->value();

        return $normalized === '' ? self::MODE_PLAN : $normalized;
    }

    protected function resolveCheckCode(): ?string
    {
        $raw = $this->option('check');
        if ($raw === null) {
            return null;
        }
        $trimmed = trim((string) $raw);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function envelope(string $mode, ?string $checkCode): array
    {
        $envelopeId = (string) Str::uuid();

        $base = [
            'schema_version' => 'atlas.command.three_tier_envelope.v1',
            'envelope_id' => $envelopeId,
            'command_name' => $this->mutativeName(),
            'mode' => $mode,
            'check_code' => $checkCode,
            'status' => null,
            'reason' => null,
            'available_checks' => $this->availableCheckCodes(),
            'actions' => null,
            'rollback_ref' => null,
            'created_at' => now()->toIso8601String(),
        ];

        // envelope_hash deterministico (sem timestamps mutantes ou uuids).
        $hashPayload = [
            'schema_version' => $base['schema_version'],
            'command_name' => $base['command_name'],
            'mode' => $base['mode'],
            'check_code' => $base['check_code'],
        ];
        $base['envelope_hash'] = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return $base;
    }

    protected function emit(array $envelope, bool $jsonOutput, int $exitCode): int
    {
        if ($jsonOutput) {
            $this->line(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}');

            return $exitCode;
        }

        $this->line('Atlas Mutative Command Envelope');
        $this->line('  schema_version : '.$envelope['schema_version']);
        $this->line('  command_name   : '.$envelope['command_name']);
        $this->line('  mode           : '.$envelope['mode']);
        $this->line('  check_code     : '.($envelope['check_code'] ?? 'null'));
        $this->line('  status         : '.$envelope['status']);
        if ($envelope['reason']) {
            $this->line('  reason         : '.$envelope['reason']);
        }
        if ($envelope['rollback_ref']) {
            $this->line('  rollback_ref   : '.$envelope['rollback_ref']);
        }
        $this->line('  envelope_hash  : '.$envelope['envelope_hash']);
        if (is_array($envelope['actions']) && $envelope['actions'] !== []) {
            $this->line('  actions:');
            foreach ($envelope['actions'] as $key => $action) {
                $line = is_string($action) ? $action : json_encode($action, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->line('    - '.(is_string($key) ? "$key: " : '').$line);
            }
        }

        return $exitCode;
    }
}
