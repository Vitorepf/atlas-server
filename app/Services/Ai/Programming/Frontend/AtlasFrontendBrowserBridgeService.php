<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class AtlasFrontendBrowserBridgeService
{
    public const SCHEMA_VERSION = 'atlas.frontend.browser_bridge.v1';

    public const BROWSER_DETECTOR_EVENT_SCHEMA_VERSION = 'atlas.frontend.browser_detector_event.v1';

    private const START = '<!-- atlas-frontend-browser-bridge:start -->';

    private const END = '<!-- atlas-frontend-browser-bridge:end -->';

    /**
     * @return array<string,mixed>
     */
    public function script(): array
    {
        $source = <<<'JS'
        (() => {
          if (window.__ATLAS_FRONTEND_BRIDGE_ACTIVE__) return;
          window.__ATLAS_FRONTEND_BRIDGE_ACTIVE__ = true;
          window.__ATLAS_FRONTEND_PICK_EVENTS__ = window.__ATLAS_FRONTEND_PICK_EVENTS__ || [];
          window.__ATLAS_FRONTEND_BROWSER_DETECTOR_EVENTS__ = window.__ATLAS_FRONTEND_BROWSER_DETECTOR_EVENTS__ || [];
          const style = document.createElement('style');
          style.dataset.atlasFrontendBridge = 'style';
          style.textContent = '[data-atlas-frontend-hover="true"]{outline:2px solid #2563eb!important;outline-offset:2px!important}';
          document.head.appendChild(style);
          let hover = null;
          const hash = (value) => String(value || '').split('').reduce((a, c) => ((a << 5) - a + c.charCodeAt(0)) | 0, 0).toString(16);
          const fingerprint = (el) => {
            const rect = el.getBoundingClientRect();
            const text = (el.innerText || el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 160);
            return {
              tag: el.tagName.toLowerCase(),
              id: el.id || null,
              classes: Array.from(el.classList || []).slice(0, 12),
              textHash: text ? String(text.length) + ':' + hash(text) : null,
              rect: { x: Math.round(rect.x), y: Math.round(rect.y), width: Math.round(rect.width), height: Math.round(rect.height) },
              path: window.atlasFrontendPath(el)
            };
          };
          const finding = (ruleId, severity, message, el, extra) => ({
            rule_id: ruleId,
            severity,
            message,
            target_path: window.atlasFrontendPath(el),
            target_hash: hash(window.atlasFrontendPath(el)),
            competitive_rubric_dimension: extra && extra.dimension ? extra.dimension : 'visual_hierarchy_and_information_architecture',
            rerun_gates: ['browser_detector_overlay', 'anti_ai_slop_detector', 'visual_quality_gate'],
            evidence_required: ['browser_detector_event', 'anti_slop_report', 'visual_quality_report']
          });
          const inspectElement = (el, fp) => {
            const computed = window.getComputedStyle(el);
            const rect = el.getBoundingClientRect();
            const text = (el.innerText || el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 160);
            const findings = [];
            const role = (el.getAttribute('role') || '').toLowerCase();
            const label = (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.getAttribute('title') || '').trim();
            if ((el.tagName.toLowerCase() === 'button' || role === 'button') && !label && el.querySelector('svg') && text.length < 2) {
              findings.push(finding('browser_icon_button_without_accessible_name', 'high', 'Selected icon button needs an accessible name.', el, { dimension: 'accessibility_and_semantics' }));
            }
            if (Number.parseFloat(computed.fontSize || '16') < 12) {
              findings.push(finding('browser_tiny_text', 'medium', 'Selected element has tiny rendered text.', el, { dimension: 'accessibility_and_semantics' }));
            }
            if ((el.tagName.toLowerCase() === 'button' || el.tagName.toLowerCase() === 'a' || role === 'button') && (rect.width < 32 || rect.height < 32)) {
              findings.push(finding('browser_small_interactive_target', 'high', 'Selected interactive target is smaller than the minimum touch/click target.', el, { dimension: 'interaction_states_and_workflow_ergonomics' }));
            }
            if ((computed.position === 'absolute' || computed.position === 'fixed') && (rect.width > 0 || rect.height > 0)) {
              findings.push(finding('browser_absolute_overlap_risk', 'medium', 'Selected element uses absolute/fixed positioning and needs overlap proof.', el, { dimension: 'composition_layout_and_spacing' }));
            }
            if (/\b(unlock|seamless|beautiful|powerful|revolutionary|next-gen|supercharge)\b/i.test(text)) {
              findings.push(finding('browser_generic_copy', 'medium', 'Selected text uses generic product copy.', el, { dimension: 'product_intent_fit' }));
            }
            return {
              schema_version: 'atlas.frontend.browser_detector_event.v1',
              status: findings.length ? 'findings_present' : 'clean',
              captured_at: new Date().toISOString(),
              target_hash: hash(JSON.stringify(fp)),
              finding_count: findings.length,
              findings,
              source_policy: {
                raw_dom_returned: false,
                raw_text_returned: false,
                text_is_hashed: true,
                cookies_storage_or_network_accessed: false
              },
              claim_policy: {
                browser_detector_event_is_not_final_design_proof: true,
                findings_require_repair_or_false_positive_reason: findings.length > 0,
                completion_requires_visual_quality_gate: true
              }
            };
          };
          const clear = () => {
            if (hover) hover.removeAttribute('data-atlas-frontend-hover');
            hover = null;
          };
          const onMove = (event) => {
            if (!event.altKey) return clear();
            const el = event.target;
            if (!(el instanceof Element) || el === document.documentElement || el === document.body) return;
            if (hover && hover !== el) hover.removeAttribute('data-atlas-frontend-hover');
            hover = el;
            hover.setAttribute('data-atlas-frontend-hover', 'true');
          };
          const onClick = (event) => {
            if (!event.altKey) return;
            event.preventDefault();
            event.stopPropagation();
            const el = event.target;
            if (!(el instanceof Element)) return;
            const picked = { schema_version: 'atlas.frontend.browser_pick_event.v1', captured_at: new Date().toISOString(), fingerprint: fingerprint(el) };
            const detectorEvent = inspectElement(el, picked.fingerprint);
            window.__ATLAS_FRONTEND_LAST_PICK__ = picked;
            window.__ATLAS_FRONTEND_LAST_BROWSER_DETECTOR_EVENT__ = detectorEvent;
            window.__ATLAS_FRONTEND_PICK_EVENTS__.push(picked);
            window.__ATLAS_FRONTEND_BROWSER_DETECTOR_EVENTS__.push(detectorEvent);
            window.dispatchEvent(new CustomEvent('atlas:frontend:pick', { detail: picked }));
            window.dispatchEvent(new CustomEvent('atlas:frontend:browser-detect', { detail: detectorEvent }));
          };
          window.atlasFrontendPath = window.atlasFrontendPath || function atlasFrontendPath(el) {
            const parts = [];
            let node = el;
            while (node && node.nodeType === 1 && parts.length < 8) {
              let part = node.tagName.toLowerCase();
              if (node.id) part += '#' + node.id;
              else if (node.classList && node.classList.length) part += '.' + Array.from(node.classList).slice(0, 3).join('.');
              const parent = node.parentElement;
              if (parent) {
                const siblings = Array.from(parent.children).filter((child) => child.tagName === node.tagName);
                if (siblings.length > 1) part += ':nth-of-type(' + (siblings.indexOf(node) + 1) + ')';
              }
              parts.unshift(part);
              node = parent;
            }
            return parts.join(' > ');
          };
          window.addEventListener('mousemove', onMove, true);
          window.addEventListener('click', onClick, true);
          window.__ATLAS_FRONTEND_BRIDGE__ = { version: 'v1', mode: 'alt_click_picker', dispose: () => { clear(); window.removeEventListener('mousemove', onMove, true); window.removeEventListener('click', onClick, true); style.remove(); window.__ATLAS_FRONTEND_BRIDGE_ACTIVE__ = false; } };
        })();
        JS;

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => 'alt_click_picker',
            'script_hash' => hash('sha256', $source),
            'event_schema' => 'atlas.frontend.browser_pick_event.v1',
            'detector_event_schema' => self::BROWSER_DETECTOR_EVENT_SCHEMA_VERSION,
            'output_events' => ['atlas:frontend:pick', 'atlas:frontend:browser-detect'],
            'detector_rules' => [
                'browser_icon_button_without_accessible_name',
                'browser_tiny_text',
                'browser_small_interactive_target',
                'browser_absolute_overlap_risk',
                'browser_generic_copy',
            ],
            'source_policy' => [
                'raw_dom_returned' => false,
                'text_is_hashed' => true,
                'absolute_paths_returned' => false,
                'cookies_storage_or_network_accessed' => false,
            ],
            'script' => $source,
        ];
        $payload['bridge_hash'] = MissionCanonicalHash::sha256($this->publicPayload($payload));

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
            throw new RuntimeException('bridge_injection_failed');
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
            throw new RuntimeException('bridge_removal_failed');
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
        $payload['bridge_hash'] = MissionCanonicalHash::sha256($payload);

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
            throw new RuntimeException('bridge_injection_requires_html_file');
        }

        return $real;
    }

    private function relativePath(string $workspace, string $file): string
    {
        $prefix = rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : basename($file);
    }
}
