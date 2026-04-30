<?php

namespace App\Services;

use App\Models\AtlasDomain;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AtlasDomainRegistry
{
    /**
     * @return Collection<int, AtlasDomain>
     */
    public function all(bool $activeOnly = true): Collection
    {
        if (Schema::hasTable('atlas_domains')) {
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function configuredDefaults(): array
    {
        $domains = config('atlas.domains.defaults', []);

        return is_array($domains) ? array_values($domains) : [];
    }
}
