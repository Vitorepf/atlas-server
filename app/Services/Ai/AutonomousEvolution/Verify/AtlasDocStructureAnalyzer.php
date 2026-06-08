<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Verify;

/**
 * DOC-STRUCTURE ANALYZER — the single source of truth for the canonical-module
 * structural rules docs-health enforces, shared by the per-file frozen linter
 * (the P2 acceptance) and the unified orchestrator's discovery scan.
 *
 * It checks ONE markdown file, from the file alone: doc_schema declared, a valid
 * graph_layer, and all 12 canonical sections present. Structural fixes (adding a
 * missing section, correcting the layer) are behavior-free and safe for the loop to
 * grind autonomously — unlike a fake-implemented claim, where the fix is a judgment
 * call. That is why doc-STRUCTURE is an auto-loop mode and doc-CLAIM is flag-only.
 */
final class AtlasDocStructureAnalyzer
{
    public const SCHEMA = 'atlas.loop.doc_structure.v1';

    /** The 12 canonical-module sections docs-health requires, in the exact ASCII forms it matches. */
    public const REQUIRED_SECTIONS = [
        'Resumo', 'Papel no Atlas', 'Onde Se Encaixa', 'Contratos', 'Fluxo', 'Regras para IA',
        'Escopo de Implementacao', 'Dependencias', 'Evidencias', 'Riscos', 'Exemplos', 'Proximas Acoes',
    ];

    public const VALID_LAYERS = ['system', 'module', 'flow', 'world', 'gear'];

    /**
     * @return array{
     *     schema_version:string,
     *     path:string,
     *     is_module:bool,
     *     violations:list<string>,
     *     note?:string
     * }
     */
    public function analyzeFile(string $absPath): array
    {
        $content = @file_get_contents($absPath);
        if ($content === false) {
            return ['schema_version' => self::SCHEMA, 'path' => $absPath, 'is_module' => false, 'violations' => ['unreadable'], 'note' => 'unreadable'];
        }

        return $this->analyzeContent($absPath, $content);
    }

    /**
     * @return array{schema_version:string,path:string,is_module:bool,violations:list<string>,note?:string}
     */
    public function analyzeContent(string $path, string $content): array
    {
        $frontmatter = preg_match('/^---\r?\n(.*?)\r?\n---/s', $content, $m) === 1 ? $m[1] : '';
        $violations = [];

        $isModule = preg_match('/^\s*doc_schema:\s*atlas_canonical_module_doc\.v1\s*$/m', $frontmatter) === 1;
        if (! $isModule) {
            $violations[] = 'must_declare_doc_schema';
        } else {
            if (preg_match('/^\s*graph_layer:\s*([a-z]+)\s*$/m', $frontmatter, $g) === 1) {
                if (! in_array($g[1], self::VALID_LAYERS, true)) {
                    $violations[] = 'graph_layer_invalid('.$g[1].')';
                }
            } else {
                $violations[] = 'graph_layer_missing';
            }
            foreach (self::REQUIRED_SECTIONS as $section) {
                if (preg_match('/^##\s+'.preg_quote($section, '/').'\s*$/m', $content) !== 1) {
                    $violations[] = 'missing_section['.$section.']';
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'path' => $path,
            'is_module' => $isModule,
            'violations' => $violations,
        ];
    }
}
