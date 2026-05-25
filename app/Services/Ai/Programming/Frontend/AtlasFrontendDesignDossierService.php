<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasFrontendDesignDossierService
{
    public const SCHEMA_VERSION = 'atlas.frontend.design_dossier.v1';

    public const TEMPLATE_SCHEMA_VERSION = 'atlas.frontend.design_dossier_template.v1';

    /**
     * @return array<string,mixed>
     */
    public function inspect(string $workspace): array
    {
        $workspace = rtrim(trim($workspace), DIRECTORY_SEPARATOR);
        if ($workspace === '' || ! File::isDirectory($workspace)) {
            return $this->result('blocked', $workspace, [], ['workspace_missing'], []);
        }

        $documents = [];
        $blockers = [];
        $warnings = [];
        foreach ($this->requiredDocuments() as $id => $definition) {
            $path = $workspace.'/'.$definition['path'];
            $exists = File::isFile($path);
            $contents = $exists ? File::get($path) : '';
            $docBlockers = [];
            $docWarnings = [];

            if (! $exists) {
                $docBlockers[] = 'missing_design_doc_'.$id;
            } elseif (mb_strlen(trim($contents)) < 180) {
                $docWarnings[] = 'thin_design_doc_'.$id;
            } elseif (str_contains($contents, '- TBD')) {
                $docWarnings[] = 'template_placeholder_still_present_'.$id;
            }
            if ($exists && $this->hasForbiddenRawFields($contents)) {
                $docBlockers[] = 'forbidden_raw_prompt_or_secret_in_'.$id;
            }

            $documents[] = [
                'id' => $id,
                'path_hash' => hash('sha256', $definition['path']),
                'status' => $docBlockers === [] ? ($docWarnings === [] ? 'ready' : 'partial') : 'missing',
                'sha256' => $exists ? hash_file('sha256', $path) : null,
                'required_sections' => $definition['sections'],
                'blockers' => $docBlockers,
                'warnings' => $docWarnings,
            ];
            array_push($blockers, ...$docBlockers);
            array_push($warnings, ...$docWarnings);
        }

        $status = $blockers === [] ? ($warnings === [] ? 'ready' : 'partial') : 'partial';

        return $this->result($status, $workspace, $documents, array_values(array_unique($blockers)), array_values(array_unique($warnings)));
    }

    /**
     * @return array<string,mixed>
     */
    public function writeTemplate(string $workspace): array
    {
        $workspace = rtrim(trim($workspace), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($workspace.'/docs/design');

        $written = [];
        foreach ($this->requiredDocuments() as $id => $definition) {
            $path = $workspace.'/'.$definition['path'];
            if (! File::isFile($path)) {
                File::put($path, $this->templateMarkdown((string) $definition['title'], (array) $definition['sections']));
                $written[] = $id;
            }
        }

        $payload = [
            'schema_version' => self::TEMPLATE_SCHEMA_VERSION,
            'status' => 'ready',
            'template_type' => 'company_frontend_design_dossier',
            'workspace_hash' => hash('sha256', $workspace),
            'written_document_ids' => $written,
            'required_document_ids' => array_keys($this->requiredDocuments()),
            'claim_policy' => [
                'template_is_not_design_context_until_filled' => true,
                'existing_documents_are_not_overwritten' => true,
                'raw_customer_source_returned' => false,
            ],
        ];
        $payload['template_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,array{path:string,title:string,sections:array<int,string>}>
     */
    public function requiredDocuments(): array
    {
        return [
            'product_experience_brief' => [
                'path' => 'docs/design/product-experience-brief.md',
                'title' => 'Product Experience Brief',
                'sections' => ['product', 'audience', 'jobs_to_be_done', 'primary_journeys', 'success_metrics'],
            ],
            'brand_system' => [
                'path' => 'docs/design/brand-system.md',
                'title' => 'Brand System',
                'sections' => ['positioning', 'voice', 'visual_principles', 'palette', 'typography', 'asset_policy'],
            ],
            'ux_journeys' => [
                'path' => 'docs/design/ux-journeys.md',
                'title' => 'UX Journeys',
                'sections' => ['happy_paths', 'empty_states', 'error_states', 'loading_states', 'mobile_paths'],
            ],
            'design_system' => [
                'path' => 'docs/design/design-system.md',
                'title' => 'Design System',
                'sections' => ['tokens', 'components', 'layout_grid', 'interaction_patterns', 'reuse_policy'],
            ],
            'frontend_quality_policy' => [
                'path' => 'docs/design/frontend-quality-policy.md',
                'title' => 'Frontend Quality Policy',
                'sections' => ['viewports', 'accessibility', 'performance_budget', 'visual_regression', 'release_evidence'],
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $documents
     * @param  array<int,string>  $blockers
     * @param  array<int,string>  $warnings
     * @return array<string,mixed>
     */
    private function result(string $status, string $workspace, array $documents, array $blockers, array $warnings): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'dossier_type' => 'company_owned_local_repo_frontend_design',
            'source' => self::class,
            'workspace_hash' => $workspace !== '' ? hash('sha256', $workspace) : null,
            'required_document_ids' => array_keys($this->requiredDocuments()),
            'documents' => $documents,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'readiness' => [
                'can_drive_company_frontend' => $status === 'ready',
                'can_drive_ultra_premium_redesign' => $status === 'ready',
                'can_start_new_saas_design_system' => $status === 'ready',
                'missing_docs_should_be_created_before_visual_claim' => $blockers !== [],
            ],
            'claim_policy' => [
                'premium_design_claim_requires_ready_dossier' => true,
                'local_company_repo_is_default_operating_mode' => true,
                'template_is_not_design_context' => true,
                'raw_customer_source_returned' => false,
            ],
        ];
        $payload['dossier_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<int,string>  $sections
     */
    private function templateMarkdown(string $title, array $sections): string
    {
        $body = ['# '.$title, '', 'Status: draft', '', 'Use this document as canonical frontend/design context for Atlas. Do not store secrets, provider prompts, cookies, tokens, or private raw customer data here.', ''];
        foreach ($sections as $section) {
            $body[] = '## '.Str::headline($section);
            $body[] = '';
            $body[] = '- TBD';
            $body[] = '';
        }

        return implode(PHP_EOL, $body);
    }

    private function hasForbiddenRawFields(string $contents): bool
    {
        $needle = Str::ascii(strtolower($contents));

        return str_contains($needle, 'raw_prompt:')
            || str_contains($needle, 'provider_prompt:')
            || str_contains($needle, 'customer_source:')
            || str_contains($needle, 'cookie=')
            || str_contains($needle, 'token=')
            || str_contains($needle, 'secret=');
    }
}
