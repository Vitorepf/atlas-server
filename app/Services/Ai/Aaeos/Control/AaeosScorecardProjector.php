<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

use App\Services\Ai\Aaeos\Spine\AaeosEngineeringSpine;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Throwable;

/**
 * Read-only AAEOS scorecard for GOD/SOTA certification.
 */
final class AaeosScorecardProjector
{
    public const SCHEMA = 'atlas.aaeos.scorecard.v1';

    public function __construct(
        private readonly AaeosOrgStateProjector $org = new AaeosOrgStateProjector,
        private readonly AaeosEngineeringSpine $spine = new AaeosEngineeringSpine,
        private readonly ?AtlasEvidenceLedger $ledger = null,
    ) {}

    /**
     * @param  array<string,mixed>  $runtimeHints  optional measured counters
     * @return array<string,mixed>
     */
    public function project(array $runtimeHints = []): array
    {
        $quarantineImports = $this->countQuarantineImports();
        $tree = $this->aaeosTreePurity();
        $orphanGeneratedTests = $this->countOrphanGeneratedTests();
        $measurement = $this->verifiedMeasurements($runtimeHints);
        $dimensions = $measurement['dimensions'];
        $unknownDimensions = $measurement['unknown_dimensions'];
        $measurementSources = $measurement['sources'];
        $fullyMeasured = $unknownDimensions === [];
        $composite = $fullyMeasured ? array_sum($dimensions) / count($dimensions) : null;
        $purityOk = $tree['pure'] && $orphanGeneratedTests === 0;

        return [
            'schema' => self::SCHEMA,
            'generated_at' => gmdate('c'),
            'org' => $this->org->project(),
            'spine_sample' => $this->spine->contractForMode(AaeosExecutorMode::AUTONOMOS),
            'quarantine_production_imports' => $quarantineImports,
            'aaeos_tree' => $tree,
            'orphan_generated_tests' => $orphanGeneratedTests,
            'dimensions' => $dimensions,
            'measurement_status' => $fullyMeasured ? 'measured' : 'unknown',
            'measurement_sources' => $measurementSources,
            'unknown_dimensions' => $unknownDimensions,
            'composite' => $composite === null ? null : round($composite, 2),
            'god_sota' => $composite !== null && $composite >= 9.0 && $quarantineImports === 0 && $purityOk,
            'target_composite' => 9.0,
            'runtime_write_performed' => false,
            'counters' => [
                'cycles_total' => (int) ($runtimeHints['cycles_total'] ?? 0),
                'halts_sovereign' => (int) ($runtimeHints['halts_sovereign'] ?? 0),
                'mode_mix' => (array) ($runtimeHints['mode_mix'] ?? [
                    'dev' => 0,
                    'forge' => 0,
                    'autonomos' => 0,
                ]),
                'spine_violations' => (int) ($runtimeHints['spine_violations'] ?? 0),
            ],
        ];
    }

    private function countQuarantineImports(): int
    {
        $root = dirname(__DIR__, 5);
        $app = $root.'/app';
        if (! is_dir($app)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/Aaeos/Quarantine/') || str_contains($path, '/archive/')) {
                continue;
            }
            // Don't score the scorecard itself or re-home comments.
            if (str_contains($path, '/Aaeos/Control/AaeosScorecardProjector.php')) {
                continue;
            }
            $text = @file_get_contents($path);
            if ($text === false) {
                continue;
            }
            if (preg_match('/^use\\s+App\\\\Services\\\\Ai\\\\Aaeos\\\\Quarantine\\\\/m', $text) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Runtime hints are untrusted until they resolve to an integrity-valid ledger event.
     *
     * @param  array<string,mixed>  $runtimeHints
     * @return array{dimensions:array<string,?float>,unknown_dimensions:list<string>,sources:list<string>}
     */
    private function verifiedMeasurements(array $runtimeHints): array
    {
        $keys = ['operate_path_wiring', 'spine_enforced', 'antifragile_loop'];
        $unknown = [
            'dimensions' => array_fill_keys($keys, null),
            'unknown_dimensions' => $keys,
            'sources' => [],
        ];
        $eventId = $runtimeHints['measurement_event_id'] ?? null;
        if (! is_string($eventId) || $eventId === '' || $this->ledger === null) {
            return $unknown;
        }

        try {
            $event = $this->ledger->eventById($eventId);
            if ($event === null
                || ! $this->ledger->eventIntegrityValid($event)
                || (string) $event->getAttribute('event_type') !== LedgerEventType::AaeosCycleRecorded->value) {
                return $unknown;
            }

            $payload = $event->payload;
            if (! is_array($payload)
                || ($payload['schema'] ?? null) !== 'atlas.aaeos.scorecard_measurement.v1') {
                return $unknown;
            }
            $measurements = $payload['measurements'] ?? null;
            $sources = $payload['measurement_sources'] ?? null;
            if (! is_array($measurements) || ! is_array($sources) || $sources === []) {
                return $unknown;
            }

            $dimensions = [];
            foreach ($keys as $key) {
                $value = $measurements[$key] ?? null;
                if ((! is_int($value) && ! is_float($value)) || $value < 0 || $value > 10) {
                    return $unknown;
                }
                $dimensions[$key] = (float) $value;
            }
            $normalizedSources = array_values(array_filter(
                $sources,
                static fn (mixed $source): bool => is_string($source) && $source !== '',
            ));
            if ($normalizedSources === []) {
                return $unknown;
            }

            return [
                'dimensions' => $dimensions,
                'unknown_dimensions' => [],
                'sources' => $normalizedSources,
            ];
        } catch (Throwable) {
            return $unknown;
        }
    }

    /**
     * @return array{pure:bool,php_files:int,foreign:list<string>}
     */
    private function aaeosTreePurity(): array
    {
        $aaeos = dirname(__DIR__);
        $foreign = [];
        $php = 0;
        if (! is_dir($aaeos)) {
            return ['pure' => false, 'php_files' => 0, 'foreign' => ['missing_aaeos_dir']];
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($aaeos));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $php++;
            $path = $file->getPathname();
            $rel = str_replace($aaeos.'/', '', $path);
            if (str_starts_with($rel, 'Control/') || str_starts_with($rel, 'Spine/')) {
                continue;
            }
            $foreign[] = $rel;
        }

        return [
            'pure' => $foreign === [] && $php > 0,
            'php_files' => $php,
            'foreign' => $foreign,
        ];
    }

    private function countOrphanGeneratedTests(): int
    {
        $root = dirname(__DIR__, 5);
        $dir = $root.'/tests/Unit/Ai/Aaeos/Generated';
        if (! is_dir($dir)) {
            return 0;
        }
        $count = 0;
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $text = @file_get_contents($file);
            if ($text === false) {
                continue;
            }
            if (preg_match('/use\\s+App\\\\Services\\\\Ai\\\\Aaeos\\\\Generated\\\\/', $text) === 1) {
                // still points at dissolved Generated namespace without live target
                if (! str_contains($text, 'AtlasLearningProposalDecisionService')
                    && ! str_contains($text, 'AtlasMemoryCognitiveImmuneLearningKernelService')) {
                    $count++;
                }
            }
        }

        return $count;
    }

}
