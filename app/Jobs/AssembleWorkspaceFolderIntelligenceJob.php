<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AtlasCode\WorkspaceIntelligenceAssemblyService;
use App\Services\AtlasCode\WorkspaceIntelligenceStatusReader;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AP-818 F2.1 · Job de assembly de inteligência on-link.
 *
 * Enfileirado pelo upsert do AtlasCodeWorkspaceController quando o operador
 * vincula/atualiza uma pasta (flag auto_assemble ON). Roda na fila
 * `folder-intel` da conexão `database-long` (retry_after alto — um index de
 * umbrella legitimamente leva minutos), drenada pelo scheduler launchd
 * (schedule:run a cada 60s → queue:work --stop-when-empty em background).
 *
 * Garantias:
 *   - ShouldBeUnique por pasta: salvar a ficha 5x não enfileira 5 assemblies.
 *   - $tries=1: o lock W-10 + o no-op por frescor (W-9) tornam retry cego
 *     pior que re-disparo explícito; falha vira marker failed + evidence.
 *   - NUNCA roda inline: este job é o ÚNICO caminho de assembly automático.
 */
final class AssembleWorkspaceFolderIntelligenceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Um umbrella de 16 repos em série pode legitimamente levar longos minutos. */
    public int $timeout = 1500;

    public int $tries = 1;

    /** Janela de unicidade: enquanto o assembly desta pasta está na fila/rodando. */
    public int $uniqueFor = 1800;

    public function __construct(
        public readonly string $workspacePath,
        public readonly bool $force = false,
    ) {
        $this->onConnection('database-long');
        $this->onQueue('folder-intel');
    }

    public function uniqueId(): string
    {
        return sha1(rtrim($this->workspacePath, '/'));
    }

    public function handle(
        WorkspaceIntelligenceAssemblyService $assembly,
        WorkspaceIntelligenceStatusReader $statusReader,
        CodeGraphWorkspaceIdentity $identity,
    ): void {
        $summary = $assembly->assemble($this->workspacePath, $this->force);

        // Ativação AOBG deferida do upsert (medida em 30s+ pro umbrella — a
        // razão de ela viver AQUI e não na request). Best-effort: onboarding
        // indisponível nunca falha o assembly que já aconteceu.
        $aobg = null;
        try {
            $aobg = app(\App\Services\Ai\AtlasAobgWorkspaceOnboardingService::class)
                ->activate(['workspace' => $this->workspacePath]);
        } catch (Throwable $exception) {
            $aobg = ['ok' => false, 'reason' => substr($exception->getMessage(), 0, 120)];
        }

        Log::info('folder_intel_assembly_summary', [
            'workspace_path' => $this->workspacePath,
            'status' => $summary['status'],
            'repos' => count($summary['repos'] ?? []),
            'duration_ms' => $summary['duration_ms'] ?? null,
            'aobg_activation_ok' => (bool) ($aobg['ok'] ?? false),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        // Timeout/kill do worker: marca o estado e prova no ledger — a ficha
        // mostra 'failed' honesto em vez de 'indexing' eterno.
        try {
            $statusReader = app(WorkspaceIntelligenceStatusReader::class);
            $workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve($this->workspacePath);
            $statusReader->markFailed($workspaceId);

            app(WorkspaceIntelligenceAssemblyService::class)->recordEvidence(
                'failed',
                $workspaceId,
                'Assembly interrompido (timeout/exceção do worker): '.substr((string) $exception?->getMessage(), 0, 160),
                ['workspace_path' => $this->workspacePath],
            );
        } catch (Throwable) {
            // último recurso: o marker TTL expira sozinho em 2h.
        }
    }
}
