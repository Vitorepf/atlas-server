<?php

namespace App\Services;

use App\Models\AtlasDomain;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AtlasDomainRegistry
{
    /**
     * @return Collection<int, AtlasDomain>
     */
    public function all(bool $activeOnly = true): Collection
    {
        if (DatabaseTableAvailability::has('atlas_domains')) {
            $this->syncConfiguredDefaults();

            $query = AtlasDomain::query()->orderBy('sort_order')->orderBy('label');
            if ($activeOnly) {
                $query->where('active', true);
            }

            $domains = $query->get();
            if ($domains->isNotEmpty()) {
                return $domains;
            }
        }

        return collect($this->configuredDefaults())
            ->filter(fn (array $domain): bool => ! $activeOnly || (bool) ($domain['active'] ?? true))
            ->sortBy(fn (array $domain): array => [(int) ($domain['sort_order'] ?? 100), (string) $domain['label']])
            ->values()
            ->map(fn (array $domain): AtlasDomain => new AtlasDomain($domain));
    }

    /**
     * @return array<int, string>
     */
    public function activeSlugs(): array
    {
        return $this->all()
            ->pluck('slug')
            ->filter(fn (mixed $slug): bool => is_string($slug) && $slug !== '')
            ->values()
            ->all();
    }

    public function defaultSlug(): string
    {
        return $this->exists('outro') ? 'outro' : ($this->activeSlugs()[0] ?? 'outro');
    }

    /**
     * @return array<int, string>
     */
    public function configuredDefaultSlugs(): array
    {
        return collect($this->configuredDefaults())
            ->map(fn (array $domain): string => $this->normalizeSlug((string) ($domain['slug'] ?? '')))
            ->filter(fn (string $slug): bool => $slug !== '')
            ->values()
            ->all();
    }

    public function isConfiguredDefault(string $slug): bool
    {
        return in_array($this->normalizeSlug($slug), $this->configuredDefaultSlugs(), true);
    }

    public function exists(string $slug): bool
    {
        return in_array($slug, $this->activeSlugs(), true);
    }

    public function defaultSensitivity(string $slug): string
    {
        $domain = $this->all(activeOnly: false)->firstWhere('slug', $slug);
        $sensitivity = $domain?->default_sensitivity;

        return in_array($sensitivity, ['normal', 'private', 'sensitive'], true) ? $sensitivity : 'normal';
    }

    public function externalAiPolicy(string $slug): string
    {
        $domain = $this->all(activeOnly: false)->firstWhere('slug', $slug);
        $policy = $domain?->external_ai_policy;

        return in_array($policy, ['allow', 'block_private_sensitive', 'block_all'], true) ? $policy : 'allow';
    }

    public function normalizeSlug(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9_-]+/', '-')
            ->trim('-_')
            ->toString();
    }

    public function syncConfiguredDefaults(): void
    {
        if (! DatabaseTableAvailability::has('atlas_domains')) {
            return;
        }

        foreach ($this->configuredDefaults() as $domain) {
            $attributes = $this->configuredDefaultAttributes($domain);
            if ($attributes === null) {
                continue;
            }

            $metadata = $attributes['metadata'];
            unset($attributes['metadata']);

            $existing = AtlasDomain::query()->whereKey($attributes['slug'])->first();
            if (! $existing) {
                AtlasDomain::query()->create([
                    ...$attributes,
                    'metadata' => $metadata,
                ]);

                continue;
            }

            $updates = [];
            foreach ($attributes as $key => $value) {
                if ($key === 'slug') {
                    continue;
                }

                $current = $existing->{$key};
                if ($key === 'active') {
                    $current = (bool) $current;
                } elseif ($key === 'sort_order') {
                    $current = (int) $current;
                }

                if ($current !== $value) {
                    $updates[$key] = $value;
                }
            }

            if ($updates !== []) {
                $existing->forceFill($updates)->save();
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function configuredDefaults(): array
    {
        $domains = config('atlas.domains.defaults', []);

        return is_array($domains) ? array_values($domains) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function configuredDefaultAttributes(array $domain): ?array
    {
        $slug = $this->normalizeSlug((string) ($domain['slug'] ?? ''));
        if ($slug === '') {
            return null;
        }

        return [
            'slug' => $slug,
            'label' => (string) ($domain['label'] ?? Str::of(str_replace(['-', '_'], ' ', $slug))->title()->toString()),
            'description' => $domain['description'] ?? null,
            'color_light' => (string) ($domain['color_light'] ?? '#1B3A57'),
            'color_dark' => (string) ($domain['color_dark'] ?? '#6892B5'),
            'default_sensitivity' => $this->validSensitivity($domain['default_sensitivity'] ?? null),
            'external_ai_policy' => $this->validExternalAiPolicy($domain['external_ai_policy'] ?? null),
            'active' => (bool) ($domain['active'] ?? true),
            'sort_order' => (int) ($domain['sort_order'] ?? 100),
            'metadata' => is_array($domain['metadata'] ?? null) ? $domain['metadata'] : [],
        ];
    }

    private function validSensitivity(mixed $value): string
    {
        return in_array($value, ['normal', 'private', 'sensitive'], true) ? $value : 'normal';
    }

    private function validExternalAiPolicy(mixed $value): string
    {
        return in_array($value, ['allow', 'block_private_sensitive', 'block_all'], true) ? $value : 'allow';
    }
}
