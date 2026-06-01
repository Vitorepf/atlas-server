<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDetectionEngineeringService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cyber Detection Engineering CLI.
 *
 *   php artisan atlas:aaeos:detection-engineering [--json]
 *
 * Read-only, deterministic. Emits the detection-as-code contract manifest:
 * lifecycle order, per-severity False Positive Budget, detection kinds, the
 * behavioral-coverage target, and a worked assessment of a sample Sigma rule
 * (a paired, testable, in-budget `live` behavioral detection -> pass).
 *
 * @see docs/engineering-knowledge-base/cyber-security/detection-engineering.md
 */
class AtlasDetectionEngineeringCommand extends Command
{
    protected $signature = 'atlas:aaeos:detection-engineering {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas cyber · detection engineering contract (lifecycle, FP budget, Red-Blue pairing, behavioral target).';

    public function handle(AtlasDetectionEngineeringService $service): int
    {
        try {
            $manifest = $service->manifest();

            $this->line((string) json_encode(
                ['ok' => true, 'manifest' => $manifest],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            $sampleVerdict = $manifest['sample_assessment']['verdict'] ?? 'fail';

            return $sampleVerdict === 'pass' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'detection_engineering_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
