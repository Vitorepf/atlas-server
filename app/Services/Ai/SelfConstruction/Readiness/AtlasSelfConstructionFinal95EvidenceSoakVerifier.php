<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

/**
 * Pure verifier that final readiness requires evidence across multiple worker
 * identities and task families, not only repeated healthy samples from one
 * narrow path.
 *
 * Fails with insufficient_worker_diversity or insufficient_task_family_diversity
 * when the soak window is healthy but too narrow.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasSelfConstructionFinal95EvidenceSoakVerifier
{
    public const SCHEMA = 'atlas.self_construction.final95_evidence_soak_verifier.v1';

    private const MIN_WORKERS = 2;
    private const MIN_FAMILIES = 2;

    /**
     * @param  list<array{
     *   worker_id?:string,
     *   task_family?:string,
     *   outcome?:string,
     * }>  $samples
     * @return array{
     *   schema:string,
     *   ready:bool,
     *   blockers:list<string>,
     *   worker_count:int,
     *   family_count:int,
     * }
     */
    public function verify(array $samples): array
    {
        $workers = [];
        $families = [];

        foreach ($samples as $sample) {
            $workerId = (string) ($sample['worker_id'] ?? '');
            $family = (string) ($sample['task_family'] ?? '');
            if ($workerId !== '') {
                $workers[$workerId] = true;
            }
            if ($family !== '') {
                $families[$family] = true;
            }
        }

        $workerCount = count($workers);
        $familyCount = count($families);
        $blockers = [];

        if ($workerCount < self::MIN_WORKERS) {
            $blockers[] = 'insufficient_worker_diversity:need>=' . self::MIN_WORKERS . '_got_' . $workerCount;
        }

        if ($familyCount < self::MIN_FAMILIES) {
            $blockers[] = 'insufficient_task_family_diversity:need>=' . self::MIN_FAMILIES . '_got_' . $familyCount;
        }

        return [
            'schema' => self::SCHEMA,
            'ready' => count($blockers) === 0,
            'blockers' => $blockers,
            'worker_count' => $workerCount,
            'family_count' => $familyCount,
        ];
    }
}
