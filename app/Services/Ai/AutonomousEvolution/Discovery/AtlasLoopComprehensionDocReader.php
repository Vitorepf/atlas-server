<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

final class AtlasLoopComprehensionDocReader
{
    private const GAP_MARKER_PATTERN = '/\b(falta|gap|todo|pendente|missing|aberto)\b/i';

    /**
     * @return array{
     *   sections:list<array{doc_path:string, heading_chain:list<string>, line_number:int}>,
     *   doc_stated_gaps:list<array{doc_path:string, heading_chain:list<string>, line_number:int, text:string, gap_marker_hit:string}>
     * }
     */
    public function read(string $docsDir): array
    {
        $sections = [];
        $gaps = [];

        foreach (glob(rtrim($docsDir, '/').'/loop-*.md') ?: [] as $path) {
            $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];
            $headingChain = [];

            foreach ($lines as $index => $line) {
                $lineNumber = $index + 1;
                if (preg_match('/^(#{1,6})\s+(.*\S)\s*$/', $line, $heading) === 1) {
                    $level = strlen($heading[1]);
                    $headingChain = array_slice($headingChain, 0, $level - 1);
                    $headingChain[] = trim($heading[2]);
                    $sections[] = [
                        'doc_path' => $path,
                        'heading_chain' => array_values($headingChain),
                        'line_number' => $lineNumber,
                    ];

                    continue;
                }

                if (preg_match('/^\s*[-*+]\s+(.*\S)\s*$/', $line, $bullet) !== 1) {
                    continue;
                }
                if (preg_match(self::GAP_MARKER_PATTERN, $bullet[1], $marker) !== 1) {
                    continue;
                }

                $gaps[] = [
                    'doc_path' => $path,
                    'heading_chain' => array_values($headingChain),
                    'line_number' => $lineNumber,
                    'text' => trim($bullet[1]),
                    'gap_marker_hit' => strtolower($marker[1]),
                ];
            }
        }

        return [
            'sections' => $sections,
            'doc_stated_gaps' => $gaps,
        ];
    }
}
