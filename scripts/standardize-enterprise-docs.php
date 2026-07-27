<?php

declare(strict_types=1);
use App\Services\Ai\AutonomousEvolution\Verify\AtlasDocStructureAnalyzer;

$basePath = realpath(__DIR__.'/..');
$docsRoot = $basePath.'/docs/engineering-knowledge-base';

$requiredSections = [
    'Resumo' => 'Resumo canonico para leitura humana, Cartografia e agentes implementadores.',
    'Papel no Atlas' => 'Define a responsabilidade desta peca dentro da arquitetura Atlas.',
    'Onde Se Encaixa' => 'Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.',
    'Contratos' => 'Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.',
    'Fluxo' => 'Descreve o caminho operacional ou a sequencia de uso quando aplicavel.',
    'Regras para IA' => 'Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.',
    'Escopo de Implementacao' => 'Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.',
    'Dependencias' => 'Dependencias canonicas vivem em frontmatter e no corpo deste documento.',
    'Evidencias' => 'Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.',
    'Riscos' => 'Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.',
    'Exemplos' => 'Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.',
    'Proximas Acoes' => 'Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.',
];

$summary = [
    'scanned' => 0,
    'skipped_historical' => 0,
    'updated' => 0,
    'unchanged' => 0,
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($docsRoot, FilesystemIterator::SKIP_DOTS),
);

foreach ($iterator as $file) {
    if (! $file instanceof SplFileInfo || strtolower($file->getExtension()) !== 'md') {
        continue;
    }

    $path = $file->getPathname();
    $relativePath = str_replace($basePath.'/', '', $path);
    $markdown = file_get_contents($path);
    if ($markdown === false || ! preg_match('/^---\R(.*?)\R---\R(.*)$/s', $markdown, $matches)) {
        continue;
    }

    $summary['scanned']++;

    $frontmatter = $matches[1];
    $body = $matches[2];
    $status = scalar($frontmatter, 'status') ?: 'active';
    if (str_contains($relativePath, '/archive/') || in_array($status, ['archived', 'source_material'], true)) {
        $summary['skipped_historical']++;

        continue;
    }

    $title = scalar($frontmatter, 'title') ?: titleFromPath($relativePath);
    $category = scalar($frontmatter, 'category') ?: categoryFromPath($relativePath);
    $id = scalar($frontmatter, 'id') ?: slugFromPath($relativePath);
    $graphId = slug($id ?: $relativePath);
    $graphStatus = graphStatus($status);
    $graphLayer = graphLayer($relativePath, $category);
    $graphKind = graphKind($relativePath, $category, $title);
    $owner = owner($relativePath, $category);
    $risk = riskLevel($relativePath, $category);
    $summaryText = scalar($frontmatter, 'summary') ?: 'Documento canonico da Engineering Knowledge Base do Atlas.';

    $additions = [
        'doc_schema' => 'atlas_canonical_module_doc.v1',
        'graph_id' => $graphId,
        'graph_title' => $title,
        'graph_world' => 'atlas',
        'graph_layer' => $graphLayer,
        'graph_kind' => $graphKind,
        'graph_parent' => parentGraph($relativePath, $graphLayer),
        'graph_status' => $graphStatus,
        'graph_source' => 'repo',
        'owner' => $owner,
        'repo_paths' => [$relativePath],
        'allowed_changes' => ['Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.'],
        'forbidden_changes' => ['Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.'],
        'depends_on' => ['atlas-ai-documentation-operating-system'],
        'flows_to' => ['atlas-cartography', 'atlas-code'],
        'unlocks' => ['ai-safe-implementation-context'],
        'governs' => [$owner],
        'evidence' => [$relativePath],
        'required_tests' => ['php artisan atlas:engineering:knowledge docs-health --json'],
        'requires_evidence' => true,
        'risk_level' => $risk,
        'visual_tags' => [$graphLayer, $graphKind, $owner],
        'ai_entrypoints' => ['Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.'],
        'ai_usage_notes' => ['Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.'],
        'quality_gates' => ['php artisan atlas:engineering:knowledge docs-health --json'],
        'failure_modes' => ['Contexto desatualizado entre doc, codigo, teste e evidencia.'],
        'observability_signals' => ['docs-health status ok'],
        'next_actions' => ['Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.'],
    ];

    $newFrontmatter = rtrim($frontmatter);
    foreach ($additions as $key => $value) {
        if (hasKey($newFrontmatter, $key)) {
            continue;
        }
        $newFrontmatter .= "\n".renderYamlField($key, $value);
    }

    $newBody = rtrim($body);
    foreach ($requiredSections as $section => $defaultText) {
        if (preg_match('/^##\s+'.preg_quote($section, '/').'\s*$/mi', $newBody)) {
            continue;
        }
        $text = $section === 'Resumo' ? $summaryText : $defaultText;
        // Mark generated prose so the ratchet can tell a stub from a written
        // section. Without it, appending 12 headings takes a doc from 12
        // violations to 0 while adding no information about the doc itself.
        // The author deletes this line when the section is actually written.
        $marker = AtlasDocStructureAnalyzer::PLACEHOLDER_MARKER;
        $newBody .= "\n\n## {$section}\n\n{$marker}\n{$text}";
    }

    $newMarkdown = "---\n{$newFrontmatter}\n---\n".ltrim($newBody)."\n";
    if ($newMarkdown === $markdown) {
        $summary['unchanged']++;

        continue;
    }

    file_put_contents($path, $newMarkdown);
    $summary['updated']++;
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

function scalar(string $frontmatter, string $key): ?string
{
    if (! preg_match('/^'.preg_quote($key, '/').':\s*(.*?)\s*$/m', $frontmatter, $match)) {
        return null;
    }
    $value = trim($match[1], " \t\n\r\0\x0B\"'");

    return $value === '' || $value === '[]' ? null : $value;
}

function hasKey(string $frontmatter, string $key): bool
{
    return (bool) preg_match('/^'.preg_quote($key, '/').':/m', $frontmatter);
}

function renderYamlField(string $key, mixed $value): string
{
    if (is_bool($value)) {
        return $key.': '.($value ? 'true' : 'false')."\n";
    }
    if (is_array($value)) {
        $yaml = $key.":\n";
        foreach ($value as $item) {
            $yaml .= '  - '.yamlScalar((string) $item)."\n";
        }

        return $yaml;
    }

    return $key.': '.yamlScalar((string) $value)."\n";
}

function yamlScalar(string $value): string
{
    if ($value === '' || preg_match('/[:\[\]#\n]/', $value)) {
        return '"'.str_replace('"', '\"', $value).'"';
    }

    return $value;
}

function titleFromPath(string $path): string
{
    $name = pathinfo($path, PATHINFO_FILENAME);

    return ucwords(str_replace(['-', '_'], ' ', $name));
}

function categoryFromPath(string $path): string
{
    $parts = explode('/', $path);

    return $parts[2] ?? 'architecture';
}

function slugFromPath(string $path): string
{
    return slug(str_replace(['docs/engineering-knowledge-base/', '.md', '/'], ['', '', '-'], $path));
}

function slug(string $value): string
{
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'atlas-doc';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'atlas-doc';
}

function graphStatus(string $status): string
{
    return match ($status) {
        'future' => 'future',
        'building', 'draft', 'scaffold' => 'building',
        'deprecated' => 'deprecated',
        default => 'active',
    };
}

function graphLayer(string $path, string $category): string
{
    if (str_contains($path, '/self-construction/') || str_contains($path, '/spec-operating-system/')) {
        return 'gear';
    }
    if (str_contains($category, 'surface') || str_contains($path, 'surface')) {
        return 'module';
    }
    if (str_contains($category, 'roadmap') || str_contains($path, 'flow') || str_contains($path, 'pipeline')) {
        return 'flow';
    }
    if (str_contains($category, 'architecture') || str_contains($category, 'governance')) {
        return 'system';
    }

    return 'module';
}

function graphKind(string $path, string $category, string $title): string
{
    $haystack = strtolower($path.' '.$category.' '.$title);
    if (str_contains($haystack, 'readme') || str_contains($haystack, 'index')) {
        return 'index';
    }
    if (str_contains($haystack, 'contract') || str_contains($haystack, 'contrato')) {
        return 'contract';
    }
    if (str_contains($haystack, 'runbook')) {
        return 'runbook';
    }
    if (str_contains($haystack, 'policy') || str_contains($haystack, 'governance')) {
        return 'policy';
    }
    if (str_contains($haystack, 'surface') || str_contains($haystack, 'screen')) {
        return 'surface';
    }
    if (str_contains($haystack, 'flow') || str_contains($haystack, 'pipeline')) {
        return 'flow';
    }
    if (str_contains($haystack, 'adr')) {
        return 'adr';
    }

    return 'module';
}

function parentGraph(string $path, string $layer): string
{
    if ($path === 'docs/engineering-knowledge-base/README.md') {
        return 'atlas';
    }
    if ($layer === 'gear') {
        return 'atlas-ai-self-construction-os';
    }
    if ($layer === 'flow') {
        return 'atlas-ai-pipeline';
    }

    return 'atlas-ai-canonical-architecture-index';
}

function owner(string $path, string $category): string
{
    $relative = str_replace('docs/engineering-knowledge-base/', '', $path);
    $parts = explode('/', $relative);

    return slug($parts[0] !== basename($relative) ? $parts[0] : $category);
}

function riskLevel(string $path, string $category): string
{
    $haystack = strtolower($path.' '.$category);
    if (str_contains($haystack, 'security') || str_contains($haystack, 'self-construction') || str_contains($haystack, 'runtime') || str_contains($haystack, 'constitutional')) {
        return 'high';
    }
    if (str_contains($haystack, 'architecture') || str_contains($haystack, 'policy') || str_contains($haystack, 'governance')) {
        return 'medium';
    }

    return 'low';
}
