<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council;

use Throwable;

/**
 * CORTEX COUNCIL — LENS #4 of 5: TEST-COVERAGE. Observes a {@see CortexSubject} (file path) and emits FACTS
 * about test coverage from the configured artifact channel (no live test runs — pure read of existing junit
 * or clover XML output produced by the CI). The lens emits one FACT per method/line:
 *   {kind: 'covered_method' | 'uncovered_method' | 'covered_line' | 'uncovered_line',
 *    method, line, by_test_class}
 *
 * EXPLICIT FACTS-ONLY CONTRACT (anti-Goodhart): the lens emits ONLY individual covered/uncovered FACTS — no
 * aggregate verdict of any kind is computed here. Downstream code that wants aggregate numbers MUST derive
 * them from the FACT counts itself — keeps the lens honest and prevents the council from being turned into a
 * hidden judgement rig.
 *
 * The subject's facts MUST carry:
 *   - `file_path` : absolute path to the file under analysis (so we can compare mtime vs artifact)
 *
 * Configured artifact path is read from `cortex.council.coverage_artifact` (clover.xml format).
 *
 * DISAGREEMENT SIGNALS:
 *   - `coverage_artifact_missing` : no artifact configured / file absent
 *   - `coverage_artifact_stale`   : artifact mtime older than subject file mtime
 *   - `coverage_artifact_unparseable` : artifact present but XML parse failed
 *   - `subject_not_in_artifact`   : artifact loaded but subject file not present in it
 */
final class AtlasCortexTestCoverageLens implements LensContract
{
    public const CONFIG_ARTIFACT_KEY = 'cortex.council.coverage_artifact';

    public function id(): string
    {
        return 'testcoverage';
    }

    public function name(): string
    {
        return 'Test-Coverage';
    }

    public function observe(CortexSubject $subject): LensObservation
    {
        $filePath = isset($subject->facts['file_path']) ? trim((string) $subject->facts['file_path']) : '';
        $artifactPath = $this->configuredArtifactPath();

        if ($artifactPath === '' || ! is_file($artifactPath) || ! is_readable($artifactPath)) {
            return new LensObservation($this->id(), $subject->id, ['entries' => []], ['coverage_artifact_missing']);
        }

        $disagreement = [];
        if ($filePath !== '' && is_file($filePath) && (int) @filemtime($artifactPath) < (int) @filemtime($filePath)) {
            $disagreement[] = 'coverage_artifact_stale';
        }

        $xmlText = (string) @file_get_contents($artifactPath);
        $dom = $this->loadDom($xmlText);
        if ($dom === null) {
            return new LensObservation($this->id(), $subject->id, ['entries' => []], array_values(array_unique(array_merge($disagreement, ['coverage_artifact_unparseable']))));
        }

        $entries = $this->extractFactsForFile($dom, $filePath);
        if ($entries === []) {
            $disagreement[] = 'subject_not_in_artifact';
        }

        return new LensObservation($this->id(), $subject->id, ['entries' => $entries], array_values(array_unique($disagreement)));
    }

    private function configuredArtifactPath(): string
    {
        if (! function_exists('config')) {
            return '';
        }
        try {
            return (string) config(self::CONFIG_ARTIFACT_KEY, '');
        } catch (Throwable) {
            return '';
        }
    }

    private function loadDom(string $xml): ?\DOMDocument
    {
        if ($xml === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $ok = @$dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok ? $dom : null;
    }

    /**
     * Clover format: <coverage><project><file name="..."><class name="..."><metrics ...>
     *                  <line num=".." type="method|stmt" count="N"/></file></project></coverage>
     *
     * @return list<array{kind:string, method:?string, line:int, by_test_class:?string}>
     */
    private function extractFactsForFile(\DOMDocument $dom, string $filePath): array
    {
        $facts = [];
        $xpath = new \DOMXPath($dom);
        $files = $xpath->query('//file');
        if ($files === false || $files->length === 0) {
            return [];
        }

        foreach ($files as $fileNode) {
            if (! $fileNode instanceof \DOMElement) {
                continue;
            }
            $name = $fileNode->getAttribute('name');
            if (! $this->fileMatches($name, $filePath)) {
                continue;
            }
            // Walk over each <line> child.
            $lines = $xpath->query('.//line', $fileNode);
            if ($lines === false) {
                continue;
            }
            $currentMethod = null;
            foreach ($lines as $lineNode) {
                if (! $lineNode instanceof \DOMElement) {
                    continue;
                }
                $type = $lineNode->getAttribute('type'); // method | stmt
                $num = (int) $lineNode->getAttribute('num');
                $count = (int) $lineNode->getAttribute('count');
                $methodName = $lineNode->hasAttribute('name') ? $lineNode->getAttribute('name') : null;

                if ($type === 'method') {
                    $currentMethod = $methodName;
                    $facts[] = [
                        'kind' => $count > 0 ? 'covered_method' : 'uncovered_method',
                        'method' => $methodName,
                        'line' => $num,
                        'by_test_class' => null,
                    ];

                    continue;
                }
                // stmt line — attribute to the surrounding method if known.
                $facts[] = [
                    'kind' => $count > 0 ? 'covered_line' : 'uncovered_line',
                    'method' => $currentMethod,
                    'line' => $num,
                    'by_test_class' => null,
                ];
            }
        }

        usort($facts, static fn (array $x, array $y): int => $x['line'] <=> $y['line']);

        return $facts;
    }

    private function fileMatches(string $artifactPath, string $subjectPath): bool
    {
        if ($subjectPath === '') {
            return false;
        }
        if ($artifactPath === $subjectPath) {
            return true;
        }
        // Allow relative-vs-absolute mismatch when one path is a suffix of the other.
        if (str_ends_with($subjectPath, '/'.$artifactPath) || str_ends_with($artifactPath, '/'.$subjectPath)) {
            return true;
        }

        return false;
    }
}
