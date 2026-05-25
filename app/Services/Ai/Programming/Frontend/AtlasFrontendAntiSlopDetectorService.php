<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use SplFileInfo;

final class AtlasFrontendAntiSlopDetectorService
{
    public const SCHEMA_VERSION = 'atlas.frontend.anti_slop_detector.v1';

    public const FINDINGS_SCHEMA_VERSION = 'atlas.frontend.detector_findings.v1';

    public const RULE_REGISTRY_SCHEMA_VERSION = 'atlas.frontend.anti_slop_rule_registry.v1';

    public const REPAIR_PROJECTION_SCHEMA_VERSION = 'atlas.frontend.anti_slop_repair_projection.v1';

    /**
     * @return array<string,mixed>
     */
    public function inspectPath(string $path, bool $strict = false): array
    {
        $path = trim($path) !== '' ? $path : base_path();
        $path = File::isDirectory($path) || File::isFile($path) ? realpath($path) ?: $path : $path;
        $files = $this->frontendFiles($path);
        $findings = [];

        foreach ($files as $file) {
            $source = File::isFile($file) ? File::get($file) : '';
            $findings = array_merge($findings, $this->inspectSource($source, $this->safeRelativePath($file, $path)));
        }

        return $this->report($path, $files, $findings, $strict);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function inspectSource(string $source, string $path = 'inline'): array
    {
        $findings = [];
        $normalized = Str::ascii(strtolower($source));
        $lines = preg_split('/\R/u', $source) ?: [];

        foreach ($this->rules() as $rule) {
            foreach ($lines as $index => $line) {
                if (preg_match((string) $rule['pattern'], $line) !== 1) {
                    continue;
                }
                $findings[] = $this->finding($rule, $path, $index + 1, $line);
            }
        }

        $centerCount = preg_match_all('/\b(?:text-center|items-center|justify-center|place-items-center|align-items:\s*center|justify-content:\s*center)\b/i', $source);
        if ($centerCount >= 6) {
            $findings[] = $this->finding($this->ruleById('everything_centered'), $path, null, 'center_count='.$centerCount);
        }

        $buttonLikeCount = preg_match_all('/<(?:button|a)\b|role=["\']button["\']/i', $source);
        $ariaCount = preg_match_all('/\b(?:aria-label|aria-labelledby|title)=["\']/i', $source);
        if ($buttonLikeCount >= 3 && $ariaCount === 0 && str_contains($normalized, '<svg')) {
            $findings[] = $this->finding($this->ruleById('icon_buttons_without_accessible_name'), $path, null, 'button_like_count='.$buttonLikeCount);
        }

        return $findings;
    }

    /**
     * @return array<string,mixed>
     */
    public function ruleRegistry(): array
    {
        $payload = [
            'schema_version' => self::RULE_REGISTRY_SCHEMA_VERSION,
            'engine' => 'deterministic_static_source',
            'rule_count' => count($this->rules()),
            'rules' => array_map(fn (array $rule): array => $this->publicRule($rule), $this->rules()),
            'claim_policy' => [
                'rule_registry_is_not_visual_proof' => true,
                'findings_require_repair_or_false_positive_reason' => true,
                'browser_or_visual_scan_is_stronger_than_static_source_scan' => true,
            ],
        ];
        $payload['rule_registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<int,string>  $files
     * @param  array<int,array<string,mixed>>  $findings
     * @return array<string,mixed>
     */
    private function report(string $path, array $files, array $findings, bool $strict): array
    {
        $high = collect($findings)->where('severity', 'high')->count();
        $medium = collect($findings)->where('severity', 'medium')->count();
        $status = match (true) {
            $high > 0 && $strict => 'failed',
            $high > 0 || $medium > 0 => 'warning',
            default => 'passed',
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'findings_schema_version' => self::FINDINGS_SCHEMA_VERSION,
            'status' => $status,
            'strict' => $strict,
            'engine' => 'deterministic_static_source',
            'target_hash' => hash('sha256', $path),
            'file_count' => count($files),
            'finding_count' => count($findings),
            'severity_counts' => [
                'high' => $high,
                'medium' => $medium,
            ],
            'findings' => $findings,
            'rule_registry' => $this->ruleRegistry(),
            'repair_projection' => $this->repairProjection($status, $findings),
            'source_policy' => [
                'raw_source_returned' => false,
                'absolute_path_returned' => false,
                'line_excerpt_max_chars' => 160,
            ],
            'claim_policy' => [
                'anti_slop_report_is_not_final_design_proof' => true,
                'findings_require_repair_or_false_positive_reason' => $findings !== [],
                'visual_completion_claim_allowed' => $findings === [],
                'browser_or_visual_scan_required_for_layout_overlap_claim' => true,
                'raw_customer_source_returned' => false,
            ],
        ];
        $payload['detector_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    private function frontendFiles(string $path): array
    {
        if (File::isFile($path)) {
            return $this->isFrontendFile($path) ? [$path] : [];
        }

        if (! File::isDirectory($path)) {
            return [];
        }

        $files = [];
        foreach (File::allFiles($path) as $file) {
            /** @var SplFileInfo $file */
            $pathname = $file->getPathname();
            if ($this->shouldSkip($pathname) || ! $this->isFrontendFile($pathname)) {
                continue;
            }
            $files[] = $pathname;
            if (count($files) >= 400) {
                break;
            }
        }

        sort($files);

        return $files;
    }

    private function isFrontendFile(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), [
            'html', 'css', 'js', 'jsx', 'ts', 'tsx', 'vue', 'svelte', 'astro', 'blade.php',
        ], true) || str_ends_with($path, '.blade.php');
    }

    private function shouldSkip(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return str_contains($normalized, '/vendor/')
            || str_contains($normalized, '/node_modules/')
            || str_contains($normalized, '/storage/')
            || str_contains($normalized, '/bootstrap/cache/')
            || str_contains($normalized, '/.git/');
    }

    private function safeRelativePath(string $file, string $root): string
    {
        if (File::isFile($root)) {
            return basename($file);
        }

        $root = rtrim(str_replace('\\', '/', $root), '/').'/';
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file);
    }

    /**
     * @return array<string,mixed>
     */
    private function finding(array $rule, string $path, ?int $line, string $excerpt): array
    {
        $excerpt = trim(preg_replace('/\s+/', ' ', $excerpt) ?: '');
        $ruleId = (string) $rule['id'];
        $severity = (string) $rule['severity'];

        return [
            'rule_id' => $ruleId,
            'severity' => $severity,
            'message' => (string) $rule['message'],
            'category' => (string) $rule['category'],
            'impact' => (string) $rule['impact'],
            'gate_signal' => (string) $rule['gate_signal'],
            'repair_target' => (string) $rule['repair_target'],
            'competitive_rubric_dimension' => (string) $rule['competitive_rubric_dimension'],
            'rerun_gates' => (array) $rule['rerun_gates'],
            'evidence_required' => (array) $rule['evidence_required'],
            'false_positive_policy' => (string) $rule['false_positive_policy'],
            'path' => $path,
            'line' => $line,
            'excerpt' => Str::limit($excerpt, 160, '...'),
            'finding_hash' => hash('sha256', implode('|', [$ruleId, $severity, $path, (string) $line, $excerpt])),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function repairProjection(string $status, array $findings): array
    {
        $gateSignals = collect($findings)->pluck('gate_signal')->filter()->unique()->values()->all();
        $ruleIds = collect($findings)->pluck('rule_id')->filter()->unique()->values()->all();
        $rerunGates = collect($findings)->flatMap(fn (array $finding): array => (array) ($finding['rerun_gates'] ?? []))->unique()->values()->all();
        $evidenceRequired = collect($findings)->flatMap(fn (array $finding): array => (array) ($finding['evidence_required'] ?? []))->unique()->values()->all();
        $blockers = collect($findings)
            ->filter(fn (array $finding): bool => ($finding['severity'] ?? null) === 'high')
            ->map(fn (array $finding): string => 'anti_slop_'.$finding['rule_id'])
            ->unique()
            ->values()
            ->all();
        $warnings = collect($findings)
            ->filter(fn (array $finding): bool => ($finding['severity'] ?? null) !== 'high')
            ->map(fn (array $finding): string => 'anti_slop_'.$finding['rule_id'])
            ->unique()
            ->values()
            ->all();

        $payload = [
            'schema_version' => self::REPAIR_PROJECTION_SCHEMA_VERSION,
            'status' => $findings === [] ? 'clean' : ($status === 'failed' ? 'blocked' : 'repair_required'),
            'failed_gates' => $gateSignals,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'rule_ids' => $ruleIds,
            'rerun_gates' => $rerunGates,
            'evidence_required' => $evidenceRequired,
            'repair_plan_input' => [
                'failed_gates' => $gateSignals,
                'blockers' => $blockers,
                'warnings' => $warnings,
            ],
            'recommended_repair_plan_command' => $findings === []
                ? null
                : 'php artisan atlas:frontend:repair-plan --failed-gate=anti_ai_slop_detector --json',
            'claim_policy' => [
                'repair_projection_is_not_completion_evidence' => true,
                'completion_requires_clean_detector_or_reasoned_false_positive_receipt' => true,
                'completion_requires_visual_quality_gate_after_repair' => true,
            ],
        ];
        $payload['repair_projection_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function rules(): array
    {
        return [
            $this->rule('gradient_text', 'medium', 'visual_slop', '/(?:bg|background)[^;\n]*(?:gradient|linear-gradient)[^;\n]*(?:text-transparent|background-clip:\s*text|-webkit-background-clip:\s*text)/i', 'Gradient text is a common AI-slop marker unless justified by brand system.', 'brand originality weakens when display text uses generic gradient treatment.', 'visual_craft', 'anti_slop_originality_and_brand_fit'),
            $this->rule('one_note_purple_palette', 'medium', 'visual_slop', '/(?:from|to|via)-(?:purple|violet|indigo|fuchsia|pink)-|#(?:7c3aed|8b5cf6|a855f7|6366f1|ec4899)/i', 'Palette appears dominated by generic purple/pink AI styling.', 'one-note palette makes unrelated products feel templated.', 'visual_craft', 'anti_slop_originality_and_brand_fit'),
            $this->rule('over_rounded_pills', 'medium', 'visual_slop', '/(?:rounded-full|border-radius:\s*(?:9999px|999px|50%))/i', 'Overuse of pill shapes often weakens enterprise UI hierarchy.', 'excessive pill styling reduces scan clarity and enterprise density.', 'component_shape', 'visual_hierarchy_and_information_architecture'),
            $this->rule('nested_card_surface', 'high', 'composition_quality', '/(?:card|rounded|shadow)[^<\n]{0,120}(?:card|rounded|shadow)[^<\n]{0,120}(?:card|rounded|shadow)/i', 'Nested card/rounded/shadow surfaces create decorative clutter and weak information architecture.', 'nested surfaces hide information hierarchy and create card-in-card clutter.', 'information_architecture', 'visual_hierarchy_and_information_architecture'),
            $this->rule('dark_glow_blob', 'medium', 'visual_slop', '/(?:blur-3xl|blur-\[|filter:\s*blur|box-shadow:[^;\n]*(?:purple|violet|rgba\([^)]*,\s*0\.[4-9]))/i', 'Glow/blob styling is a frequent substitute for real layout or product signal.', 'glow decoration can mask weak product signal.', 'visual_craft', 'anti_slop_originality_and_brand_fit'),
            $this->rule('placeholder_asset', 'high', 'asset_integrity', '/(?:placeholder|lorem ipsum|unsplash\.it|placehold\.co|picsum\.photos|dummyimage)/i', 'Placeholder assets cannot support final multi-company frontend claims.', 'placeholder content breaks final delivery evidence and product trust.', 'assets', 'evidence_completeness', ['anti_ai_slop_detector', 'asset_pack_verifier', 'visual_quality_gate'], ['anti_slop_report', 'frontend_asset_pack', 'visual_quality_report']),
            $this->rule('generic_hero_copy', 'medium', 'product_copy', '/\b(?:unlock|seamless|beautiful|powerful|revolutionary|next-gen|supercharge)\b/i', 'Generic hero copy signals weak product understanding.', 'generic claims do not prove product intent fit.', 'product_copy', 'product_intent_fit'),
            $this->rule('tiny_text', 'medium', 'accessibility_quality', '/(?:text-\[?1[0-1]px\]?|font-size:\s*(?:10|11)px)/i', 'Tiny text is often inaccessible or visually brittle.', 'small text risks readability failures across viewports.', 'typography', 'accessibility_and_semantics', ['anti_ai_slop_detector', 'a11y_check_or_reason', 'visual_quality_gate'], ['anti_slop_report', 'a11y_or_reason', 'screenshots_by_viewport']),
            $this->rule('wide_tracking_body', 'medium', 'typography_quality', '/(?:tracking-widest|letter-spacing:\s*(?:0\.[1-9]|[1-9])em)/i', 'Wide tracking outside labels/eyebrows hurts readability.', 'wide letter spacing in body/content weakens reading quality.', 'typography', 'accessibility_and_semantics'),
            $this->rule('absolute_overlap_risk', 'high', 'layout_quality', '/(?:position:\s*absolute|class=["\'][^"\']*\babsolute\b)[^;\n]{0,180}(?:top-|left-|right-|bottom-|transform:|translate)/i', 'Absolute positioning with offsets needs visual proof to avoid overlap.', 'absolute layout offsets can create viewport-specific overlap.', 'layout_stability', 'composition_layout_and_spacing', ['anti_ai_slop_detector', 'visual_quality_gate'], ['anti_slop_report', 'screenshots_by_viewport', 'visual_quality_report']),
            $this->rule('everything_centered', 'medium', 'composition_quality', '/$a/', 'Excessive centering suggests weak layout hierarchy.', 'over-centering flattens scan order and workflow hierarchy.', 'information_architecture', 'visual_hierarchy_and_information_architecture'),
            $this->rule('icon_buttons_without_accessible_name', 'high', 'accessibility_quality', '/$a/', 'Icon-heavy controls need accessible names.', 'icon-only controls without names block accessible operation.', 'accessibility', 'accessibility_and_semantics', ['anti_ai_slop_detector', 'a11y_check_or_reason', 'visual_quality_gate'], ['anti_slop_report', 'a11y_or_reason', 'visual_quality_report']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rule(string $id, string $severity, string $category, string $pattern, string $message, string $impact, string $repairTarget, string $dimension, array $rerunGates = ['anti_ai_slop_detector', 'design_5d_review', 'visual_quality_gate'], array $evidenceRequired = ['anti_slop_report', 'design_review_report', 'visual_quality_report']): array
    {
        return [
            'id' => $id,
            'severity' => $severity,
            'category' => $category,
            'pattern' => $pattern,
            'message' => $message,
            'impact' => $impact,
            'gate_signal' => 'anti_ai_slop_detector',
            'repair_target' => $repairTarget,
            'competitive_rubric_dimension' => $dimension,
            'rerun_gates' => $rerunGates,
            'evidence_required' => $evidenceRequired,
            'false_positive_policy' => 'Allowed only with a short brand/product rationale and follow-up visual quality evidence.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ruleById(string $id): array
    {
        return collect($this->rules())->firstWhere('id', $id) ?? $this->rule($id, 'medium', 'unknown', '/$a/', 'Unknown anti-slop rule.', 'unknown impact.', 'visual_craft', 'anti_slop_originality_and_brand_fit');
    }

    /**
     * @return array<string,mixed>
     */
    private function publicRule(array $rule): array
    {
        return collect($rule)
            ->except('pattern')
            ->all();
    }
}
