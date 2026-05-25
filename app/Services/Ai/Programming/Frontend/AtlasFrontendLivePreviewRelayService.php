<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class AtlasFrontendLivePreviewRelayService
{
    public const SCHEMA_VERSION = 'atlas.frontend.live_preview_relay.v1';

    private const START = '<!-- atlas-frontend-live-preview-relay:start -->';

    private const END = '<!-- atlas-frontend-live-preview-relay:end -->';

    /**
     * @return array<string,mixed>
     */
    public function script(): array
    {
        $source = <<<'JS'
        (() => {
          if (window.__ATLAS_FRONTEND_LIVE_PREVIEW_RELAY__) return;
          const style = document.createElement('style');
          style.dataset.atlasFrontendLivePreview = 'true';
          document.head.appendChild(style);
          const hash = (value) => String(value || '').split('').reduce((a, c) => ((a << 5) - a + c.charCodeAt(0)) | 0, 0).toString(16);
          const eventPayload = (status, detail) => ({
            schema_version: 'atlas.frontend.live_preview_event.v1',
            status,
            variant_id: detail && detail.variant_id ? String(detail.variant_id).slice(0, 80) : null,
            content_hash: detail && detail.css ? hash(String(detail.css)) : null,
            content_length: detail && detail.css ? String(detail.css).length : 0,
            captured_at: new Date().toISOString()
          });
          const applyPreview = (event) => {
            const detail = event.detail || {};
            const css = typeof detail.css === 'string' ? detail.css : '';
            style.textContent = css;
            window.__ATLAS_FRONTEND_LIVE_PREVIEW_RELAY__.lastEvent = eventPayload('preview_applied', detail);
            window.dispatchEvent(new CustomEvent('atlas:frontend:preview-applied', { detail: window.__ATLAS_FRONTEND_LIVE_PREVIEW_RELAY__.lastEvent }));
          };
          const clearPreview = (event) => {
            const detail = event.detail || {};
            style.textContent = '';
            window.__ATLAS_FRONTEND_LIVE_PREVIEW_RELAY__.lastEvent = eventPayload('preview_cleared', detail);
            window.dispatchEvent(new CustomEvent('atlas:frontend:preview-cleared', { detail: window.__ATLAS_FRONTEND_LIVE_PREVIEW_RELAY__.lastEvent }));
          };
          const dispose = () => {
            window.removeEventListener('atlas:frontend:preview-css', applyPreview, true);
            window.removeEventListener('atlas:frontend:clear-preview', clearPreview, true);
            style.remove();
            delete window.__ATLAS_FRONTEND_LIVE_PREVIEW_RELAY__;
          };
          window.__ATLAS_FRONTEND_LIVE_PREVIEW_RELAY__ = { version: 'v1', mode: 'event_driven_css_preview', lastEvent: null, dispose };
          window.addEventListener('atlas:frontend:preview-css', applyPreview, true);
          window.addEventListener('atlas:frontend:clear-preview', clearPreview, true);
        })();
        JS;

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => 'event_driven_css_preview',
            'script_hash' => hash('sha256', $source),
            'input_events' => ['atlas:frontend:preview-css', 'atlas:frontend:clear-preview'],
            'output_events' => ['atlas:frontend:preview-applied', 'atlas:frontend:preview-cleared'],
            'event_schema' => 'atlas.frontend.live_preview_event.v1',
            'source_policy' => [
                'raw_dom_returned' => false,
                'preview_content_persisted' => false,
                'cookies_storage_or_network_accessed' => false,
                'absolute_paths_returned' => false,
            ],
            'script' => $source,
        ];
        $payload['relay_hash'] = MissionCanonicalHash::sha256($this->publicPayload($payload));

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function inject(string $workspace, string $file): array
    {
        $workspace = $this->workspace($workspace);
        $filePath = $this->filePath($workspace, $file);
        $source = File::get($filePath);
        if (str_contains($source, self::START)) {
            return $this->result('already_injected', $workspace, $filePath, $source, $source);
        }

        $script = self::START."\n<script>\n".$this->script()['script']."\n</script>\n".self::END;
        $patched = str_contains(strtolower($source), '</body>')
            ? preg_replace('/<\/body>/i', $script."\n</body>", $source, 1)
            : $source."\n".$script."\n";
        if (! is_string($patched)) {
            throw new RuntimeException('relay_injection_failed');
        }
        File::put($filePath, $patched);

        return $this->result('injected', $workspace, $filePath, $source, $patched);
    }

    /**
     * @return array<string,mixed>
     */
    public function remove(string $workspace, string $file): array
    {
        $workspace = $this->workspace($workspace);
        $filePath = $this->filePath($workspace, $file);
        $source = File::get($filePath);
        $patched = preg_replace('/\n?'.preg_quote(self::START, '/').'.*?'.preg_quote(self::END, '/').'\n?/s', "\n", $source, 1);
        if (! is_string($patched)) {
            throw new RuntimeException('relay_removal_failed');
        }
        File::put($filePath, $patched);

        return $this->result($source === $patched ? 'not_present' : 'removed', $workspace, $filePath, $source, $patched);
    }

    /**
     * @return array<string,mixed>
     */
    private function result(string $status, string $workspace, string $filePath, string $before, string $after): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'file' => $this->relativePath($workspace, $filePath),
            'before_hash' => hash('sha256', $before),
            'after_hash' => hash('sha256', $after),
            'script_hash' => $this->script()['script_hash'],
            'source_policy' => [
                'raw_source_returned' => false,
                'absolute_path_returned' => false,
            ],
        ];
        $payload['relay_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function publicPayload(array $payload): array
    {
        unset($payload['script']);

        return $payload;
    }

    private function workspace(string $workspace): string
    {
        $workspace = trim($workspace) !== '' ? $workspace : base_path();
        $real = realpath($workspace);
        if ($real === false || ! File::isDirectory($real)) {
            throw new RuntimeException('workspace_not_found');
        }

        return $real;
    }

    private function filePath(string $workspace, string $file): string
    {
        $candidate = str_starts_with($file, DIRECTORY_SEPARATOR) ? $file : $workspace.'/'.ltrim($file, DIRECTORY_SEPARATOR);
        $real = realpath($candidate);
        if ($real === false || ! File::isFile($real)) {
            throw new RuntimeException('file_not_found');
        }
        $workspacePrefix = rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with($real, $workspacePrefix)) {
            throw new RuntimeException('file_outside_workspace');
        }
        if (! in_array(strtolower(pathinfo($real, PATHINFO_EXTENSION)), ['html', 'htm'], true)) {
            throw new RuntimeException('relay_injection_requires_html_file');
        }

        return $real;
    }

    private function relativePath(string $workspace, string $file): string
    {
        $prefix = rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : basename($file);
    }
}
