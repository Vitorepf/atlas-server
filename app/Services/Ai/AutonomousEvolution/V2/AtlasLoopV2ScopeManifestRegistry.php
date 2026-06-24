<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\V2;

use DomainException;

final class AtlasLoopV2ScopeManifestRegistry
{
    /** @var list<array<string,mixed>> */
    private array $scopes;

    /** @var array<string,array<string,mixed>> */
    private array $byId = [];

    /**
     * @param  null|list<array<string,mixed>>  $scopes
     */
    public function __construct(?array $scopes = null)
    {
        $this->scopes = array_values($scopes ?? (array) config('atlas.ai.loop.v2.scopes', []));
        $this->validate();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->scopes;
    }

    /**
     * @return null|array<string,mixed>
     */
    public function findById(string $id): ?array
    {
        $id = trim($id);

        return $id !== '' ? ($this->byId[$id] ?? null) : null;
    }

    public function assertHomeRepoPresent(string $homeRepo): void
    {
        $home = $this->canonicalPath($homeRepo);
        foreach ($this->scopes as $scope) {
            if ($this->canonicalPath((string) $scope['repo_root_absolute']) === $home) {
                return;
            }
        }

        throw new DomainException('Loop V2 scope manifest missing home repo: '.$home);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function tieredScopes(string $tier): array
    {
        $tier = strtolower(trim($tier));

        return array_values(array_filter(
            $this->scopes,
            static fn (array $scope): bool => (string) $scope['risk_tier'] === $tier,
        ));
    }

    public function totalParallelBudget(): int
    {
        return array_sum(array_map(
            static fn (array $scope): int => (int) $scope['max_parallel_workers'],
            $this->scopes,
        ));
    }

    private function validate(): void
    {
        foreach ($this->scopes as $index => $scope) {
            if (! is_array($scope)) {
                throw new DomainException('Loop V2 scope manifest <index:'.$index.'> field manifest must be an array.');
            }

            $id = $this->validateId($scope, $index);
            $this->validateRequiredString($scope, $id, 'territory_name');
            $this->validateAbsolutePath($scope, $id, 'repo_root_absolute');
            $this->validateNonEmptyArray($scope, $id, 'discovery_roots');
            $this->validateArray($scope, $id, 'frozen_safety_files');
            $this->validateRiskTier($scope, $id);
            $this->validatePositiveInt($scope, $id, 'max_parallel_workers');
            $this->validatePositiveInt($scope, $id, 'promotion_required_certified_leaps');

            if (isset($this->byId[$id])) {
                throw new DomainException('Loop V2 scope manifest '.$id.' field id is duplicated.');
            }
            $this->byId[$id] = $scope;
        }
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    private function validateId(array $scope, int $index): string
    {
        if (! array_key_exists('id', $scope)) {
            throw new DomainException('Loop V2 scope manifest <index:'.$index.'> field id is required.');
        }

        $id = trim((string) $scope['id']);
        if ($id === '' || preg_match('/^[a-z0-9_-]+$/', $id) !== 1) {
            throw new DomainException('Loop V2 scope manifest '.($id !== '' ? $id : '<empty>').' field id is invalid.');
        }

        return $id;
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    private function validateRequiredString(array $scope, string $id, string $field): void
    {
        if (! array_key_exists($field, $scope) || trim((string) $scope[$field]) === '') {
            throw new DomainException('Loop V2 scope manifest '.$id.' field '.$field.' is required.');
        }
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    private function validateAbsolutePath(array $scope, string $id, string $field): void
    {
        $this->validateRequiredString($scope, $id, $field);
        if (! str_starts_with((string) $scope[$field], '/')) {
            throw new DomainException('Loop V2 scope manifest '.$id.' field '.$field.' must be absolute.');
        }
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    private function validateArray(array $scope, string $id, string $field): void
    {
        if (! array_key_exists($field, $scope) || ! is_array($scope[$field])) {
            throw new DomainException('Loop V2 scope manifest '.$id.' field '.$field.' must be an array.');
        }
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    private function validateNonEmptyArray(array $scope, string $id, string $field): void
    {
        $this->validateArray($scope, $id, $field);
        if (count((array) $scope[$field]) === 0) {
            throw new DomainException('Loop V2 scope manifest '.$id.' field '.$field.' must be non-empty.');
        }
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    private function validateRiskTier(array $scope, string $id): void
    {
        $tier = strtolower(trim((string) ($scope['risk_tier'] ?? '')));
        if (! in_array($tier, ['low', 'medium', 'high'], true)) {
            throw new DomainException('Loop V2 scope manifest '.$id.' field risk_tier is invalid.');
        }
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    private function validatePositiveInt(array $scope, string $id, string $field): void
    {
        if (! array_key_exists($field, $scope) || ! is_int($scope[$field]) || $scope[$field] < 1) {
            throw new DomainException('Loop V2 scope manifest '.$id.' field '.$field.' must be an int >= 1.');
        }
    }

    private function canonicalPath(string $path): string
    {
        $path = preg_replace('#/+#', '/', str_replace('\\', '/', trim($path))) ?? '';
        if (! str_starts_with($path, '/')) {
            throw new DomainException('Loop V2 scope manifest home repo field repo_root_absolute must be absolute.');
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/'.implode('/', $parts);
    }
}
