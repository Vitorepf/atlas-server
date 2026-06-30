<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativePatchMaterializer;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativePatchPlanner;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativeTestFeedbackRepairLoop;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Read-only operator surface for the native-implementation brain.
 *
 *   templates    list the canonical template ids (no facts needed)
 *   plan         echo the supplied packet/patch_plan shape (no business decision; safety lint only)
 *   materialize  AtlasSelfConstructionNativePatchMaterializer::materialize(patch_plan)
 *   repair       AtlasSelfConstructionNativeTestFeedbackRepairLoop::repair(failures, patch_plan)
 *   patch-plan   AtlasSelfConstructionNativePatchPlanner::plan(packet) — template-driven patch PLAN
 *                (target_files, template_ids, variables, required_imports, test_plan, risk_notes); a
 *                read-only preview of the intended patch BEFORE materialize applies it
 *
 * NEVER writes files, calls providers, runs processes, or touches git.
 */
final class AtlasSelfConstructionNativeImplementationCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    protected $signature = 'atlas:self-construction:native-implementation {action : templates|plan|materialize|repair|patch-plan} {--packet=} {--facts=} {--json}';

    protected $description = 'Read-only native-implementation CLI: templates | plan | materialize | repair | patch-plan.';

    public function handle(
        AtlasSelfConstructionNativePatchMaterializer $materializer,
        AtlasSelfConstructionNativeTestFeedbackRepairLoop $repair,
        AtlasSelfConstructionNativePatchPlanner $patchPlanner,
    ): int {
        $action = (string) $this->argument('action');

        $payload = match ($action) {
            'templates' => $this->templates(),
            'plan' => $this->plan(),
            'materialize' => $this->materialize($materializer),
            'repair' => $this->repair($repair),
            'patch-plan' => $this->patchPlan($patchPlanner),
            default => null,
        };
        if ($payload === null) {
            $this->emitError('refused', 'unknown_action:'.$action);

            return self::EXIT_USAGE;
        }
        if (isset($payload['__usage_error__'])) {
            return self::EXIT_USAGE;
        }

        $this->emit($payload);

        return self::EXIT_OK;
    }

    /**
     * @return array<string,mixed>
     */
    private function templates(): array
    {
        return [
            'templates' => [
                AtlasSelfConstructionNativeTestFeedbackRepairLoop::TEMPLATE_STUB_CLASS,
                AtlasSelfConstructionNativeTestFeedbackRepairLoop::TEMPLATE_FIX_NAMESPACE,
                AtlasSelfConstructionNativeTestFeedbackRepairLoop::TEMPLATE_ALIGN_ASSERTION,
                AtlasSelfConstructionNativeTestFeedbackRepairLoop::TEMPLATE_ADD_USE,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function plan(): array
    {
        $packet = $this->loadPacket();
        if ($packet === null) {
            return ['__usage_error__' => true];
        }
        $allowed = array_values((array) ($packet['allowed_files'] ?? []));
        $patches = array_values((array) ($packet['patches'] ?? []));
        $blockers = [];
        if ($allowed === []) {
            $blockers[] = 'allowed_files_empty';
        }
        foreach ($patches as $p) {
            if (! is_array($p)) {
                $blockers[] = 'patch_not_object';

                continue;
            }
            $path = (string) ($p['path'] ?? '');
            if ($path === '' || ! in_array($path, $allowed, true)) {
                $blockers[] = 'forbidden_path:'.$path;
            }
        }

        return [
            'plan_ok' => $blockers === [],
            'allowed_files' => $allowed,
            'patch_count' => count($patches),
            'blockers' => $blockers,
        ];
    }

    /**
     * Template-driven patch PLAN preview (distinct from the path-validation `plan` action): emits the planner's
     * structured plan, or a refusal when the packet maps to no template / escapes scope. Read-only.
     *
     * @return array<string,mixed>
     */
    private function patchPlan(AtlasSelfConstructionNativePatchPlanner $planner): array
    {
        $packet = $this->loadPacket();
        if ($packet === null) {
            return ['__usage_error__' => true];
        }
        try {
            return $planner->plan($packet);
        } catch (RuntimeException $e) {
            return ['patch_plan_ok' => false, 'refused' => true, 'reason' => $e->getMessage()];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function materialize(AtlasSelfConstructionNativePatchMaterializer $materializer): array
    {
        $packet = $this->loadPacket();
        if ($packet === null) {
            return ['__usage_error__' => true];
        }

        return $materializer->materialize($packet);
    }

    /**
     * @return array<string,mixed>
     */
    private function repair(AtlasSelfConstructionNativeTestFeedbackRepairLoop $repair): array
    {
        $packet = $this->loadPacket();
        $facts = $this->loadFacts();
        if ($packet === null || $facts === null) {
            return ['__usage_error__' => true];
        }

        return $repair->repair((array) ($facts['failures'] ?? []), $packet);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadPacket(): ?array
    {
        return $this->loadJson('packet');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadFacts(): ?array
    {
        return $this->loadJson('facts');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadJson(string $option): ?array
    {
        $path = (string) $this->option($option);
        if ($path === '' || ! is_file($path)) {
            $this->emitError('usage_error', '--'.$option.'=<path> is required for this action');

            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->emitError('usage_error', $option.'_payload_not_valid_json');

            return null;
        }
        if (! is_array($decoded)) {
            $this->emitError('usage_error', $option.'_payload_root_must_be_object');

            return null;
        }

        return $decoded;
    }

    private function emitError(string $status, string $reason): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['status' => $status, 'reason' => $reason], self::JSON_FLAGS));
        } else {
            $this->error($reason);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        unset($payload['__usage_error__']);
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($payload as $k => $v) {
            $this->line($k.': '.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }
}
