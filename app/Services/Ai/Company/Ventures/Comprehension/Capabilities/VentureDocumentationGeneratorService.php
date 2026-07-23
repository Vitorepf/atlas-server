<?php

declare(strict_types=1);

namespace App\Services\Ai\Company\Ventures\Comprehension\Capabilities;

use App\Models\AiVentureComprehensionFinding;
use App\Models\AiVentureComprehensionRun;
use App\Models\AiVentureDocumentationArtifact;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use App\Services\Ai\Company\Ventures\Comprehension\ComprehensionCapability;
use App\Services\Ai\Company\Ventures\Comprehension\ComprehensionRecorder;
use App\Services\Ai\Company\Ventures\Comprehension\WorkspaceReader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Documentation capability: synthesizes the canonical company doc for a venture
 * from already-recorded comprehension findings plus the repository structure.
 *
 * This closes the comprehension subsystem's `docs_status: incomplete` gap. It is
 * deterministic and offline: it reads the manifest + repo roots through the
 * shared {@see WorkspaceReader} and the findings other capabilities persisted on
 * the same run, then emits a Markdown doc carrying VALID Atlas Layer-1
 * cartography frontmatter (status:draft, parented under the Venture Foundry OS so
 * it never has to be wired into the live cartography graph).
 *
 * Unlike the other capabilities it records an {@see AiVentureDocumentationArtifact},
 * not findings — and it NEVER writes into the target repo automatically: disk
 * write is the orchestrator's gated job via {@see self::writeToDisk()}.
 */
class VentureDocumentationGeneratorService implements ComprehensionCapability
{
    public const DOC_KIND = 'company_canonical';

    public const GRAPH_PARENT = 'atlas-venture-foundry-operating-system';

    /** Manifest dependencies that map to a recognizable capability/integration. */
    private const KNOWN_INTEGRATIONS = [
        'laravel/cashier' => 'Billing/subscriptions (Laravel Cashier)',
        'stripe/stripe-php' => 'Payments (Stripe)',
        'laravel/framework' => 'Laravel application framework',
        'i18next' => 'Internationalization (i18next)',
        'react' => 'React frontend',
    ];

    public function __construct(private readonly ComprehensionRecorder $recorder) {}

    public function capability(): string
    {
        return 'documentation';
    }

    /**
     * Build and persist the canonical company doc for this run.
     *
     * @return array<string,mixed> {capability, artifact_uuid, relative_path, line_count, sections, has_*}
     */
    public function scan(AiVentureComprehensionRun $run, WorkspaceReader $reader): array
    {
        $manifest = $this->readManifest($reader);
        $architecture = $this->architectureOverview($reader);
        $findings = $this->loadFindings($run);

        $businessRules = $this->summarizeBusinessRules($findings);
        $problems = $this->summarizeProblems($findings);
        $audience = $this->summarizeAudience($findings, $manifest);
        $improvements = $this->summarizeImprovements($findings);

        [$markdown, $sections] = $this->buildMarkdown(
            $run,
            $manifest,
            $architecture,
            $businessRules,
            $problems,
            $audience,
            $improvements,
        );

        $lineCount = substr_count($markdown, "\n") + 1;
        $relativePath = $this->relativePath($run);
        $contentHash = StrategyCanonicalHash::sha256($markdown);

        $artifact = AiVentureDocumentationArtifact::query()->create([
            'uuid' => (string) Str::uuid(),
            'run_id' => $run->id,
            'venture_id' => $run->venture_id,
            'doc_kind' => self::DOC_KIND,
            'title' => $this->title($manifest),
            'relative_path' => $relativePath,
            'written_to_disk' => false,
            'sections' => $sections,
            'content' => $markdown,
            'line_count' => $lineCount,
            'content_hash' => $contentHash,
            'status' => 'generated',
        ]);

        return [
            'capability' => $this->capability(),
            'artifact_uuid' => $artifact->uuid,
            'relative_path' => $relativePath,
            'line_count' => $lineCount,
            'sections' => $sections,
            'has_business_rules' => $businessRules !== [],
            'has_problems' => $problems['all'] !== [],
            'has_audience' => $audience['signals'] !== [],
        ];
    }

    /**
     * Write a generated artifact to disk under an orchestrator-provided base dir
     * and return the absolute path written. Only called on the gated --write
     * path; the scan itself never touches the target repo.
     */
    public function writeToDisk(AiVentureDocumentationArtifact $artifact, string $absoluteBaseDir): string
    {
        $relative = ltrim(str_replace('\\', '/', (string) $artifact->relative_path), '/');
        $absolute = rtrim($absoluteBaseDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relative;

        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, (string) $artifact->content);

        $artifact->forceFill(['written_to_disk' => true])->save();

        return $absolute;
    }

    /**
     * @return array<string,mixed> {description, requires:list, integrations:list, locales:list}
     */
    private function readManifest(WorkspaceReader $reader): array
    {
        $composer = $reader->json('composer.json');
        $package = $reader->json('package.json');

        $requires = [];
        foreach ([$composer['require'] ?? [], $package['dependencies'] ?? []] as $bag) {
            if (is_array($bag)) {
                foreach ($bag as $dep => $constraint) {
                    if ($dep === 'php') {
                        continue;
                    }
                    $requires[] = $dep.' '.(is_string($constraint) ? $constraint : '');
                }
            }
        }

        $integrations = [];
        foreach (self::KNOWN_INTEGRATIONS as $dep => $label) {
            $present = isset($composer['require'][$dep])
                || isset($package['dependencies'][$dep]);
            if ($present) {
                $integrations[] = $label;
            }
        }

        $description = trim((string) ($composer['description'] ?? $package['description'] ?? ''));

        return [
            'description' => $description,
            'requires' => array_values(array_unique($requires)),
            'integrations' => array_values(array_unique($integrations)),
        ];
    }

    /**
     * @return list<string> relative repo roots, '' rendered as '(workspace root)'
     */
    private function architectureOverview(WorkspaceReader $reader): array
    {
        $roots = $reader->repoRoots();
        $out = [];
        foreach ($roots as $root) {
            $out[] = $root === '' ? '(workspace root)' : $root;
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<AiVentureComprehensionFinding>
     */
    private function loadFindings(AiVentureComprehensionRun $run): array
    {
        return AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->orderByDesc('confidence')
            ->orderByDesc('leverage_score')
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    /**
     * Top business rules ordered by confidence.
     *
     * @param  list<AiVentureComprehensionFinding>  $findings
     * @return list<AiVentureComprehensionFinding>
     */
    private function summarizeBusinessRules(array $findings): array
    {
        $rules = array_values(array_filter(
            $findings,
            static fn (AiVentureComprehensionFinding $f) => $f->capability === AiVentureComprehensionFinding::CAPABILITY_BUSINESS_RULE,
        ));

        usort($rules, static fn (AiVentureComprehensionFinding $a, AiVentureComprehensionFinding $b) => ($b->confidence ?? 0.0) <=> ($a->confidence ?? 0.0));

        return array_slice($rules, 0, 12);
    }

    /**
     * Problems bucketed by severity, with criticals isolated.
     *
     * @param  list<AiVentureComprehensionFinding>  $findings
     * @return array{all:list<AiVentureComprehensionFinding>, critical:list<AiVentureComprehensionFinding>}
     */
    private function summarizeProblems(array $findings): array
    {
        $problems = array_values(array_filter(
            $findings,
            static fn (AiVentureComprehensionFinding $f) => $f->capability === AiVentureComprehensionFinding::CAPABILITY_PROBLEM,
        ));

        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($problems, static function (AiVentureComprehensionFinding $a, AiVentureComprehensionFinding $b) use ($rank): int {
            return ($rank[$a->severity ?? 'low'] ?? 4) <=> ($rank[$b->severity ?? 'low'] ?? 4);
        });

        $critical = array_values(array_filter(
            $problems,
            static fn (AiVentureComprehensionFinding $f) => $f->severity === 'critical',
        ));

        return ['all' => array_slice($problems, 0, 20), 'critical' => $critical];
    }

    /**
     * Audience / usage signals: tiers, locales and integrations.
     *
     * @param  list<AiVentureComprehensionFinding>  $findings
     * @param  array<string,mixed>  $manifest
     * @return array{signals:list<AiVentureComprehensionFinding>, integrations:list<string>}
     */
    private function summarizeAudience(array $findings, array $manifest): array
    {
        $signals = array_values(array_filter(
            $findings,
            static fn (AiVentureComprehensionFinding $f) => $f->capability === AiVentureComprehensionFinding::CAPABILITY_AUDIENCE_USAGE,
        ));

        return [
            'signals' => array_slice($signals, 0, 16),
            'integrations' => is_array($manifest['integrations'] ?? null) ? $manifest['integrations'] : [],
        ];
    }

    /**
     * Top improvements ordered by leverage score.
     *
     * @param  list<AiVentureComprehensionFinding>  $findings
     * @return list<AiVentureComprehensionFinding>
     */
    private function summarizeImprovements(array $findings): array
    {
        $improvements = array_values(array_filter(
            $findings,
            static fn (AiVentureComprehensionFinding $f) => $f->capability === AiVentureComprehensionFinding::CAPABILITY_IMPROVEMENT,
        ));

        usort($improvements, static fn (AiVentureComprehensionFinding $a, AiVentureComprehensionFinding $b) => ($b->leverage_score ?? 0.0) <=> ($a->leverage_score ?? 0.0));

        return array_slice($improvements, 0, 12);
    }

    private function title(array $manifest): string
    {
        $description = trim((string) ($manifest['description'] ?? ''));

        return $description !== ''
            ? Str::limit($description, 120, '')
            : 'Company Canonical Doc';
    }

    private function relativePath(AiVentureComprehensionRun $run): string
    {
        return 'docs/ventures/'.$run->venture_id.'/company-canonical.md';
    }

    /**
     * Assemble the Markdown doc and the list of section keys it carries.
     *
     * @param  array<string,mixed>  $manifest
     * @param  list<string>  $architecture
     * @param  list<AiVentureComprehensionFinding>  $businessRules
     * @param  array{all:list<AiVentureComprehensionFinding>, critical:list<AiVentureComprehensionFinding>}  $problems
     * @param  array{signals:list<AiVentureComprehensionFinding>, integrations:list<string>}  $audience
     * @param  list<AiVentureComprehensionFinding>  $improvements
     * @return array{0:string, 1:list<string>}
     */
    private function buildMarkdown(
        AiVentureComprehensionRun $run,
        array $manifest,
        array $architecture,
        array $businessRules,
        array $problems,
        array $audience,
        array $improvements,
    ): array {
        $title = $this->title($manifest);
        $slug = 'venture-'.$run->venture_id.'-company-canonical';

        $frontmatter = $this->frontmatter($run, $title, $slug);

        $sections = [];
        $body = [];

        $body[] = '# '.$title;
        $body[] = '';
        $body[] = '> Generated company-canonical doc for venture `'.$run->venture_id.'` from comprehension run `'.$run->uuid.'`. Deterministic synthesis of recorded findings + repo structure — cite or omit.';

        // Overview / architecture
        $sections[] = 'overview';
        $body[] = '';
        $body[] = '## Overview';
        $body[] = '';
        $description = trim((string) ($manifest['description'] ?? ''));
        $body[] = $description !== '' ? $description : '_No manifest description found._';
        $body[] = '';
        $body[] = '### Architecture';
        $body[] = '';
        $body[] = 'Repository roots:';
        foreach ($architecture as $root) {
            $body[] = '- `'.$root.'`';
        }
        if (! empty($manifest['requires'])) {
            $body[] = '';
            $body[] = 'Key dependencies:';
            foreach (array_slice($manifest['requires'], 0, 20) as $dep) {
                $body[] = '- `'.$dep.'`';
            }
        }

        // Business rules
        $sections[] = 'business_rules';
        $body[] = '';
        $body[] = '## Business Rules';
        $body[] = '';
        if ($businessRules === []) {
            $body[] = '_No business rules recorded for this run._';
        } else {
            $body[] = 'Top recorded business rules (by confidence):';
            $body[] = '';
            foreach ($businessRules as $rule) {
                $body[] = $this->renderFinding($rule);
            }
        }

        // Problems
        $sections[] = 'problems';
        $body[] = '';
        $body[] = '## Problems';
        $body[] = '';
        if ($problems['all'] === []) {
            $body[] = '_No problems recorded for this run._';
        } else {
            if ($problems['critical'] !== []) {
                $body[] = '### Critical';
                $body[] = '';
                foreach ($problems['critical'] as $problem) {
                    $body[] = $this->renderFinding($problem);
                }
                $body[] = '';
            }
            $body[] = '### All problems (by severity)';
            $body[] = '';
            foreach ($problems['all'] as $problem) {
                $body[] = $this->renderFinding($problem);
            }
        }

        // Audience / usage
        $sections[] = 'audience';
        $body[] = '';
        $body[] = '## Audience & Usage';
        $body[] = '';
        if ($audience['integrations'] !== []) {
            $body[] = 'Detected integrations:';
            foreach ($audience['integrations'] as $integration) {
                $body[] = '- '.$integration;
            }
            $body[] = '';
        }
        if ($audience['signals'] === []) {
            $body[] = '_No audience/usage signals recorded for this run._';
        } else {
            $body[] = 'Recorded audience / usage signals (tiers, locales, integrations):';
            $body[] = '';
            foreach ($audience['signals'] as $signal) {
                $body[] = $this->renderFinding($signal);
            }
        }

        // Improvements
        $sections[] = 'improvements';
        $body[] = '';
        $body[] = '## Improvements';
        $body[] = '';
        if ($improvements === []) {
            $body[] = '_No improvements recorded for this run._';
        } else {
            $body[] = 'Highest-leverage improvements:';
            $body[] = '';
            foreach ($improvements as $improvement) {
                $body[] = $this->renderFinding($improvement);
            }
        }

        $markdown = $frontmatter."\n".implode("\n", $body)."\n";

        return [$markdown, $sections];
    }

    /**
     * Render a single finding as a cited Markdown bullet (cite-or-omit).
     */
    private function renderFinding(AiVentureComprehensionFinding $finding): string
    {
        $line = '- **'.$this->escape((string) $finding->title).'**';

        $meta = [];
        if ($finding->severity !== null) {
            $meta[] = 'severity: '.$finding->severity;
        }
        if ($finding->confidence !== null) {
            $meta[] = 'confidence: '.number_format((float) $finding->confidence, 2);
        }
        if ($finding->leverage_score !== null) {
            $meta[] = 'leverage: '.number_format((float) $finding->leverage_score, 2);
        }
        if ($meta !== []) {
            $line .= ' ('.implode(', ', $meta).')';
        }

        if ($finding->evidence_path !== null) {
            $cite = '`'.$finding->evidence_path.'`';
            if ($finding->evidence_line !== null) {
                $cite .= ':'.$finding->evidence_line;
            }
            $line .= ' — '.$cite;
        }

        if ($finding->evidence_snippet !== null && trim((string) $finding->evidence_snippet) !== '') {
            $line .= "\n  - evidence: `".$this->escapeInline((string) $finding->evidence_snippet).'`';
        }

        return $line;
    }

    /**
     * Build the Atlas Layer-1 cartography frontmatter. All MANDATORY fields are
     * present so the doc satisfies AtlasCartographyContractTest; status:draft and
     * a Venture-Foundry graph_parent keep it out of the live cartography graph.
     */
    private function frontmatter(AiVentureComprehensionRun $run, string $title, string $slug): string
    {
        $summary = 'Generated company-canonical doc for venture '.$run->venture_id.', synthesized from comprehension findings and repository structure.';

        $fields = [
            'id' => $slug,
            'title' => $title,
            'status' => 'draft',
            'type' => 'venture-company-canonical',
            'human_name' => $title,
            'canonical_name' => $slug,
            'technical_name' => 'VentureCompanyCanonicalDoc',
            'cartography_type' => 'module',
            'canonical_source' => $this->relativePath($run),
            'graph_parent' => self::GRAPH_PARENT,
            'graph_id' => $slug,
            'graph_world' => 'atlas',
            'graph_layer' => 'module',
            'graph_kind' => 'venture-doc',
            'graph_status' => 'active',
            'graph_source' => 'repo',
            'summary' => $summary,
            'tags' => ['venture-foundry', 'comprehension', 'generated', 'company-canonical'],
            'owner' => 'atlas-venture-foundry',
            'doc_schema' => 'atlas_canonical_module_doc.v1',
        ];

        $lines = ['---'];
        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                $lines[] = $key.':';
                foreach ($value as $item) {
                    $lines[] = '  - '.$this->yamlScalar((string) $item);
                }

                continue;
            }
            $lines[] = $key.': '.$this->yamlScalar((string) $value);
        }
        $lines[] = '---';

        return implode("\n", $lines);
    }

    private function yamlScalar(string $value): string
    {
        if ($value === '') {
            return "''";
        }
        // Quote anything with YAML-significant characters to keep the doc valid.
        if (preg_match('/[:#\[\]{}",\'\n]/', $value) === 1 || str_starts_with($value, ' ') || str_ends_with($value, ' ')) {
            return "'".str_replace("'", "''", $value)."'";
        }

        return $value;
    }

    private function escape(string $value): string
    {
        return trim(str_replace(["\n", "\r"], ' ', $value));
    }

    private function escapeInline(string $value): string
    {
        return trim(str_replace(['`', "\n", "\r"], ["'", ' ', ' '], $value));
    }
}
