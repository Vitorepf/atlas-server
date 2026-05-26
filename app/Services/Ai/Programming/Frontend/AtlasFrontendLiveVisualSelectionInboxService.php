<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class AtlasFrontendLiveVisualSelectionInboxService
{
    public const SCHEMA_VERSION = 'atlas.frontend.live_visual_selection_inbox.v1';

    public const SELECTION_SCHEMA_VERSION = 'atlas.frontend.live_visual_selection.v1';

    /**
     * @param  array<string,mixed>  $selection
     * @return array<string,mixed>
     */
    public function record(string $workspace, string $session, array $selection): array
    {
        $workspace = $this->workspace($workspace);
        $session = $this->session($session);
        $selection = $this->selection($selection);

        $record = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'recorded',
            'session' => $session,
            'workspace_hash' => hash('sha256', $workspace),
            'selection' => $selection,
            'source_policy' => $this->sourcePolicy(),
        ];
        $record['inbox_hash'] = MissionCanonicalHash::sha256($record);

        File::ensureDirectoryExists(dirname($this->path($workspace, $session)));
        File::put($this->path($workspace, $session), json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $record;
    }

    /**
     * @return array<string,mixed>
     */
    public function latest(string $workspace, string $session): array
    {
        $workspace = $this->workspace($workspace);
        $session = $this->session($session);
        $path = $this->path($workspace, $session);

        if (! File::isFile($path)) {
            $record = [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'not_found',
                'session' => $session,
                'workspace_hash' => hash('sha256', $workspace),
                'selection' => null,
                'source_policy' => $this->sourcePolicy(),
            ];
            $record['inbox_hash'] = MissionCanonicalHash::sha256($record);

            return $record;
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('live_visual_selection_inbox_corrupt');
        }

        $selection = is_array($decoded['selection'] ?? null) ? $this->selection($decoded['selection']) : null;
        $record = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $selection === null ? 'not_found' : 'found',
            'session' => $session,
            'workspace_hash' => hash('sha256', $workspace),
            'selection' => $selection,
            'source_policy' => $this->sourcePolicy(),
        ];
        $record['inbox_hash'] = MissionCanonicalHash::sha256($record);

        return $record;
    }

    private function workspace(string $workspace): string
    {
        $real = realpath(trim($workspace));
        if ($real === false || ! File::isDirectory($real)) {
            throw new RuntimeException('workspace_not_found');
        }

        return $real;
    }

    private function session(string $session): string
    {
        $session = trim($session) !== '' ? trim($session) : 'atlas-live-session';
        $session = preg_replace('/[^A-Za-z0-9._-]+/', '-', $session) ?: 'atlas-live-session';

        return Str::limit(trim($session, '.-_'), 120, '') ?: 'atlas-live-session';
    }

    /**
     * @param  array<string,mixed>  $selection
     * @return array<string,mixed>
     */
    private function selection(array $selection): array
    {
        $detail = is_array($selection['detail'] ?? null) ? $selection['detail'] : $selection;
        $route = trim((string) ($detail['route'] ?? ''));
        $selector = trim((string) ($detail['selector'] ?? ''));
        $textExcerpt = trim((string) ($detail['text_excerpt'] ?? ''));
        $componentHint = trim((string) ($detail['component_hint'] ?? ''));
        $screenshotHash = $this->hashOrNull($detail['screenshot_hash'] ?? null, 'visual_selection_screenshot_hash_invalid');

        $payload = [
            'schema_version' => self::SELECTION_SCHEMA_VERSION,
            'status' => 'provided',
            'route_hash' => $this->hashOrNull($detail['route_hash'] ?? null) ?? ($route !== '' ? hash('sha256', $route) : null),
            'selector_hash' => $this->hashOrNull($detail['selector_hash'] ?? null) ?? ($selector !== '' ? hash('sha256', $selector) : null),
            'text_excerpt_hash' => $this->hashOrNull($detail['text_excerpt_hash'] ?? null) ?? ($textExcerpt !== '' ? hash('sha256', Str::limit($textExcerpt, 500, '')) : null),
            'component_hint_hash' => $this->hashOrNull($detail['component_hint_hash'] ?? null) ?? ($componentHint !== '' ? hash('sha256', Str::limit($componentHint, 240, '')) : null),
            'bounding_box' => $this->box(is_array($detail['bounding_box'] ?? null) ? $detail['bounding_box'] : []),
            'viewport' => $this->viewport(is_array($detail['viewport'] ?? null) ? $detail['viewport'] : []),
            'screenshot_hash' => $screenshotHash,
            'confidence' => max(0.0, min(1.0, (float) ($detail['confidence'] ?? 0.0))),
            'policy' => [
                'raw_selector_returned' => false,
                'raw_text_returned' => false,
                'raw_screenshot_returned' => false,
                'absolute_path_returned' => false,
                'selection_can_guide_patch_but_not_replace_visual_gate' => true,
            ],
        ];
        $payload['selection_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    private function hashOrNull(mixed $value, string $error = 'visual_selection_hash_invalid'): ?string
    {
        $hash = strtolower(trim((string) $value));
        if ($hash === '') {
            return null;
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
            throw new RuntimeException($error);
        }

        return $hash;
    }

    /**
     * @param  array<string,mixed>  $box
     * @return array{x:int|float,y:int|float,width:int|float,height:int|float}
     */
    private function box(array $box): array
    {
        return [
            'x' => $this->stableNumber(max(0.0, (float) ($box['x'] ?? 0.0))),
            'y' => $this->stableNumber(max(0.0, (float) ($box['y'] ?? 0.0))),
            'width' => $this->stableNumber(max(0.0, (float) ($box['width'] ?? 0.0))),
            'height' => $this->stableNumber(max(0.0, (float) ($box['height'] ?? 0.0))),
        ];
    }

    private function stableNumber(float $value): int|float
    {
        $rounded = round($value, 3);

        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }

    /**
     * @param  array<string,mixed>  $viewport
     * @return array{width:int,height:int}
     */
    private function viewport(array $viewport): array
    {
        return [
            'width' => max(0, (int) ($viewport['width'] ?? 0)),
            'height' => max(0, (int) ($viewport['height'] ?? 0)),
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function sourcePolicy(): array
    {
        return [
            'provider_safe_only' => true,
            'raw_selector_returned' => false,
            'raw_text_returned' => false,
            'raw_screenshot_returned' => false,
            'absolute_path_returned' => false,
            'selection_is_not_visual_quality_proof' => true,
        ];
    }

    private function path(string $workspace, string $session): string
    {
        return $workspace.'/.atlas/frontend/live-visual-selection-inbox/'.$session.'.json';
    }
}
