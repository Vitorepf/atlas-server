<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasFrontendCompanyDesignProfileService
{
    public const SCHEMA_VERSION = 'atlas.frontend.company_design_profile.v1';

    public const TEMPLATE_SCHEMA_VERSION = 'atlas.frontend.company_design_profile_template.v1';

    /**
     * @return array<string,mixed>
     */
    public function inspect(string $profilePath): array
    {
        $profilePath = trim($profilePath);
        if ($profilePath === '' || ! File::isFile($profilePath)) {
            return $this->payload('blocked', $profilePath, ['profile_missing'], [], null);
        }

        $raw = File::get($profilePath);
        $profile = json_decode($raw, true);
        if (! is_array($profile)) {
            return $this->payload('blocked', $profilePath, ['profile_json_invalid'], [], hash('sha256', $raw));
        }

        $blockers = [];
        $warnings = [];
        if (($profile['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $blockers[] = 'schema_version_invalid';
        }
        foreach ($this->requiredSections() as $section) {
            if (! is_array($profile[$section] ?? null) || $profile[$section] === []) {
                $blockers[] = 'missing_'.$section;
            }
        }
        if ($this->hasForbiddenRawFields($profile)) {
            $blockers[] = 'forbidden_raw_prompt_or_customer_source_field_present';
        }

        $sectionChecks = $this->sectionChecks($profile);
        foreach ($sectionChecks as $check) {
            foreach ((array) ($check['blockers'] ?? []) as $blocker) {
                $blockers[] = $blocker;
            }
            foreach ((array) ($check['warnings'] ?? []) as $warning) {
                $warnings[] = $warning;
            }
        }

        $status = $blockers === [] ? ($warnings === [] ? 'ready' : 'partial') : 'blocked';

        return $this->payload($status, $profilePath, array_values(array_unique($blockers)), array_values(array_unique($warnings)), hash('sha256', $raw), [
            'profile_id_hash' => isset($profile['profile_id']) ? hash('sha256', (string) $profile['profile_id']) : null,
            'section_checks' => $sectionChecks,
            'readiness' => [
                'can_drive_multi_company_frontend' => $blockers === [],
                'can_claim_brand_adaptation' => $blockers === [] && $warnings === [],
                'requires_human_brand_review' => in_array('brand_voice_or_visual_rules_thin', $warnings, true),
                'requires_design_system_discovery' => in_array('design_system_refs_thin', $warnings, true),
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function writeTemplate(string $outputDirectory): array
    {
        $outputDirectory = rtrim(trim($outputDirectory), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($outputDirectory);
        $path = $outputDirectory.'/company-design-profile.json';

        if (! File::isFile($path)) {
            File::put($path, json_encode([
                'schema_version' => self::SCHEMA_VERSION,
                'profile_id' => 'acme-web-app-v1',
                'company_context' => [
                    'company_name_hash' => '<sha256-64-hex>',
                    'industry' => 'saas',
                    'audience_segments' => ['operators', 'admins'],
                    'product_jobs' => ['monitor operations', 'complete repeated workflows'],
                ],
                'brand_system' => [
                    'tone_keywords' => ['clear', 'calm', 'precise'],
                    'visual_principles' => ['dense but readable', 'low decoration', 'high contrast'],
                    'palette_tokens' => ['primary', 'surface', 'accent', 'danger'],
                    'typography_tokens' => ['body', 'heading', 'mono'],
                    'spacing_radius_tokens' => ['space-2', 'space-4', 'radius-sm'],
                ],
                'design_system_refs' => [
                    ['kind' => 'tokens', 'ref' => 'design-tokens.json', 'sha256' => '<sha256-64-hex>'],
                    ['kind' => 'screenshots', 'ref' => 'current-ui-screenshots.zip', 'sha256' => '<sha256-64-hex>'],
                ],
                'frontend_constraints' => [
                    'framework' => 'react',
                    'supported_viewports' => ['desktop', 'tablet', 'mobile'],
                    'accessibility_baseline' => 'wcag_aa_or_reason',
                    'performance_budget' => 'project budget or reason',
                ],
                'quality_policy' => [
                    'forbidden_tropes' => ['generic gradient hero', 'nested cards', 'placeholder assets'],
                    'required_evidence' => ['visual_smoke_multi_viewport', 'anti_slop_report', 'state_transition_check'],
                    'review_policy' => 'senior_review_for_broad_visual_change',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        $payload = [
            'schema_version' => self::TEMPLATE_SCHEMA_VERSION,
            'status' => 'ready',
            'template_type' => 'frontend_company_design_profile',
            'profile_path_hash' => hash('sha256', $path),
            'required_sections' => $this->requiredSections(),
            'claim_policy' => [
                'template_is_not_company_context' => true,
                'profile_must_be_inspected_before_multi_company_claim' => true,
                'raw_customer_source_forbidden' => true,
            ],
        ];
        $payload['template_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    public function requiredSections(): array
    {
        return [
            'company_context',
            'brand_system',
            'design_system_refs',
            'frontend_constraints',
            'quality_policy',
        ];
    }

    /**
     * @param  array<string,mixed>  $profile
     * @return array<int,array<string,mixed>>
     */
    private function sectionChecks(array $profile): array
    {
        return [
            $this->checkListSection($profile, 'company_context', ['industry', 'audience_segments', 'product_jobs'], 'company_context_thin'),
            $this->checkListSection($profile, 'brand_system', ['tone_keywords', 'visual_principles', 'palette_tokens', 'typography_tokens'], 'brand_voice_or_visual_rules_thin'),
            $this->checkRefs($profile['design_system_refs'] ?? null),
            $this->checkListSection($profile, 'frontend_constraints', ['framework', 'supported_viewports', 'accessibility_baseline', 'performance_budget'], 'frontend_constraints_thin'),
            $this->checkListSection($profile, 'quality_policy', ['forbidden_tropes', 'required_evidence', 'review_policy'], 'quality_policy_thin'),
        ];
    }

    /**
     * @param  array<string,mixed>  $profile
     * @param  array<int,string>  $keys
     * @return array<string,mixed>
     */
    private function checkListSection(array $profile, string $section, array $keys, string $thinWarning): array
    {
        $blockers = [];
        $present = [];
        foreach ($keys as $key) {
            $value = data_get($profile, $section.'.'.$key);
            if ($value === null || $value === '' || $value === []) {
                $blockers[] = 'missing_'.$section.'_'.$key;
            } else {
                $present[] = $key;
            }
        }

        return [
            'section' => $section,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'present_keys' => $present,
            'blockers' => $blockers,
            'warnings' => count($present) < count($keys) ? [$thinWarning] : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkRefs(mixed $refs): array
    {
        if (! is_array($refs) || $refs === []) {
            return [
                'section' => 'design_system_refs',
                'status' => 'blocked',
                'present_keys' => [],
                'blockers' => ['missing_design_system_refs_entries'],
                'warnings' => ['design_system_refs_thin'],
            ];
        }

        $blockers = [];
        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                $blockers[] = 'design_system_ref_invalid';

                continue;
            }
            if (! is_string($ref['kind'] ?? null) || ! in_array($ref['kind'], ['tokens', 'screenshots', 'components', 'guidelines', 'assets'], true)) {
                $blockers[] = 'design_system_ref_kind_invalid';
            }
            if (! is_string($ref['ref'] ?? null) || (string) $ref['ref'] === '' || str_starts_with((string) $ref['ref'], '/') || str_contains((string) $ref['ref'], '..')) {
                $blockers[] = 'design_system_ref_path_invalid';
            }
            if (! is_string($ref['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', (string) $ref['sha256']) !== 1) {
                $blockers[] = 'design_system_ref_hash_invalid';
            }
        }

        return [
            'section' => 'design_system_refs',
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'present_keys' => ['entries'],
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => count($refs) < 2 ? ['design_system_refs_thin'] : [],
        ];
    }

    /**
     * @param  array<string,mixed>  $profile
     */
    private function hasForbiddenRawFields(array $profile): bool
    {
        $forbidden = ['raw_prompt', 'prompt', 'source', 'raw_source', 'customer_source', 'customer_data', 'secret', 'token'];

        return collect(array_keys($profile))
            ->contains(fn (string $key): bool => in_array(Str::snake($key), $forbidden, true));
    }

    /**
     * @param  array<int,string>  $blockers
     * @param  array<int,string>  $warnings
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function payload(string $status, string $profilePath, array $blockers, array $warnings, ?string $profileHash, array $extra = []): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'profile_type' => 'multi_company_frontend_design_context',
            'source' => self::class,
            'profile_path_hash' => $profilePath !== '' ? hash('sha256', $profilePath) : null,
            'profile_hash' => $profileHash,
            'required_sections' => $this->requiredSections(),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'provider_policy' => [
                'raw_customer_source_returned' => false,
                'provider_lock_in_required' => false,
                'brand_claim_requires_profile_ready' => true,
            ],
            ...array_filter($extra, fn (mixed $value): bool => $value !== null),
        ];
        $payload['profile_certification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }
}
