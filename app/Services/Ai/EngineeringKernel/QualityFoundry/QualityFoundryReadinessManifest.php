<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\QualityFoundry;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;

/**
 * Read-only audit of the canonical Quality Foundry plan checklists.
 *
 * This is deliberately a plan-completion manifest, not a claim of runtime
 * quality: an unchecked plan item remains a blocker and no scorecard PASS can
 * promote it to completion.
 */
final class QualityFoundryReadinessManifest
{
    public const SCHEMA = 'atlas.quality_foundry.readiness_manifest.v1';

    /** @var list<string> */
    private const PLAN_FILES = [
        'docs/superpowers/plans/quality-foundry/01-factory-v2-closure.md',
        'docs/superpowers/plans/quality-foundry/02-quality-constitution-and-evidence.md',
        'docs/superpowers/plans/quality-foundry/03-elite-workcell-product-and-spec.md',
        'docs/superpowers/plans/quality-foundry/04-world-model-and-capability-market.md',
        'docs/superpowers/plans/quality-foundry/05-verification-release-and-outcomes.md',
        'docs/superpowers/plans/quality-foundry/06-dev-forge-autonomos-dominance.md',
        'docs/superpowers/plans/quality-foundry/07-rivals-world-engineering-trial.md',
        'docs/superpowers/plans/quality-foundry/08-causal-compounding-and-domain-waves.md',
    ];

    /** @return array<string,mixed> */
    public function build(): array
    {
        $plans = [];
        $openItems = [];
        $missingFiles = [];
        $totalItems = 0;
        $completedItems = 0;

        foreach (self::PLAN_FILES as $path) {
            $absolute = $this->absolutePath($path);
            if (! is_file($absolute)) {
                $missingFiles[] = $path;
                $plans[] = [
                    'plan' => $path,
                    'exists' => false,
                    'sections' => [],
                    'total_items' => 0,
                    'completed_items' => 0,
                    'open_items' => 0,
                ];

                continue;
            }

            $sections = [];
            $section = null;
            $lines = file($absolute, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $lineNumber => $line) {
                if (preg_match('/^### Packet\s+(\d+):\s*(.+)$/', $line, $heading) === 1) {
                    $section = [
                        'packet' => (int) $heading[1],
                        'title' => trim($heading[2]),
                        'total_items' => 0,
                        'completed_items' => 0,
                        'open_items' => 0,
                    ];
                    $sections[] = $section;
                }

                if (preg_match('/^- \[([ xX])\]\s+(.+)$/', $line, $check) !== 1) {
                    continue;
                }

                $isComplete = strtolower($check[1]) === 'x';
                $totalItems++;
                if ($isComplete) {
                    $completedItems++;
                }

                $sectionIndex = count($sections) - 1;
                if ($sectionIndex < 0) {
                    $section = [
                        'packet' => 0,
                        'title' => 'Unsectioned',
                        'total_items' => 0,
                        'completed_items' => 0,
                        'open_items' => 0,
                    ];
                    $sections[] = $section;
                    $sectionIndex = 0;
                }

                $sections[$sectionIndex]['total_items']++;
                if ($isComplete) {
                    $sections[$sectionIndex]['completed_items']++;
                } else {
                    $sections[$sectionIndex]['open_items']++;
                    $openItems[] = [
                        'plan' => $path,
                        'packet' => $sections[$sectionIndex]['packet'],
                        'title' => $sections[$sectionIndex]['title'],
                        'line' => $lineNumber + 1,
                        'requirement' => trim($check[2]),
                    ];
                }
            }

            $plans[] = [
                'plan' => $path,
                'exists' => true,
                'sections' => $sections,
                'total_items' => array_sum(array_column($sections, 'total_items')),
                'completed_items' => array_sum(array_column($sections, 'completed_items')),
                'open_items' => array_sum(array_column($sections, 'open_items')),
            ];
        }

        $blockers = [];
        if ($missingFiles !== []) {
            $blockers[] = 'master_plan_files_missing';
        }
        if ($openItems !== []) {
            $blockers[] = 'master_plan_checklist_open';
        }

        $payload = [
            'schema' => self::SCHEMA,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'completion_allowed' => $blockers === [],
            'blockers' => $blockers,
            'missing_files' => $missingFiles,
            'summary' => [
                'plan_count' => count(self::PLAN_FILES),
                'packet_count' => count($plans),
                'total_items' => $totalItems,
                'completed_items' => $completedItems,
                'open_items' => count($openItems),
            ],
            'packets' => $plans,
            'open_items' => $openItems,
            'evidence_boundary' => 'checklist_audit_only_runtime_evidence_must_be_verified_separately',
            'verification_refs' => $this->verificationRefs(),
        ];
        $payload['manifest_hash'] = CanonicalKernelPayload::hash($payload);
        $payload['generated_at'] = now()->toIso8601String();

        return $payload;
    }

    /** @return array<string,mixed> */
    private function verificationRefs(): array
    {
        $paths = [
            'tests/Unit/Ai/EngineeringKernel/QualityFoundryModeParityServiceTest.php',
            'tests/Unit/Ai/EngineeringKernel/ExecutionOrderModeParityTest.php',
            'tests/Feature/Architecture/EngineeringKernelBypassRegressionTest.php',
            'tests/Feature/Ai/AgenticWorkcell/AtlasAgenticWorkcellRuntimeServiceTest.php',
        ];
        $refs = array_map(function (string $path): array {
            $absolute = $this->absolutePath($path);

            return [
                'kind' => 'test_ref',
                'path' => $path,
                'exists' => is_file($absolute),
                'sha256' => is_file($absolute) ? hash_file('sha256', $absolute) : null,
            ];
        }, $paths);

        return [
            'status' => collect($refs)->every(fn (array $ref): bool => $ref['exists'] === true) ? 'refs_present' : 'refs_missing',
            'test_command' => 'php artisan test tests/Unit/Ai/EngineeringKernel/QualityFoundryModeParityServiceTest.php tests/Unit/Ai/EngineeringKernel/ExecutionOrderModeParityTest.php tests/Feature/Architecture/EngineeringKernelBypassRegressionTest.php tests/Feature/Ai/AgenticWorkcell/AtlasAgenticWorkcellRuntimeServiceTest.php',
            'refs' => $refs,
        ];
    }

    private function absolutePath(string $path): string
    {
        if (function_exists('base_path')) {
            try {
                return base_path($path);
            } catch (\Throwable) {
                // Pure unit tests may load Laravel helpers without a Foundation app.
            }
        }

        return dirname(__DIR__, 5).DIRECTORY_SEPARATOR.$path;
    }
}
