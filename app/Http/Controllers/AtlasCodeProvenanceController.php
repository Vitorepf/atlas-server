<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasCode\AtlasCodeProvenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Read-only C23 commit identity/provenance endpoint. */
final class AtlasCodeProvenanceController extends Controller
{
    public function __construct(private readonly AtlasCodeProvenanceService $provenance) {}

    public function __invoke(Request $request, string $hash): JsonResponse
    {
        $input = $request->validate([
            'repo' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        try {
            return response()->json($this->provenance->capture(
                $hash,
                isset($input['repo']) && is_string($input['repo']) ? $input['repo'] : null,
            ));
        } catch (InvalidArgumentException $exception) {
            // 404 = o git rodou e disse que não conhece este commit (ausência
            // real). 503 = o git não pôde ler (timeout, corrupção): falha de
            // leitura, não ausência — o operador não deve concluir que o commit
            // sumiu quando a ferramenta é que não abriu. 422 = pedido malformado.
            $status = match ($exception->getMessage()) {
                'repository_profile_not_found', 'commit_not_found' => 404,
                'git_unavailable', 'repository_path_missing_or_unreadable' => 503,
                default => 422,
            };

            return response()->json([
                'error' => $exception->getMessage(),
                'hash' => $hash,
            ], $status);
        }
    }
}
