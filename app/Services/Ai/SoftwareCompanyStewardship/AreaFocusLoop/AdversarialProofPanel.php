<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Contract for the independent adversarial proof panel: the second pre-merge line
 * of defense. Implementations run N independent verifiers that each try to REFUTE
 * the merge candidate; the merge is allowed only when none refutes. The seam is an
 * interface so the live runtime binds the real {@see AdversarialProofPanelService}
 * while tests can inject deterministic verdicts without subclassing a final class.
 */
interface AdversarialProofPanel
{
    /**
     * @param  array<string,mixed>  $cycle
     * @return array{
     *     schema_version: string,
     *     cycle_id: string,
     *     verifier_count: int,
     *     verifier_verdicts: list<array{verifier:string,refuted:bool,detail:string}>,
     *     refuted_count: int,
     *     majority_refuted: bool,
     *     merge_allowed: bool,
     *     reason: string,
     * }
     */
    public function refute(array $cycle): array;
}
