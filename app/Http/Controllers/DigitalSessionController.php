<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexDigitalSessionRequest;
use App\Http\Requests\StoreDigitalSessionRequest;
use App\Http\Requests\UpdateDigitalSessionRequest;
use App\Http\Resources\DigitalSessionResource;
use App\Models\DigitalSession;
use App\Support\Metadata;
use Illuminate\Http\JsonResponse;

class DigitalSessionController extends Controller
{
    public function index(IndexDigitalSessionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $limit = (int) ($data['limit'] ?? 50);
        $since = $data['since'] ?? null;

        $query = DigitalSession::query();

        if ($since) {
            $query->withTrashed()->where('updated_at', '>', $since);
        }

        if (isset($data['source'])) {
            $query->where('source', $data['source']);
        }

        if (isset($data['source_identifier'])) {
            $query->where('source_identifier', $data['source_identifier']);
        }

        if (isset($data['category_class'])) {
            $query->where('category_class_at_time', $data['category_class']);
        }

        if (isset($data['date_from'])) {
            $query->where('started_at', '>=', $data['date_from']);
        }

        if (isset($data['date_to'])) {
            $query->where('started_at', '<=', $data['date_to']);
        }

        if (isset($data['cursor'])) {
            $cursor = DigitalSession::withTrashed()->find($data['cursor']);

            if ($cursor) {
                $query->where(function ($query) use ($cursor, $since): void {
                    $column = $since ? 'updated_at' : 'started_at';
                    $operator = $since ? '>' : '<';
                    $idOperator = $since ? '>' : '<';

                    $query
                        ->where($column, $operator, $cursor->{$column})
                        ->orWhere(function ($query) use ($cursor, $column, $idOperator): void {
                            $query->where($column, $cursor->{$column})->where('id', $idOperator, $cursor->id);
                        });
                });
            }
        }

        $query = $since
            ? $query->orderBy('updated_at')->orderBy('id')
            : $query->orderByDesc('started_at')->orderByDesc('id');

        $sessions = $query->limit($limit + 1)->get();
        $hasMore = $sessions->count() > $limit;
        $page = $hasMore ? $sessions->take($limit)->values() : $sessions;

        return response()->json([
            'digital_sessions' => DigitalSessionResource::collection($page)->resolve(),
            'next_cursor' => $hasMore ? $page->last()?->id : null,
            'has_more' => $hasMore,
        ]);
    }

    public function store(StoreDigitalSessionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['raw_payload'] = Metadata::forStorage($data['raw_payload'] ?? []);
        $data['metadata'] = Metadata::forStorage($data['metadata'] ?? []);

        $session = DigitalSession::withTrashed()->where('client_id', $data['client_id'])->first();
        $created = false;

        if (! $session) {
            $session = DigitalSession::create($data);
            $created = true;
        } else {
            $session->fill($data)->save();
        }

        return (new DigitalSessionResource($session->refresh()))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function update(UpdateDigitalSessionRequest $request, DigitalSession $digitalSession): DigitalSessionResource
    {
        $data = $request->validated();

        foreach (['raw_payload', 'metadata'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = Metadata::forStorage($data[$field]);
            }
        }

        $digitalSession->update($data);

        return new DigitalSessionResource($digitalSession->refresh());
    }

    public function destroy(DigitalSession $digitalSession): DigitalSessionResource
    {
        $digitalSession->delete();

        return new DigitalSessionResource($digitalSession->refresh());
    }
}
