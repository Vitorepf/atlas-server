<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Compound;

/**
 * Engineering Kernel mechanism (OBRA #4 S4): o LEITOR que faltava para o ledger de admission do
 * task-fabric (`storage/atlas/learning-transfer/admission.resolved.jsonl`) — a ponta aberta desde o
 * mapa de outcome-learning (bridge vivo escrevendo SEM nenhum consumidor). Fecha a camada
 * Optimization do loop: outcome do ciclo N vira insumo do ciclo N+1.
 *
 * Imunidade (lição do echo 04/07): toda entrada consumida precisa de PROVENANCE verificável —
 * schema_version + agent_id + commit_sha não-vazios. Entrada sem origem é IGNORADA e contada,
 * nunca consumida.
 */
final class OutcomeDigestReader
{
    public function __construct(private readonly ?string $path = null) {}

    /**
     * @return array{admitted: list<array<string,mixed>>, ignored_no_provenance: int}
     */
    public function read(): array
    {
        $file = $this->file();
        $admitted = [];
        $ignored = 0;
        if (! is_file($file)) {
            return ['admitted' => [], 'ignored_no_provenance' => 0];
        }
        foreach (explode("\n", trim((string) file_get_contents($file))) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $entry = json_decode($line, true);
            if (! is_array($entry)) {
                $ignored++;

                continue;
            }
            // Provenance obrigatória: origem verificável ou a entrada não existe para o flywheel.
            if (trim((string) ($entry['schema_version'] ?? '')) === ''
                || trim((string) ($entry['agent_id'] ?? '')) === ''
                || trim((string) ($entry['commit_sha'] ?? '')) === '') {
                $ignored++;

                continue;
            }
            $admitted[] = $entry;
        }

        return ['admitted' => $admitted, 'ignored_no_provenance' => $ignored];
    }

    /**
     * Agregado provider-safe do que o ledger PROVA: totais + contagem por agente.
     *
     * @return array<string,mixed>
     */
    public function digest(): array
    {
        $read = $this->read();
        $perAgent = [];
        foreach ($read['admitted'] as $entry) {
            $agent = (string) $entry['agent_id'];
            $perAgent[$agent] = 1 + ($perAgent[$agent] ?? 0);
        }

        return [
            'schema_version' => 'atlas.engineering_kernel.outcome_digest.v1',
            'admitted' => count($read['admitted']),
            'ignored_no_provenance' => $read['ignored_no_provenance'],
            'per_agent' => $perAgent,
        ];
    }

    /**
     * Amostras no contrato do AtlasConductorRoutingMemory::record() — cada task resolvida com
     * commit REAL vira evidência positiva de roteamento para o agente que a completou.
     *
     * @return list<array{task_category:string, role:string, provider:string, model:string, result:string}>
     */
    public function routingSamples(): array
    {
        $samples = [];
        foreach ($this->read()['admitted'] as $entry) {
            $packet = (string) ($entry['task_packet_id'] ?? '');
            if ($packet === '') {
                continue;
            }
            $samples[] = [
                'task_category' => $this->packetFamily($packet),
                'role' => 'task_worker',
                'provider' => (string) $entry['agent_id'],
                'model' => '',
                'result' => 'success', // resolved = completado + commit_sha presente (provado acima)
            ];
        }

        return $samples;
    }

    /**
     * Sementes de exemplar (runs verdes REAIS) para o indexador de exemplares do Dev consumir:
     * commit + packet + objetivo — o ciclo N vira material de prompt do ciclo N+1.
     *
     * @return list<array{commit_sha:string, task_packet_id:string, objective_excerpt:string}>
     */
    public function exemplarSeeds(int $limit = 50): array
    {
        $seeds = [];
        foreach (array_slice($this->read()['admitted'], -max(1, $limit)) as $entry) {
            $seeds[] = [
                'commit_sha' => (string) $entry['commit_sha'],
                'task_packet_id' => (string) ($entry['task_packet_id'] ?? ''),
                'objective_excerpt' => (string) ($entry['objective_excerpt'] ?? ''),
            ];
        }

        return $seeds;
    }

    /** Família do packet = identidade estável para roteamento (sem o sufixo de slice/etapa). */
    private function packetFamily(string $packetId): string
    {
        $family = preg_replace('/-s?\d+[a-z]?(-[a-z]+)?$/i', '', $packetId);

        return $family !== null && $family !== '' ? $family : $packetId;
    }

    private function file(): string
    {
        if ($this->path !== null && $this->path !== '') {
            return $this->path;
        }
        try {
            return storage_path('atlas/learning-transfer/admission.resolved.jsonl');
        } catch (\Throwable) {
            return sys_get_temp_dir().'/admission.resolved.jsonl';
        }
    }
}
