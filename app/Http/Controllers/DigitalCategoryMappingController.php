<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexDigitalCategoryMappingRequest;
use App\Http\Requests\StoreDigitalCategoryMappingRequest;
use App\Http\Requests\UpdateDigitalCategoryMappingRequest;
use App\Http\Resources\DigitalCategoryMappingResource;
use App\Models\DigitalCategoryMapping;
use App\Support\Metadata;
use Illuminate\Http\JsonResponse;

class DigitalCategoryMappingController extends Controller
{
    public function index(IndexDigitalCategoryMappingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $limit = (int) ($data['limit'] ?? 200);

        $query = DigitalCategoryMapping::query()->orderBy('source_name')->orderByDesc('valid_from');

        if ($data['current'] ?? true) {
            $query->whereNull('valid_until');
        }

        if (isset($data['category_class'])) {
            $query->where('category_class', $data['category_class']);
        }

        if (isset($data['source_identifier'])) {
            $query->where('source_identifier', $data['source_identifier']);
        }

        return response()->json([
            'digital_category_mappings' => DigitalCategoryMappingResource::collection($query->limit($limit)->get())->resolve(),
        ]);
    }

    public function store(StoreDigitalCategoryMappingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['metadata'] = Metadata::forStorage($data['metadata'] ?? []);
        $data['valid_from'] ??= now();

        $current = DigitalCategoryMapping::query()
            ->where('source_identifier', $data['source_identifier'])
            ->where('source_kind', $data['source_kind'])
            ->whereNull('valid_until')
            ->first();

        if ($current) {
            $sameClassification = (int) $current->category_class === (int) $data['category_class']
                && $current->category_label === $data['category_label']
                && $current->intentionality === $data['intentionality'];

            if ($sameClassification) {
                $current->fill($data)->save();

                return (new DigitalCategoryMappingResource($current->refresh()))
                    ->response()
                    ->setStatusCode(200);
            }

            $current->update(['valid_until' => now()]);
        }

        return (new DigitalCategoryMappingResource(DigitalCategoryMapping::create($data)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateDigitalCategoryMappingRequest $request, DigitalCategoryMapping $digitalCategoryMapping): DigitalCategoryMappingResource
    {
        $data = $request->validated();

        if (array_key_exists('metadata', $data)) {
            $data['metadata'] = Metadata::forStorage($data['metadata']);
        }

        $digitalCategoryMapping->update($data);

        return new DigitalCategoryMappingResource($digitalCategoryMapping->refresh());
    }

    public function destroy(DigitalCategoryMapping $digitalCategoryMapping): DigitalCategoryMappingResource
    {
        $digitalCategoryMapping->update(['valid_until' => now()]);

        return new DigitalCategoryMappingResource($digitalCategoryMapping->refresh());
    }
}
