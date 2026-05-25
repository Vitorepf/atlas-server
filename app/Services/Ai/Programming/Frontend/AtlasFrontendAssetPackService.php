<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class AtlasFrontendAssetPackService
{
    public const SCHEMA_VERSION = 'atlas.frontend.asset_pack_verifier.v1';

    public const PACK_SCHEMA_VERSION = 'atlas.frontend.asset_pack.v1';

    public const TEMPLATE_SCHEMA_VERSION = 'atlas.frontend.asset_pack_template.v1';

    /**
     * @return array<string,mixed>
     */
    public function inspect(string $packPath): array
    {
        $packPath = trim($packPath);
        $blockers = [];
        $warnings = [];
        $raw = '';

        if ($packPath === '' || ! File::isFile($packPath)) {
            return $this->result('blocked', $packPath, '', ['asset_pack_missing'], [], []);
        }

        $raw = File::get($packPath);
        $pack = json_decode($raw, true);
        if (! is_array($pack)) {
            return $this->result('blocked', $packPath, $raw, ['asset_pack_json_invalid'], [], []);
        }

        if (($pack['schema_version'] ?? null) !== self::PACK_SCHEMA_VERSION) {
            $blockers[] = 'schema_version_invalid';
        }
        foreach (['pack_id', 'task_spec_hash', 'assets'] as $field) {
            if (! array_key_exists($field, $pack) || $pack[$field] === null || $pack[$field] === '') {
                $blockers[] = 'missing_'.$field;
            }
        }
        if (! preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($pack['task_spec_hash'] ?? '')))) {
            $blockers[] = 'task_spec_hash_invalid';
        }
        if ($this->hasForbiddenRawFields($pack)) {
            $blockers[] = 'forbidden_raw_prompt_source_customer_or_secret_field_present';
        }

        $assetResults = is_array($pack['assets'] ?? null) ? $this->inspectAssets($pack['assets']) : [];
        if (! is_array($pack['assets'] ?? null)) {
            $blockers[] = 'assets_must_be_array';
        }
        if ($assetResults === []) {
            $blockers[] = 'assets_missing';
        }

        $presentKinds = array_values(array_unique(array_map(
            fn (array $asset): string => (string) ($asset['kind'] ?? ''),
            array_filter($assetResults, fn (array $asset): bool => ($asset['status'] ?? null) === 'present'),
        )));
        foreach ($this->recommendedAssetKinds() as $kind) {
            if (! in_array($kind, $presentKinds, true)) {
                $warnings[] = 'missing_recommended_asset_kind_'.$kind;
            }
        }

        foreach ($assetResults as $asset) {
            foreach ((array) ($asset['blockers'] ?? []) as $blocker) {
                $blockers[] = $blocker;
            }
            foreach ((array) ($asset['warnings'] ?? []) as $warning) {
                $warnings[] = $warning;
            }
        }

        return $this->result($blockers === [] ? 'passed' : 'blocked', $packPath, $raw, array_values(array_unique($blockers)), array_values(array_unique($warnings)), $assetResults, [
            'asset_count' => count($assetResults),
            'present_kinds' => $presentKinds,
            'pack_id_hash' => isset($pack['pack_id']) ? hash('sha256', (string) $pack['pack_id']) : null,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function writeTemplate(string $outputDirectory): array
    {
        $outputDirectory = rtrim(trim($outputDirectory), DIRECTORY_SEPARATOR);
        File::ensureDirectoryExists($outputDirectory);
        $packPath = $outputDirectory.'/frontend-asset-pack.json';

        if (! File::isFile($packPath)) {
            File::put($packPath, json_encode([
                'schema_version' => self::PACK_SCHEMA_VERSION,
                'pack_id' => 'acme-frontend-assets-v1',
                'task_spec_hash' => '<sha256-64-hex>',
                'assets' => array_map(fn (string $kind): array => [
                    'kind' => $kind,
                    'path' => 'assets/'.$kind.'.png',
                    'sha256' => '<sha256-64-hex>',
                    'source_ref' => 'approved-company-source-or-url-hash',
                    'license' => 'owned_or_approved',
                    'usage_rights' => 'allowed_for_product_ui',
                    'critical' => in_array($kind, ['logo', 'product_screenshot'], true),
                    'dimensions' => ['width' => 1440, 'height' => 900],
                ], $this->recommendedAssetKinds()),
                'notes' => 'Store asset refs and hashes only. Do not store raw prompts, customer source, cookies, tokens or provider secrets.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        $payload = [
            'schema_version' => self::TEMPLATE_SCHEMA_VERSION,
            'status' => 'ready',
            'template_type' => 'frontend_asset_pack',
            'pack_path_hash' => hash('sha256', $packPath),
            'allowed_asset_kinds' => $this->allowedAssetKinds(),
            'recommended_asset_kinds' => $this->recommendedAssetKinds(),
            'claim_policy' => [
                'template_is_not_evidence' => true,
                'asset_claim_requires_passed_inspection' => true,
                'placeholder_or_unlicensed_assets_block_claim' => true,
                'raw_customer_source_forbidden' => true,
            ],
        ];
        $payload['template_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<int,string>
     */
    public function allowedAssetKinds(): array
    {
        return ['logo', 'product_screenshot', 'design_reference', 'brand_image', 'icon', 'font', 'illustration', 'video', 'document'];
    }

    /**
     * @return array<int,string>
     */
    public function recommendedAssetKinds(): array
    {
        return ['logo', 'product_screenshot', 'design_reference'];
    }

    /**
     * @param  array<int,mixed>  $assets
     * @return array<int,array<string,mixed>>
     */
    private function inspectAssets(array $assets): array
    {
        return array_values(array_map(function (mixed $asset): array {
            if (! is_array($asset)) {
                return ['status' => 'blocked', 'blockers' => ['asset_entry_invalid'], 'warnings' => []];
            }

            $kind = (string) ($asset['kind'] ?? '');
            $path = (string) ($asset['path'] ?? '');
            $hash = strtolower((string) ($asset['sha256'] ?? ''));
            $license = (string) ($asset['license'] ?? '');
            $rights = (string) ($asset['usage_rights'] ?? '');
            $critical = (bool) ($asset['critical'] ?? false);
            $blockers = [];
            $warnings = [];

            if (! in_array($kind, $this->allowedAssetKinds(), true)) {
                $blockers[] = 'asset_kind_invalid';
            }
            if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
                $blockers[] = 'asset_path_invalid';
            }
            if ($this->looksPlaceholder($path) || $this->looksPlaceholder((string) ($asset['source_ref'] ?? ''))) {
                $blockers[] = 'placeholder_asset_forbidden';
            }
            if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
                $blockers[] = 'asset_hash_invalid';
            }
            if (! is_string($asset['source_ref'] ?? null) || trim((string) $asset['source_ref']) === '') {
                $blockers[] = 'asset_source_ref_missing';
            }
            if (! in_array($license, ['owned_or_approved', 'open_license', 'generated_with_commercial_rights'], true)) {
                $blockers[] = 'asset_license_invalid';
            }
            if (! in_array($rights, ['allowed_for_product_ui', 'allowed_for_prototype_only', 'allowed_for_internal_review'], true)) {
                $blockers[] = 'asset_usage_rights_invalid';
            }
            if ($critical && ! is_array($asset['dimensions'] ?? null)) {
                $blockers[] = 'critical_asset_dimensions_missing';
            }
            if ($critical && is_array($asset['dimensions'] ?? null)) {
                $width = (int) data_get($asset, 'dimensions.width', 0);
                $height = (int) data_get($asset, 'dimensions.height', 0);
                if ($width < 256 || $height < 256) {
                    $blockers[] = 'critical_asset_resolution_too_low';
                }
            }
            if ($rights !== 'allowed_for_product_ui') {
                $warnings[] = 'asset_not_cleared_for_product_ui';
            }

            return [
                'kind' => $kind,
                'path_hash' => $path !== '' ? hash('sha256', $path) : null,
                'source_ref_hash' => isset($asset['source_ref']) ? hash('sha256', (string) $asset['source_ref']) : null,
                'status' => $blockers === [] ? 'present' : 'blocked',
                'sha256' => preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : null,
                'critical' => $critical,
                'blockers' => array_values(array_unique($blockers)),
                'warnings' => array_values(array_unique($warnings)),
            ];
        }, $assets));
    }

    private function looksPlaceholder(string $value): bool
    {
        return preg_match('/placeholder|lorem|picsum|placehold\.co|dummyimage|unsplash\.it/i', $value) === 1;
    }

    /**
     * @param  array<int,string>  $blockers
     * @param  array<int,string>  $warnings
     * @param  array<int,array<string,mixed>>  $assets
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function result(string $status, string $packPath, string $raw, array $blockers, array $warnings, array $assets, array $extra = []): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'verifier_type' => 'frontend_asset_pack',
            'source' => self::class,
            'pack_schema_version' => self::PACK_SCHEMA_VERSION,
            'pack_path_hash' => $packPath !== '' ? hash('sha256', $packPath) : null,
            'pack_hash' => $raw !== '' ? hash('sha256', $raw) : null,
            'allowed_asset_kinds' => $this->allowedAssetKinds(),
            'recommended_asset_kinds' => $this->recommendedAssetKinds(),
            'asset_results' => $assets,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'claim_policy' => [
                'asset_provenance_claim_allowed' => $status === 'passed' && $blockers === [],
                'placeholder_or_unlicensed_assets_block_claim' => true,
                'critical_assets_require_dimensions' => true,
                'raw_customer_source_returned' => false,
            ],
            ...array_filter($extra, fn (mixed $value): bool => $value !== null),
        ];
        $payload['verification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hasForbiddenRawFields(array $payload): bool
    {
        $forbidden = ['raw_prompt', 'prompt', 'source', 'raw_source', 'customer_source', 'customer_data', 'secret', 'api_key'];

        foreach ($payload as $key => $value) {
            if (in_array(Str::snake((string) $key), $forbidden, true)) {
                return true;
            }
            if (is_array($value) && $this->hasForbiddenRawFields($value)) {
                return true;
            }
        }

        return false;
    }
}
