<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class AtlasFrontendLiveSourcePatchRuntimeService
{
    public const SESSION_SCHEMA_VERSION = 'atlas.frontend.live_source_patch_session.v1';

    public const RESULT_SCHEMA_VERSION = 'atlas.frontend.live_source_patch_result.v1';

    public const DECISION_RECEIPT_SCHEMA_VERSION = 'atlas.frontend.live_source_patch_decision_receipt.v1';

    /**
     * @param  array<int,array{id:string,content:string}>  $variants
     * @param  array<string,mixed>|null  $visualSelection
     * @return array<string,mixed>
     */
    public function prepare(string $workspace, string $file, string $target, array $variants, ?string $sessionId = null, ?array $visualSelection = null): array
    {
        $workspace = $this->workspace($workspace);
        $filePath = $this->filePath($workspace, $file);
        $source = File::get($filePath);
        $targetCount = substr_count($source, $target);
        if ($target === '' || $targetCount !== 1) {
            throw new RuntimeException('target_must_match_exactly_once');
        }

        $variants = $this->normalizeVariants($variants);
        if ($variants === []) {
            throw new RuntimeException('at_least_one_variant_required');
        }

        $session = [
            'schema_version' => self::SESSION_SCHEMA_VERSION,
            'session_id' => $sessionId ?: (string) Str::uuid(),
            'status' => 'prepared',
            'workspace_hash' => hash('sha256', $workspace),
            'file' => $this->relativePath($workspace, $filePath),
            'original_hash' => hash('sha256', $target),
            'original_file_hash' => hash('sha256', $source),
            'variant_count' => count($variants),
            'visual_selection' => $this->visualSelection($visualSelection),
            'variants' => array_map(fn (array $variant): array => [
                'id' => $variant['id'],
                'content_hash' => hash('sha256', $variant['content']),
                'line_count' => substr_count($variant['content'], "\n") + 1,
            ], $variants),
            'variant_payloads' => $variants,
            'source_policy' => [
                'raw_original_returned' => false,
                'raw_variants_returned' => false,
                'raw_visual_selection_text_returned' => false,
                'absolute_path_returned' => false,
                'private_session_integrity_verified_before_patch' => true,
                'visual_selection_is_sanitized' => true,
            ],
            'journal' => [
                $this->event('prepared', [
                    'variant_count' => count($variants),
                    'target_count' => $targetCount,
                    'visual_selection_present' => $visualSelection !== null,
                ]),
            ],
            'accepted_variant_id' => null,
            'original' => $target,
        ];
        $session['private_integrity_hash'] = $this->privateIntegrityHash($session);
        $session['session_hash'] = MissionCanonicalHash::sha256($this->publicSession($session));
        $this->writeSession($workspace, $session);

        return $this->result('prepared', $workspace, $session);
    }

    /**
     * @return array<string,mixed>
     */
    public function accept(string $workspace, string $sessionId, string $variantId): array
    {
        $workspace = $this->workspace($workspace);
        $session = $this->readSession($workspace, $sessionId);
        $this->assertStatus($session, ['prepared', 'recovered']);
        $variant = $this->variantPayload($session, $variantId);
        $filePath = $this->filePath($workspace, (string) $session['file']);
        $source = File::get($filePath);

        if (hash('sha256', $source) !== ($session['original_file_hash'] ?? null)) {
            throw new RuntimeException('source_changed_since_prepare');
        }

        $original = (string) ($session['original'] ?? '');
        if (substr_count($source, $original) !== 1) {
            throw new RuntimeException('original_target_not_found_once');
        }

        $patched = str_replace($original, (string) $variant['content'], $source);
        File::put($filePath, $patched);

        $session['status'] = 'accepted';
        $session['accepted_variant_id'] = $variantId;
        $session['accepted_file_hash'] = hash('sha256', $patched);
        $session['accepted_variant_hash'] = hash('sha256', (string) $variant['content']);
        $session['accepted_diff_hash'] = hash('sha256', $original."\n---atlas-variant---\n".(string) $variant['content']);
        $session['journal'][] = $this->event('accepted', ['variant_id' => $variantId]);
        $session['private_integrity_hash'] = $this->privateIntegrityHash($session);
        $session['session_hash'] = MissionCanonicalHash::sha256($this->publicSession($session));
        $this->writeSession($workspace, $session);

        return $this->result('accepted', $workspace, $session);
    }

    /**
     * @return array<string,mixed>
     */
    public function discard(string $workspace, string $sessionId): array
    {
        $workspace = $this->workspace($workspace);
        $session = $this->readSession($workspace, $sessionId);
        $this->assertStatus($session, ['prepared']);
        $session['status'] = 'discarded';
        $session['journal'][] = $this->event('discarded');
        $session['private_integrity_hash'] = $this->privateIntegrityHash($session);
        $session['session_hash'] = MissionCanonicalHash::sha256($this->publicSession($session));
        $this->writeSession($workspace, $session);

        return $this->result('discarded', $workspace, $session);
    }

    /**
     * @return array<string,mixed>
     */
    public function recover(string $workspace, string $sessionId): array
    {
        $workspace = $this->workspace($workspace);
        $session = $this->readSession($workspace, $sessionId);
        $this->assertStatus($session, ['accepted']);
        $variant = $this->variantPayload($session, (string) $session['accepted_variant_id']);
        $filePath = $this->filePath($workspace, (string) $session['file']);
        $source = File::get($filePath);
        $accepted = (string) $variant['content'];

        if (substr_count($source, $accepted) !== 1) {
            throw new RuntimeException('accepted_variant_not_found_once');
        }

        $recovered = str_replace($accepted, (string) $session['original'], $source);
        File::put($filePath, $recovered);

        $session['status'] = 'recovered';
        $session['recovered_file_hash'] = hash('sha256', $recovered);
        $session['journal'][] = $this->event('recovered', ['variant_id' => (string) $session['accepted_variant_id']]);
        $session['private_integrity_hash'] = $this->privateIntegrityHash($session);
        $session['session_hash'] = MissionCanonicalHash::sha256($this->publicSession($session));
        $this->writeSession($workspace, $session);

        return $this->result('recovered', $workspace, $session);
    }

    /**
     * @return array<string,mixed>
     */
    public function status(string $workspace, string $sessionId): array
    {
        $workspace = $this->workspace($workspace);

        return $this->result('status', $workspace, $this->readSession($workspace, $sessionId));
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

        return $real;
    }

    /**
     * @param  array<int,array{id:string,content:string}>  $variants
     * @return array<int,array{id:string,content:string}>
     */
    private function normalizeVariants(array $variants): array
    {
        $normalized = [];
        foreach ($variants as $index => $variant) {
            $id = trim((string) ($variant['id'] ?? 'variant-'.($index + 1)));
            $content = (string) ($variant['content'] ?? '');
            if ($id === '' || trim($content) === '') {
                continue;
            }
            $normalized[] = ['id' => $id, 'content' => $content];
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>|null  $selection
     * @return array<string,mixed>
     */
    private function visualSelection(?array $selection): array
    {
        if ($selection === null || $selection === []) {
            return [
                'schema_version' => 'atlas.frontend.live_visual_selection.v1',
                'status' => 'not_provided',
                'selection_hash' => null,
                'policy' => [
                    'raw_selector_returned' => false,
                    'raw_text_returned' => false,
                    'raw_screenshot_returned' => false,
                    'absolute_path_returned' => false,
                ],
            ];
        }

        $route = trim((string) ($selection['route'] ?? ''));
        $selector = trim((string) ($selection['selector'] ?? ''));
        $textExcerpt = trim((string) ($selection['text_excerpt'] ?? ''));
        $componentHint = trim((string) ($selection['component_hint'] ?? ''));
        $routeHash = $this->hashOrNull($selection['route_hash'] ?? null);
        $selectorHash = $this->hashOrNull($selection['selector_hash'] ?? null);
        $textExcerptHash = $this->hashOrNull($selection['text_excerpt_hash'] ?? null);
        $componentHintHash = $this->hashOrNull($selection['component_hint_hash'] ?? null);
        $screenshotHash = strtolower(trim((string) ($selection['screenshot_hash'] ?? '')));
        if ($screenshotHash !== '' && preg_match('/\A[a-f0-9]{64}\z/', $screenshotHash) !== 1) {
            throw new RuntimeException('visual_selection_screenshot_hash_invalid');
        }

        $payload = [
            'schema_version' => 'atlas.frontend.live_visual_selection.v1',
            'status' => 'provided',
            'route_hash' => $routeHash ?? ($route !== '' ? hash('sha256', $route) : null),
            'selector_hash' => $selectorHash ?? ($selector !== '' ? hash('sha256', $selector) : null),
            'text_excerpt_hash' => $textExcerptHash ?? ($textExcerpt !== '' ? hash('sha256', Str::limit($textExcerpt, 500, '')) : null),
            'component_hint_hash' => $componentHintHash ?? ($componentHint !== '' ? hash('sha256', Str::limit($componentHint, 240, '')) : null),
            'bounding_box' => $this->visualSelectionBox((array) ($selection['bounding_box'] ?? [])),
            'viewport' => $this->visualSelectionViewport((array) ($selection['viewport'] ?? [])),
            'screenshot_hash' => $screenshotHash !== '' ? $screenshotHash : null,
            'confidence' => max(0.0, min(1.0, (float) ($selection['confidence'] ?? 0.0))),
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

    private function hashOrNull(mixed $value): ?string
    {
        $hash = strtolower(trim((string) $value));
        if ($hash === '') {
            return null;
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
            throw new RuntimeException('visual_selection_hash_invalid');
        }

        return $hash;
    }

    /**
     * @param  array<string,mixed>  $box
     * @return array{x:int|float,y:int|float,width:int|float,height:int|float}
     */
    private function visualSelectionBox(array $box): array
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
    private function visualSelectionViewport(array $viewport): array
    {
        return [
            'width' => max(0, (int) ($viewport['width'] ?? 0)),
            'height' => max(0, (int) ($viewport['height'] ?? 0)),
        ];
    }

    /**
     * @param  array<string,mixed>  $session
     * @return array{id:string,content:string}
     */
    private function variantPayload(array $session, string $variantId): array
    {
        foreach ((array) ($session['variant_payloads'] ?? []) as $variant) {
            if (is_array($variant) && ($variant['id'] ?? null) === $variantId) {
                return ['id' => (string) $variant['id'], 'content' => (string) $variant['content']];
            }
        }

        throw new RuntimeException('variant_not_found');
    }

    /**
     * @param  array<string,mixed>  $session
     * @param  array<int,string>  $allowed
     */
    private function assertStatus(array $session, array $allowed): void
    {
        if (! in_array((string) ($session['status'] ?? ''), $allowed, true)) {
            throw new RuntimeException('invalid_session_status');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function result(string $operation, string $workspace, array $session): array
    {
        $payload = [
            'schema_version' => self::RESULT_SCHEMA_VERSION,
            'operation' => $operation,
            'status' => $session['status'] ?? 'unknown',
            'session' => $this->publicSession($session),
            'session_ref' => 'atlas_frontend_live:'.$session['session_id'],
            'journal_path_hash' => hash('sha256', $this->sessionPath($workspace, (string) $session['session_id'])),
            'decision_receipt' => $this->decisionReceipt($operation, $session),
            'integrity_policy' => [
                'private_session_integrity_hash_required' => true,
                'raw_original_or_variants_returned' => false,
            ],
            'claim_policy' => [
                'live_patch_decision_is_not_delivery_evidence' => true,
                'accepted_live_patch_requires_visual_quality_gate' => true,
                'accepted_live_patch_requires_run_certification_before_completion_claim' => true,
                'discard_or_recover_preserves_reversibility_evidence' => true,
            ],
        ];
        $payload['result_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>
     */
    private function decisionReceipt(string $operation, array $session): array
    {
        $receipt = [
            'schema_version' => self::DECISION_RECEIPT_SCHEMA_VERSION,
            'operation' => $operation,
            'session_id_hash' => hash('sha256', (string) ($session['session_id'] ?? '')),
            'session_hash' => $session['session_hash'] ?? null,
            'status' => $session['status'] ?? 'unknown',
            'file_hash' => isset($session['file']) ? hash('sha256', (string) $session['file']) : null,
            'original_hash' => $session['original_hash'] ?? null,
            'original_file_hash' => $session['original_file_hash'] ?? null,
            'accepted_variant_id_hash' => isset($session['accepted_variant_id']) && $session['accepted_variant_id'] !== null
                ? hash('sha256', (string) $session['accepted_variant_id'])
                : null,
            'accepted_variant_hash' => $session['accepted_variant_hash'] ?? null,
            'accepted_diff_hash' => $session['accepted_diff_hash'] ?? null,
            'accepted_file_hash' => $session['accepted_file_hash'] ?? null,
            'recovered_file_hash' => $session['recovered_file_hash'] ?? null,
            'visual_selection_hash' => data_get($session, 'visual_selection.selection_hash'),
            'visual_selection_status' => data_get($session, 'visual_selection.status', 'not_provided'),
            'journal_event_count' => count((array) ($session['journal'] ?? [])),
            'required_next_gates' => $this->requiredNextGates((string) ($session['status'] ?? 'unknown')),
            'source_policy' => [
                'raw_original_or_variants_returned' => false,
                'raw_visual_selection_text_returned' => false,
                'absolute_path_returned' => false,
                'decision_receipt_is_provider_safe' => true,
            ],
            'claim_policy' => [
                'receipt_is_decision_evidence_not_delivery_completion' => true,
                'frontend_done_requires_visual_quality_and_run_certification' => true,
                'visual_selection_is_not_visual_quality_proof' => true,
                'recovery_supported_before_final_claim' => true,
            ],
        ];
        $receipt['decision_receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @return array<int,string>
     */
    private function requiredNextGates(string $status): array
    {
        return match ($status) {
            'accepted' => ['visual_quality_gate', 'design_review_or_reason', 'evidence_pack_verifier', 'run_certification'],
            'discarded' => ['no_delivery_claim_from_discarded_live_patch'],
            'recovered' => ['confirm_workspace_restored_or_prepare_new_live_patch'],
            default => ['accept_discard_or_recover_before_delivery_claim'],
        };
    }

    /**
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>
     */
    private function publicSession(array $session): array
    {
        $public = $session;
        unset($public['original'], $public['variant_payloads']);

        return $public;
    }

    /**
     * @param  array<string,mixed>  $session
     */
    private function privateIntegrityHash(array $session): string
    {
        unset($session['session_hash'], $session['private_integrity_hash']);

        return MissionCanonicalHash::sha256($session);
    }

    /**
     * @return array<string,mixed>
     */
    private function event(string $type, array $metadata = []): array
    {
        $event = [
            'type' => $type,
            'metadata' => $metadata,
            'sequence_hash' => hash('sha256', $type.'|'.json_encode($metadata, JSON_UNESCAPED_SLASHES)),
        ];

        return $event;
    }

    /**
     * @param  array<string,mixed>  $session
     */
    private function writeSession(string $workspace, array $session): void
    {
        File::ensureDirectoryExists($this->sessionDir($workspace));
        File::put($this->sessionPath($workspace, (string) $session['session_id']), json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    /**
     * @return array<string,mixed>
     */
    private function readSession(string $workspace, string $sessionId): array
    {
        $path = $this->sessionPath($workspace, $sessionId);
        if (! File::isFile($path)) {
            throw new RuntimeException('session_not_found');
        }
        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('session_corrupt');
        }
        if (! is_string($decoded['private_integrity_hash'] ?? null)) {
            throw new RuntimeException('session_integrity_missing');
        }
        if (! hash_equals((string) $decoded['private_integrity_hash'], $this->privateIntegrityHash($decoded))) {
            throw new RuntimeException('session_integrity_mismatch');
        }

        return $decoded;
    }

    private function sessionDir(string $workspace): string
    {
        return $workspace.'/.atlas/frontend-live/sessions';
    }

    private function sessionPath(string $workspace, string $sessionId): string
    {
        return $this->sessionDir($workspace).'/'.preg_replace('/[^a-zA-Z0-9._-]/', '_', $sessionId).'.json';
    }

    private function relativePath(string $workspace, string $file): string
    {
        $prefix = rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : basename($file);
    }
}
