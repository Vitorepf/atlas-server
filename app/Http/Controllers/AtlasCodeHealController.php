<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasCode\AtlasCodeHealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** C25 policy/tick/undo surface. Observe is the default and is read-only. */
final class AtlasCodeHealController extends Controller
{
    public function __construct(private readonly AtlasCodeHealService $heals) {}

    public function tick(Request $request): JsonResponse
    {
        $input = $request->validate([
            'repo' => ['required', 'string', 'max:120'],
            'mode' => ['sometimes', 'string', 'in:observe,heal'],
            'rule_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'target' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'action' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        try {
            return response()->json($this->heals->tick(
                (string) $input['repo'],
                (string) ($input['mode'] ?? 'observe'),
                isset($input['rule_id']) ? (string) $input['rule_id'] : null,
                isset($input['target']) ? (string) $input['target'] : null,
                isset($input['action']) ? (string) $input['action'] : null,
            ));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function undo(Request $request, string $healId): JsonResponse
    {
        $input = $request->validate(['repo' => ['required', 'string', 'max:120']]);
        try {
            return response()->json($this->heals->undo($healId, (string) $input['repo']));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }
}
