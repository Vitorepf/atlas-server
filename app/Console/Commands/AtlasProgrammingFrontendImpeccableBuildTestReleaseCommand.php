<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendImpeccableBuildTestReleaseService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Impeccable Build / Test / Release teardown CLI.
 *
 *   php artisan atlas:aaeos:programming-frontend-impeccable-build-test-release --json
 *
 * Emits the folded ship/hold decision over the four documented "Regras para IA"
 * (source-of-truth guard, detector-rule rebuild propagation, live E2E
 * requirement and the release preflight gate) using safe defaults.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-build-test-release.md
 */
class AtlasProgrammingFrontendImpeccableBuildTestReleaseCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-frontend-impeccable-build-test-release
        {--json : Emit canonical JSON payload}';

    protected $description = 'Evaluate the Impeccable frontend build/test/release rules (source guard, rebuild propagation, live E2E, release gate) into one ship/hold decision.';

    public function handle(AtlasProgrammingFrontendImpeccableBuildTestReleaseService $service): int
    {
        try {
            // Safe defaults: a clean, fully-built, fully-pushed tree with a
            // changelog and no touched live scripts — i.e. the shippable case.
            $report = $service->evaluate([
                'dirty_tree' => false,
                'head_pushed' => true,
                'changelog_present' => true,
                'build_fresh' => true,
                'detector_rules_changed' => false,
                'live_script_touched' => false,
                'edited_path' => 'src/skill.md',
                'fresh_stages' => [
                    'skill_source',
                    'provider_transforms',
                    'dist_universal_zip',
                    'site_assets',
                    'release',
                ],
            ]);
        } catch (Throwable $exception) {
            $payload = [
                'ok' => false,
                'action' => 'programming-frontend-impeccable-build-test-release',
                'error' => $exception->getMessage(),
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('[atlas:aaeos:programming-frontend-impeccable-build-test-release] '.$exception->getMessage());
            }

            return self::FAILURE;
        }

        $payload = [
            'ok' => ($report['decision'] ?? null) === 'ship',
            'action' => 'programming-frontend-impeccable-build-test-release',
            'report' => $report,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Impeccable build/test/release decision: '.($report['decision'] ?? 'unknown'));
            $blockers = (array) data_get($report, 'release_gate.blockers', []);
            $this->line('Release blockers: '.($blockers === [] ? 'none' : implode(', ', $blockers)));
        }

        return self::SUCCESS;
    }
}
