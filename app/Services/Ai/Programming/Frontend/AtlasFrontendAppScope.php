<?php

namespace App\Services\Ai\Programming\Frontend;

final class AtlasFrontendAppScope
{
    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public static function fromRequestedApp(string $workspace, mixed $frontendApp, array $options = []): array
    {
        $schemaVersion = self::stringOption($options, 'schema_version');
        $includeRawAbsolutePathReturned = (bool) ($options['include_raw_absolute_path_returned'] ?? false);
        $includeRootBlockers = (bool) ($options['include_root_blockers'] ?? false);
        $dotIsRepoRoot = (bool) ($options['dot_is_repo_root'] ?? false);
        $rejectRawAbsolute = (bool) ($options['reject_raw_absolute'] ?? true);
        $rejectDoubleSlash = (bool) ($options['reject_double_slash'] ?? false);
        $workspaceRequiredStatus = self::stringOption($options, 'workspace_required_status');
        $workspaceRequiredBlocker = self::stringOption($options, 'workspace_required_blocker');
        $requirePackageManifest = (bool) ($options['require_package_manifest'] ?? false);

        if (! is_string($frontendApp) || trim($frontendApp) === '') {
            return self::payload('repo_root', null, [], $schemaVersion, $includeRawAbsolutePathReturned, $includeRootBlockers);
        }

        $raw = trim($frontendApp);
        $relative = trim(str_replace('\\', '/', $raw), '/');

        if ($relative === '' || ($dotIsRepoRoot && $relative === '.')) {
            return self::payload('repo_root', null, [], $schemaVersion, $includeRawAbsolutePathReturned, $includeRootBlockers);
        }

        if (str_contains($relative, '..') || ($rejectRawAbsolute && str_starts_with($raw, '/')) || ($rejectDoubleSlash && str_contains($relative, '//'))) {
            return self::payload(
                'invalid_subscope',
                null,
                [self::stringOption($options, 'invalid_blocker') ?? 'frontend_app_scope_invalid_relative_frontend_app_subscope'],
                $schemaVersion,
                $includeRawAbsolutePathReturned,
                true,
                hash('sha256', $relative),
            );
        }

        if ($workspaceRequiredStatus !== null && ($workspace === '' || ! is_dir($workspace))) {
            return self::payload($workspaceRequiredStatus, $relative, $workspaceRequiredBlocker !== null ? [$workspaceRequiredBlocker] : [], $schemaVersion, $includeRawAbsolutePathReturned, true);
        }

        $candidate = rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($workspace !== '' && is_dir($workspace) && ! is_dir($candidate)) {
            return self::payload(
                'missing_subscope',
                $relative,
                [self::stringOption($options, 'missing_blocker') ?? 'frontend_app_scope_frontend_app_subscope_directory_missing'],
                $schemaVersion,
                $includeRawAbsolutePathReturned,
                true,
            );
        }

        if ($requirePackageManifest && ! file_exists($candidate.DIRECTORY_SEPARATOR.'package.json')) {
            return self::payload(
                'missing_package_manifest',
                $relative,
                [self::stringOption($options, 'missing_package_manifest_blocker') ?? 'frontend_app_subscope_package_manifest_missing'],
                $schemaVersion,
                $includeRawAbsolutePathReturned,
                true,
            );
        }

        return self::payload('subscope_selected', $relative, [], $schemaVersion, $includeRawAbsolutePathReturned, $includeRootBlockers);
    }

    /**
     * @return array<string,mixed>
     */
    public static function fromTrustedRelativeName(?string $relativeName): array
    {
        if ($relativeName === null || $relativeName === '') {
            return [
                'status' => 'repo_root',
                'relative_name_hash' => null,
            ];
        }

        return [
            'status' => 'subscope_selected',
            'relative_name' => $relativeName,
            'relative_name_hash' => hash('sha256', $relativeName),
            'repo_workspace_remains_primary' => true,
        ];
    }

    public static function relativeName(mixed $frontendApp): ?string
    {
        if (! is_string($frontendApp) || trim($frontendApp) === '') {
            return null;
        }

        $relative = trim(str_replace('\\', '/', $frontendApp), '/');

        return $relative !== '' ? $relative : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function fromPayload(array $payload, string $field = 'frontend_app_scope', bool $includeWorkspaceFlag = true): array
    {
        $scope = $payload[$field] ?? null;

        return self::normalize(is_array($scope) ? $scope : [], $includeWorkspaceFlag);
    }

    /**
     * @param  array<string,mixed>  $scope
     * @return array<string,mixed>
     */
    public static function normalize(array $scope, bool $includeWorkspaceFlag = true): array
    {
        if ($scope === []) {
            return ['status' => 'repo_root', 'relative_name_hash' => null];
        }

        $status = (string) ($scope['status'] ?? 'repo_root');
        if ($status !== 'subscope_selected') {
            return [
                'status' => $status !== '' ? $status : 'repo_root',
                'relative_name_hash' => null,
            ];
        }

        $relative = is_string($scope['relative_name'] ?? null)
            ? trim(str_replace('\\', '/', (string) $scope['relative_name']), '/')
            : null;

        if ($relative === null || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            return [
                'status' => 'invalid_subscope',
                'relative_name_hash' => $relative !== null ? hash('sha256', $relative) : null,
            ];
        }

        $normalized = [
            'status' => 'subscope_selected',
            'relative_name' => $relative,
            'relative_name_hash' => hash('sha256', $relative),
        ];

        if ($includeWorkspaceFlag) {
            $normalized['repo_workspace_remains_primary'] = true;
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    public static function key(array $scope): string
    {
        return implode(':', [
            (string) ($scope['status'] ?? 'repo_root'),
            (string) ($scope['relative_name_hash'] ?? ''),
        ]);
    }

    /**
     * @param  array<int,string>  $blockers
     * @return array<string,mixed>
     */
    private static function payload(
        string $status,
        ?string $relativeName,
        array $blockers,
        ?string $schemaVersion,
        bool $includeRawAbsolutePathReturned,
        bool $includeBlockers,
        ?string $relativeNameHash = null,
    ): array {
        $payload = [];
        if ($schemaVersion !== null) {
            $payload['schema_version'] = $schemaVersion;
        }

        $payload += [
            'status' => $status,
            'relative_name' => $relativeName,
            'relative_name_hash' => $relativeNameHash ?? ($relativeName !== null ? hash('sha256', $relativeName) : null),
            'repo_workspace_remains_primary' => true,
        ];

        if ($includeRawAbsolutePathReturned) {
            $payload['raw_absolute_path_returned'] = false;
        }
        if ($includeBlockers) {
            $payload['blockers'] = $blockers;
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private static function stringOption(array $options, string $key): ?string
    {
        return is_string($options[$key] ?? null) ? (string) $options[$key] : null;
    }
}
