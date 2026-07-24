<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityMapDriftDetector;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMaturityGapIndex;
use App\Services\Ai\SelfConstruction\MultiAgentLoopCertification\AtlasMultiAgentLoopCertificationRunner;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only combined final-95 blocker + domain-drift report. Merges
 * {@see AtlasExternalBrainMaturityGapIndex} (proof gaps against the maturity rubric) with
 * {@see AtlasExternalBrainCapabilityMapDriftDetector} (capability-map vs. queue/outcome drift)
 * so origination targets what is actually missing proof or drifted, never queued vanity work.
 *
 * Never enqueues, mutates evidence, calls providers, or runs git — read-only reporting only.
 *
 * Input: a single JSON file (--input=PATH) with keys:
 *   { rubric:list, control_plane_snapshot:{...}, map_entries:list, queued_areas:list, outcomes?:list, multi_agent_loop_proof?:{...} }
 * Missing/absent sections default to empty and simply produce no gaps/findings for that side.
 */
final class AtlasExternalBrainMaturityGapCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:maturity-gap
        {--input= : Path to a JSON file with rubric, control_plane_snapshot, map_entries, queued_areas, outcomes}';

    /** @var string */
    protected $description = 'Read-only final-95 blocker + capability-map drift report (combines maturity gap index + drift detector).';

    public function handle(
        AtlasExternalBrainMaturityGapIndex $maturityGapIndex,
        AtlasExternalBrainCapabilityMapDriftDetector $driftDetector,
        ?AtlasMultiAgentLoopCertificationRunner $certificationRunner = null,
    ): int {
        $inputPath = trim((string) $this->option('input'));
        if ($inputPath === '' || ! is_file($inputPath)) {
            $this->error('--input=<path> required and must exist');

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($inputPath), true);
        if (! is_array($decoded)) {
            $this->error('invalid input JSON');

            return self::FAILURE;
        }

        $rubric = is_array($decoded['rubric'] ?? null) ? $decoded['rubric'] : [];
        $controlPlaneSnapshot = is_array($decoded['control_plane_snapshot'] ?? null) ? $decoded['control_plane_snapshot'] : [];
        $mapEntries = is_array($decoded['map_entries'] ?? null) ? $decoded['map_entries'] : [];
        $queuedAreas = is_array($decoded['queued_areas'] ?? null) ? $decoded['queued_areas'] : [];
        $outcomes = is_array($decoded['outcomes'] ?? null) ? $decoded['outcomes'] : [];

        $gapReport = $maturityGapIndex->compute($rubric, $controlPlaneSnapshot);
        $driftReport = $driftDetector->detect([
            'map_entries' => $mapEntries,
            'queued_areas' => $queuedAreas,
            'outcomes' => $outcomes,
        ]);

        $certificationVerdict = null;
        if ($certificationRunner !== null) {
            $multiAgentProof = is_array($decoded['multi_agent_loop_proof'] ?? null)
                ? $decoded['multi_agent_loop_proof']
                : ['proof_payload' => $controlPlaneSnapshot];
            $certificationVerdict = $certificationRunner->run($multiAgentProof);
        }

        $payload = [
            'status' => 'ok',
            'blockers' => $gapReport['gaps'],
            'complete_dimensions' => $gapReport['complete_dimensions'],
            'domain_map_drift' => $driftReport['findings'],
            'has_drift' => $driftReport['has_drift'],
            'total_drift_findings' => $driftReport['total_findings'],
            'multi_agent_loop_certification' => $certificationVerdict,
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
