<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\Aaeos\AaeosGeneratedContractGate;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Engineering\EliteCompactionInventoryService;
use App\Services\Engineering\EliteCompactionSelfConstructionOrganizer;
use App\Services\Engineering\EliteCompactionWavePruner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class AtlasEliteCompactionCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:elite:compaction
        {action=status : status|baseline|inventory|deprecate-loop-commands|strip-loop-corpses|organize-sc|prune-acde|prune-generated|hard-delete-loop|cleanup-loop-wiring|prove|docs-gate|final-dod}
        {--json : machine-readable output}
        {--dry-run : preview without writes}
        {--yes : confirm destructive prune}
        {--force-after-sunset : allow hard-delete-loop before recorded sunset (drills only)}
        {--limit=200 : prune-acde wave size}';

    protected $description = 'Elite compaction obra — baseline, inventory, loop deprecation, prune, proof battery.';

    public function handle(
        EliteCompactionInventoryService $inventory,
        EliteCompactionWavePruner $pruner,
        AaeosGeneratedContractGate $generatedGate,
        EliteExecutorKernel $eliteKernel,
        AtlasCognitionScoreCardService $scorecard,
    ): int {
        return match ((string) $this->argument('action')) {
            'status' => $this->renderStatus($inventory, $generatedGate, $eliteKernel),
            'baseline' => $this->renderBaseline($inventory),
            'inventory' => $this->renderInventory($inventory),
            'deprecate-loop-commands' => $this->deprecateLoopCommands($pruner),
            'strip-loop-corpses' => $this->stripLoopCorpses($pruner),
            'organize-sc' => $this->organizeSelfConstruction(),
            'prune-acde' => $this->pruneAcde($pruner),
            'prune-generated' => $this->pruneGenerated($pruner),
            'hard-delete-loop' => $this->hardDeleteLoop($pruner),
            'cleanup-loop-wiring' => $this->cleanupLoopWiring($pruner),
            'prove' => $this->proveElite($scorecard, $inventory),
            'docs-gate' => $this->docsGate(),
            'final-dod' => $this->finalDod($inventory, $scorecard),
            default => $this->invalidAction(),
        };
    }

    private function renderStatus(
        EliteCompactionInventoryService $inventory,
        AaeosGeneratedContractGate $generatedGate,
        EliteExecutorKernel $eliteKernel,
    ): int {
        $payload = [
            'schema' => 'atlas.elite_compaction.status.v1',
            'freeze_active' => (bool) config('atlas_elite_compaction.freeze.active', false),
            'loop_sunset' => config('atlas_elite_compaction.loop_commands.hard_remove_after'),
            'generated' => $generatedGate->status(),
            'elite_kernel' => $eliteKernel->contract(),
            'metrics' => [
                'atlas_loop_commands' => $this->countLoopCommandsQuick(),
            ],
        ];
        $this->emit($payload);

        return self::SUCCESS;
    }

    private function countLoopCommandsQuick(): int
    {
        return count(glob(base_path('app/Console/Commands/AtlasLoop*.php')) ?: []);
    }

    private function renderBaseline(EliteCompactionInventoryService $inventory): int
    {
        $baseline = $inventory->captureBaseline();
        $path = $inventory->persistBaseline($baseline);
        $payload = array_merge($baseline, ['persisted_at' => $path]);
        $this->emit($payload);

        return self::SUCCESS;
    }

    private function renderInventory(EliteCompactionInventoryService $inventory): int
    {
        $payload = [
            'acde' => $inventory->inventoryAcde(),
            'generated' => $inventory->inventoryGenerated(),
            'self_construction' => $inventory->inventorySelfConstruction(),
        ];
        $this->emit($payload);

        return self::SUCCESS;
    }

    private function deprecateLoopCommands(EliteCompactionWavePruner $pruner): int
    {
        $result = $pruner->stubLoopCommands((bool) $this->option('dry-run'));
        $this->emit($result);

        return ($result['errors'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function pruneAcde(EliteCompactionWavePruner $pruner): int
    {
        if (! (bool) $this->option('yes') && ! (bool) $this->option('dry-run')) {
            $this->error('prune-acde requires --yes or --dry-run');

            return self::FAILURE;
        }
        $result = $pruner->pruneAcdeDeadCluster(
            (bool) $this->option('dry-run'),
            max(1, (int) $this->option('limit')),
        );
        $this->emit($result);

        if (($result['blocked'] ?? false) === true) {
            $this->error('prune-acde blocked: '.(string) ($result['block_reason'] ?? 'inventory_unsafe'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function cleanupLoopWiring(EliteCompactionWavePruner $pruner): int
    {
        $result = $pruner->cleanupLoopWiring((bool) $this->option('dry-run'));
        $this->emit($result);

        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    private function stripLoopCorpses(EliteCompactionWavePruner $pruner): int
    {
        $result = $pruner->stripLoopCommandCorpses((bool) $this->option('dry-run'));
        $this->emit($result);

        return ($result['errors'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function organizeSelfConstruction(): int
    {
        if (! (bool) $this->option('yes') && ! (bool) $this->option('dry-run')) {
            $this->error('organize-sc requires --yes or --dry-run');

            return self::FAILURE;
        }
        $organizer = app(EliteCompactionSelfConstructionOrganizer::class);
        if ((bool) $this->option('dry-run')) {
            $this->emit($organizer->plan());

            return self::SUCCESS;
        }
        $result = $organizer->organize(false);
        // Obra 3 / ELITE-02: always run FQCN repair after organize (and when already organized).
        $repair = $organizer->repairMovedFqcnImports(false);
        $result['fqcn_repair'] = $repair;
        $result['import_updates'] = (int) ($result['import_updates'] ?? 0) + (int) ($repair['import_updates'] ?? 0);
        $this->emit($result);

        return ($result['failed_count'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function pruneGenerated(EliteCompactionWavePruner $pruner): int
    {
        if (! (bool) $this->option('yes') && ! (bool) $this->option('dry-run')) {
            $this->error('prune-generated requires --yes or --dry-run');

            return self::FAILURE;
        }
        $result = $pruner->pruneGeneratedOrphans((bool) $this->option('dry-run'));
        $this->emit($result);

        return self::SUCCESS;
    }

    private function hardDeleteLoop(EliteCompactionWavePruner $pruner): int
    {
        if (! (bool) $this->option('yes') && ! (bool) $this->option('dry-run')) {
            $this->error('hard-delete-loop requires --yes or --dry-run');

            return self::FAILURE;
        }
        $result = $pruner->hardDeleteLoopCommands(
            (bool) $this->option('dry-run'),
            (bool) $this->option('force-after-sunset'),
        );
        $this->emit($result);

        return ($result['allowed'] ?? true) === false ? self::FAILURE : self::SUCCESS;
    }

    private function proveElite(AtlasCognitionScoreCardService $scorecard, EliteCompactionInventoryService $inventory): int
    {
        $checks = [];
        foreach ([
            'scorecard' => fn () => Artisan::call('atlas:cognition:scorecard', ['--json' => true]),
            'dev_proof' => fn () => Artisan::call('atlas:proof:status', ['--json' => true]),
            'brain' => fn () => Artisan::call('atlas:brain:summary', ['--compact' => true]),
            'task_serving' => fn () => Artisan::call('atlas:task:serving', ['action' => 'status', '--json' => true]),
            'context_runtime' => fn () => $scorecard->build(),
        ] as $name => $runner) {
            try {
                $runner();
                $checks[$name] = ['ok' => true];
            } catch (\Throwable $e) {
                $checks[$name] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        $hygiene = $this->hygieneChecks();
        $sc = $inventory->inventorySelfConstruction();
        $payload = [
            'schema' => 'atlas.elite_compaction.proof.v1',
            'checks' => $checks,
            'hygiene' => $hygiene,
            'self_construction_root_files' => $sc['root_file_count'] ?? null,
            'failed_count' => count(array_filter($checks, fn (array $c): bool => ! ($c['ok'] ?? false)))
                + count(array_filter($hygiene, fn (array $c): bool => ! ($c['ok'] ?? false))),
            'ok' => true,
        ];
        $payload['ok'] = ($payload['failed_count'] ?? 1) === 0;
        $this->emit($payload);

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, array{ok:bool, detail?:string}>
     */
    private function hygieneChecks(): array
    {
        $checks = [];
        $aucriHits = shell_exec("rg -n 'AtlasAucriRuntimeEnforcementService' app/Services/Ai/Programming app/Services/Ai/SelfConstruction 2>/dev/null | wc -l");
        $checks['aucri_ban_outside_context'] = [
            'ok' => ((int) trim((string) $aucriHits)) === 0,
            'detail' => trim((string) $aucriHits).' hits',
        ];
        $legacyDocs = shell_exec("rg -n 'núcleo rápido|Dev =.*leve|Loop = vivo' docs/engineering-knowledge-base/atlas-dual-core*.md docs/engineering-knowledge-base/atlas-programming-governance*.md docs/engineering-knowledge-base/atlas-programming-superiority*.md 2>/dev/null | wc -l");
        $checks['docs_mae_elite_identity'] = [
            'ok' => ((int) trim((string) $legacyDocs)) === 0,
            'detail' => trim((string) $legacyDocs).' legacy hits',
        ];
        $freezeActive = (bool) config('atlas_elite_compaction.freeze.active', false);
        $freezeEndedAt = trim((string) config('atlas_elite_compaction.freeze.ended_at', ''));
        $checks['freeze_active'] = [
            // Obra 3 / AUT-01: freeze ON during obra; after final-dod lift, ended_at proves intentional lift.
            'ok' => $freezeActive || $freezeEndedAt !== '',
            'detail' => $freezeActive ? 'on' : ($freezeEndedAt !== '' ? 'lifted:'.$freezeEndedAt : 'missing'),
        ];
        $checks['generated_quarantine'] = [
            'ok' => ! (bool) config('atlas_elite_compaction.generated.hot_path_enabled', false),
        ];
        $loopCmds = count(glob(base_path('app/Console/Commands/AtlasLoop*.php')) ?: []);
        $checks['loop_commands_zero'] = [
            'ok' => $loopCmds === 0,
            'detail' => $loopCmds.' remaining',
        ];
        $scheduleHits = shell_exec("rg -n \"Schedule::command\\('atlas:loop:\" routes/console.php 2>/dev/null | wc -l");
        $checks['loop_schedules_zero'] = [
            'ok' => ((int) trim((string) $scheduleHits)) === 0,
            'detail' => trim((string) $scheduleHits).' hits',
        ];
        $genCount = count(glob(base_path('app/Services/Ai/Aaeos/Generated/*.php')) ?: []);
        $qCount = count(glob(base_path('archive/app/Services/Ai/Aaeos/Quarantine/*.php')) ?: []);
        $checks['generated_hot_path_off'] = [
            'ok' => ! (bool) config('atlas_elite_compaction.generated.hot_path_enabled', false),
            'detail' => "generated={$genCount} quarantine={$qCount}",
        ];
        $checks['sc_root_cap'] = [
            'ok' => count(glob(base_path('app/Services/Ai/SelfConstruction/*.php')) ?: []) <= 10,
            'detail' => (string) count(glob(base_path('app/Services/Ai/SelfConstruction/*.php')) ?: []),
        ];

        return $checks;
    }

    private function finalDod(EliteCompactionInventoryService $inventory, AtlasCognitionScoreCardService $scorecard): int
    {
        $baselinePath = storage_path('app/atlas/elite-compaction/phase0-baseline.json');
        $baseline = is_file($baselinePath)
            ? json_decode((string) file_get_contents($baselinePath), true)
            : $inventory->captureBaseline();
        $current = $inventory->captureBaseline();

        $checks = [];
        foreach ([
            'scorecard' => fn () => Artisan::call('atlas:cognition:scorecard', ['--json' => true]),
            'dev_proof' => fn () => Artisan::call('atlas:proof:status', ['--json' => true]),
            'brain' => fn () => Artisan::call('atlas:brain:summary', ['--compact' => true]),
            'task_serving' => fn () => Artisan::call('atlas:task:serving', ['action' => 'status', '--json' => true]),
            'context_runtime' => fn () => $scorecard->build(),
        ] as $name => $runner) {
            try {
                $runner();
                $checks[$name] = ['ok' => true];
            } catch (\Throwable $e) {
                $checks[$name] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        $hygiene = $this->hygieneChecks();
        $failed = count(array_filter($checks, fn (array $c): bool => ! ($c['ok'] ?? false)))
            + count(array_filter($hygiene, fn (array $c): bool => ! ($c['ok'] ?? false)));
        $proveOk = $failed === 0;

        $payload = [
            'schema' => 'atlas.elite_compaction.final_dod.v1',
            'baseline' => $baseline['metrics'] ?? [],
            'current' => $current['metrics'] ?? [],
            'prove_ok' => $proveOk,
            'checks' => $checks,
            'hygiene' => $hygiene,
            'loop_sunset' => config('atlas_elite_compaction.loop_commands.hard_remove_after'),
            'hard_delete_allowed' => now()->toDateString() >= (string) config('atlas_elite_compaction.loop_commands.hard_remove_after'),
        ];
        $this->emit($payload);

        return $proveOk ? self::SUCCESS : self::FAILURE;
    }


    private function docsGate(): int
    {
        $code = Artisan::call('atlas:engineering:knowledge', [
            'action' => 'docs-health',
            '--json' => true,
            '--enforce' => true,
        ]);
        $this->line(trim(Artisan::output()));

        return $code;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);
        } else {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    private function invalidAction(): int
    {
        $this->error('Unknown action. Use: status|baseline|inventory|deprecate-loop-commands|strip-loop-corpses|organize-sc|prune-acde|prune-generated|hard-delete-loop|prove|docs-gate|final-dod');

        return self::INVALID;
    }
}
