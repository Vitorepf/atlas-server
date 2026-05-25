<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use SplFileInfo;

final class AtlasFrontendAntiSlopDetectorService
{
    public const SCHEMA_VERSION = 'atlas.frontend.anti_slop_detector.v1';

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

        $checks = [
            ['gradient_text', 'medium', '/(?:bg|background)[^;\n]*(?:gradient|linear-gradient)[^;\n]*(?:text-transparent|background-clip:\s*text|-webkit-background-clip:\s*text)/i', 'Gradient text is a common AI-slop marker unless justified by brand system.'],
            ['one_note_purple_palette', 'medium', '/(?:from|to|via)-(?:purple|violet|indigo|fuchsia|pink)-|#(?:7c3aed|8b5cf6|a855f7|6366f1|ec4899)/i', 'Palette appears dominated by generic purple/pink AI styling.'],
            ['over_rounded_pills', 'medium', '/(?:rounded-full|border-radius:\s*(?:9999px|999px|50%))/i', 'Overuse of pill shapes often weakens enterprise UI hierarchy.'],
            ['nested_card_surface', 'high', '/(?:card|rounded|shadow)[^<\n]{0,120}(?:card|rounded|shadow)[^<\n]{0,120}(?:card|rounded|shadow)/i', 'Nested card/rounded/shadow surfaces create decorative clutter and weak information architecture.'],
            ['dark_glow_blob', 'medium', '/(?:blur-3xl|blur-\[|filter:\s*blur|box-shadow:[^;\n]*(?:purple|violet|rgba\([^)]*,\s*0\.[4-9]))/i', 'Glow/blob styling is a frequent substitute for real layout or product signal.'],
            ['placeholder_asset', 'high', '/(?:placeholder|lorem ipsum|unsplash\.it|placehold\.co|picsum\.photos|dummyimage)/i', 'Placeholder assets cannot support final multi-company frontend claims.'],
            ['generic_hero_copy', 'medium', '/\b(?:unlock|seamless|beautiful|powerful|revolutionary|next-gen|supercharge)\b/i', 'Generic hero copy signals weak product understanding.'],
            ['tiny_text', 'medium', '/(?:text-\[?1[0-1]px\]?|font-size:\s*(?:10|11)px)/i', 'Tiny text is often inaccessible or visually brittle.'],
            ['wide_tracking_body', 'medium', '/(?:tracking-widest|letter-spacing:\s*(?:0\.[1-9]|[1-9])em)/i', 'Wide tracking outside labels/eyebrows hurts readability.'],
            ['absolute_overlap_risk', 'high', '/(?:position:\s*absolute|class=["\'][^"\']*\babsolute\b)[^;\n]{0,180}(?:top-|left-|right-|bottom-|transform:|translate)/i', 'Absolute positioning with offsets needs visual proof to avoid overlap.'],
        ];

        foreach ($checks as [$ruleId, $severity, $pattern, $message]) {
            foreach ($lines as $index => $line) {
                if (preg_match($pattern, $line) !== 1) {
                    continue;
                }
                $findings[] = $this->finding($ruleId, $severity, $message, $path, $index + 1, $line);
            }
        }

        $centerCount = preg_match_all('/\b(?:text-center|items-center|justify-center|place-items-center|align-items:\s*center|justify-content:\s*center)\b/i', $source);
        if ($centerCount >= 6) {
            $findings[] = $this->finding('everything_centered', 'medium', 'Excessive centering suggests weak layout hierarchy.', $path, null, 'center_count='.$centerCount);
        }

        $buttonLikeCount = preg_match_all('/<(?:button|a)\b|role=["\']button["\']/i', $source);
        $ariaCount = preg_match_all('/\b(?:aria-label|aria-labelledby|title)=["\']/i', $source);
        if ($buttonLikeCount >= 3 && $ariaCount === 0 && str_contains($normalized, '<svg')) {
            $findings[] = $this->finding('icon_buttons_without_accessible_name', 'high', 'Icon-heavy controls need accessible names.', $path, null, 'button_like_count='.$buttonLikeCount);
        }

        return $findings;
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
            'status' => $status,
            'strict' => $strict,
            'target_hash' => hash('sha256', $path),
            'file_count' => count($files),
            'finding_count' => count($findings),
            'severity_counts' => [
                'high' => $high,
                'medium' => $medium,
            ],
            'findings' => $findings,
            'source_policy' => [
                'raw_source_returned' => false,
                'absolute_path_returned' => false,
                'line_excerpt_max_chars' => 160,
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
    private function finding(string $ruleId, string $severity, string $message, string $path, ?int $line, string $excerpt): array
    {
        $excerpt = trim(preg_replace('/\s+/', ' ', $excerpt) ?: '');

        return [
            'rule_id' => $ruleId,
            'severity' => $severity,
            'message' => $message,
            'path' => $path,
            'line' => $line,
            'excerpt' => Str::limit($excerpt, 160, '...'),
            'finding_hash' => hash('sha256', implode('|', [$ruleId, $severity, $path, (string) $line, $excerpt])),
        ];
    }
}
