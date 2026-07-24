<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativeImplementationReleasePreflight;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativePatchRollbackRunner;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativePostApplyVerifier;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativeScopedPatchApplyRunner;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read/write CLI for the native implementation release pipeline. Four verbs:
 *   preflight — pure scope+evidence preflight (no I/O).
 *   apply     — atomic write to a project root from a preflight-allowed proposal.
 *   verify    — converts apply receipts + gate results into a verdict.
 *   rollback  — restores preimage contents on failure.
 *
 * Every action returns a structured receipt; NEVER shells out and NEVER calls providers.
 */
final class AtlasSelfConstructionNativeImplementationReleaseCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:self-construction:native-implementation-release {action : preflight|apply|verify|rollback} {--payload=} {--json}';

    /** @var string */
    protected $description = 'Native implementation release surface: preflight / apply / verify / rollback.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $payload = $this->readJson('payload');

        $result = match ($action) {
            'preflight' => $this->preflight($payload),
            'apply' => $this->apply($payload),
            'verify' => $this->verify($payload),
            'rollback' => $this->rollback($payload),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line($this->encode($result));

        return ($result['status'] ?? 'ok') === 'ok' || ! isset($result['status']) ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string,mixed>|null $payload @return array<string,mixed> */
    private function preflight(?array $payload): array
    {
        if (! is_array($payload) || ! isset($payload['proposal'])) {
            return ['status' => 'usage_error', 'reason' => '--payload JSON with proposal{} required'];
        }
        $r = $this->app()->make(AtlasSelfConstructionNativeImplementationReleasePreflight::class)->preflight((array) $payload['proposal']);

        return ['status' => 'ok', 'preflight' => $r];
    }

    /** @param array<string,mixed>|null $payload @return array<string,mixed> */
    private function apply(?array $payload): array
    {
        if (! is_array($payload) || ! isset($payload['preflight'], $payload['proposal'], $payload['project_root'])) {
            return ['status' => 'usage_error', 'reason' => '--payload JSON with preflight{},proposal{},project_root required'];
        }
        try {
            $runner = new AtlasSelfConstructionNativeScopedPatchApplyRunner((string) $payload['project_root']);
            $r = $runner->apply((array) $payload['preflight'], (array) $payload['proposal']);
        } catch (Throwable $e) {
            return ['status' => 'apply_invalid', 'reason' => $e->getMessage()];
        }

        return ['status' => 'ok', 'apply' => $r];
    }

    /** @param array<string,mixed>|null $payload @return array<string,mixed> */
    private function verify(?array $payload): array
    {
        if (! is_array($payload) || ! isset($payload['facts'])) {
            return ['status' => 'usage_error', 'reason' => '--payload JSON with facts{} required'];
        }
        $r = $this->app()->make(AtlasSelfConstructionNativePostApplyVerifier::class)->verify((array) $payload['facts']);

        return ['status' => 'ok', 'verify' => $r];
    }

    /** @param array<string,mixed>|null $payload @return array<string,mixed> */
    private function rollback(?array $payload): array
    {
        if (! is_array($payload) || ! isset($payload['facts'], $payload['project_root'])) {
            return ['status' => 'usage_error', 'reason' => '--payload JSON with facts{},project_root required'];
        }
        try {
            $runner = new AtlasSelfConstructionNativePatchRollbackRunner((string) $payload['project_root']);
            $r = $runner->rollback((array) $payload['facts']);
        } catch (Throwable $e) {
            return ['status' => 'rollback_invalid', 'reason' => $e->getMessage()];
        }

        return ['status' => 'ok', 'rollback' => $r];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $optionName): ?array
    {
        $path = (string) ($this->option($optionName) ?? '');
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
