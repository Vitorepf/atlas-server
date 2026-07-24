<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\EngineeringKernel\PressureLayerGuards;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * atlas:pressure:guard — run ONE of the 3 Cognitive Pressure Layer advisory guards
 * (runtime_verifier | context_cartographer | boundary_wiring_guard) over a candidate,
 * gate it (exit non-zero = land blocked), and optionally record the verdict into the
 * proof-gated outcome ledger the Decision Core weighs (--record). Deterministic,
 * reuse-first, no provider calls. This is the real consumer surface for the guards.
 *
 * @see docs/engineering-knowledge-base/atlas-orchestrator-canon.md
 */
final class AtlasPressureGuardCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:pressure:guard
        {guard : runtime_verifier|context_cartographer|boundary_wiring_guard}
        {--target= : rel path of the organ (runtime_verifier)}
        {--objective= : stated objective (context_cartographer)}
        {--symbol=* : cited symbol(s) (context_cartographer)}
        {--declared=* : declared target rel path(s) (boundary_wiring_guard)}
        {--diff=* : actual diff rel path(s) (boundary_wiring_guard)}
        {--task-category=programming : outcome ledger task_category}
        {--runtime=unknown : runtime whose candidate is judged (outcome ledger provider)}
        {--record : persist the verdict into the outcome ledger the Decision Core weighs}
        {--json : emit JSON}';

    protected $description = 'Run a Cognitive Pressure Layer advisory guard; exit non-zero blocks the land; --record feeds the proof-gated outcome ledger.';

    public function handle(PressureLayerGuards $guards): int
    {
        $guard = strtolower(trim((string) $this->argument('guard')));

        $verdict = match ($guard) {
            PressureLayerGuards::RUNTIME_VERIFIER => $this->runRuntimeVerifier($guards),
            PressureLayerGuards::CONTEXT_CARTOGRAPHER => $guards->contextCartographer((string) $this->option('objective'), $this->listOption('symbol')),
            PressureLayerGuards::BOUNDARY_WIRING_GUARD => $guards->boundaryWiringGuard($this->listOption('declared'), $this->listOption('diff')),
            default => ['__error' => "unknown guard '{$guard}': use runtime_verifier|context_cartographer|boundary_wiring_guard"],
        };

        if (isset($verdict['__error'])) {
            return $this->failWith((string) $verdict['__error']);
        }

        $gate = $guards->gate($verdict);

        $recorded = null;
        if ((bool) $this->option('record')) {
            $recorded = $guards->recordVerdict(
                $verdict,
                (string) ($this->option('task-category') ?: 'programming'),
                (string) ($this->option('runtime') ?: 'unknown'),
            );
        }

        $payload = [
            'guard' => $verdict['guard'],
            'pass' => $verdict['pass'],
            'blocked' => $gate['blocked'],
            'proven_real' => $verdict['proven_real'],
            'detail' => $verdict['detail'],
            'evidence' => $verdict['evidence'],
            'recorded_to_ledger' => $recorded !== null,
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->components->twoColumnDetail('guard', (string) $verdict['guard']);
            $this->components->twoColumnDetail('pass', YesNo::format($verdict['pass']));
            $this->components->twoColumnDetail('blocked (land)', $gate['blocked'] ? 'YES' : 'no');
            $this->components->twoColumnDetail('proven_real', YesNo::format($verdict['proven_real']));
            $this->components->twoColumnDetail('detail', (string) $verdict['detail']);
            $this->components->twoColumnDetail('recorded to ledger', $recorded !== YesNo::format(null));
        }

        return $gate['blocked'] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function runRuntimeVerifier(PressureLayerGuards $guards): array
    {
        $target = trim((string) $this->option('target'));
        if ($target === '') {
            return ['__error' => 'runtime_verifier requires --target=<rel path>'];
        }

        return $guards->runtimeVerifier($target);
    }

    /**
     * @return list<string>
     */
    private function listOption(string $name): array
    {
        $vals = (array) $this->option($name);

        return array_values(array_filter(
            array_map(static fn ($v): string => trim((string) $v), $vals),
            static fn (string $v): bool => $v !== '',
        ));
    }

    private function failWith(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['error' => $message], JSON_PRETTY_PRINT));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
