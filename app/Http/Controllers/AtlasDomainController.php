<?php

namespace App\Http\Controllers;

use App\Http\Resources\AtlasDomainResource;
use App\Models\AtlasDomain;
use App\Services\AtlasDomainRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AtlasDomainController extends Controller
{
    public function index(Request $request, AtlasDomainRegistry $registry): JsonResponse
    {
        $includeInactive = $request->boolean('include_inactive', false);

        return response()->json([
            'domains' => AtlasDomainResource::collection($registry->all(activeOnly: ! $includeInactive))->resolve(),
        ]);
    }

    public function store(Request $request, AtlasDomainRegistry $registry): JsonResponse
    {
        $data = $this->validated($request);
        $slug = $registry->normalizeSlug((string) $data['slug']);

        validator(['slug' => $slug], [
            'slug' => ['required', 'string', 'min:2', 'max:48', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('atlas_domains', 'slug')],
        ])->validate();

        $domain = AtlasDomain::query()->create([
            ...$data,
            'slug' => $slug,
            'color_light' => $data['color_light'] ?? '#1B3A57',
            'color_dark' => $data['color_dark'] ?? '#6892B5',
            'default_sensitivity' => $data['default_sensitivity'] ?? 'normal',
            'external_ai_policy' => $data['external_ai_policy'] ?? 'allow',
            'active' => $data['active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 100,
            'metadata' => $data['metadata'] ?? [],
        ]);

        return (new AtlasDomainResource($domain))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, AtlasDomain $domain): AtlasDomainResource
    {
        $data = $this->validated($request, partial: true);
        unset($data['slug']);

        $domain->update([
            ...$data,
            'metadata' => $data['metadata'] ?? $domain->metadata,
        ]);

        return new AtlasDomainResource($domain->refresh());
    }

    public function destroy(AtlasDomain $domain): AtlasDomainResource
    {
        $domain->update(['active' => false]);

        return new AtlasDomainResource($domain->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $optional = 'sometimes';

        return $request->validate([
            'slug' => [$partial ? 'sometimes' : 'required', 'string', 'min:2', 'max:48'],
            'label' => [$required, 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'color_light' => [$optional, 'string', 'max:32'],
            'color_dark' => [$optional, 'string', 'max:32'],
            'default_sensitivity' => [$optional, Rule::in(['normal', 'private', 'sensitive'])],
            'external_ai_policy' => [$optional, Rule::in(['allow', 'block_private_sensitive', 'block_all'])],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'metadata' => ['sometimes', 'array'],
        ]);
    }
}
