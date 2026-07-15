<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasCode\AtlasCodeReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * H6 · o estado dos agentes que revisam, por commit.
 *
 * Só leitura: quem MANDA revisar é a pílula (`/code/ask`), porque mandar é uma
 * frase do operador, não um botão. Aqui a tela só pergunta "e aí?" — e o grafo
 * se transforma com a resposta.
 */
final class AtlasCodeReviewController extends Controller
{
    public function __construct(private readonly AtlasCodeReviewService $review) {}

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'repo' => ['required', 'string', 'max:120'],
            'hashes' => ['required', 'string', 'max:600'],
        ]);

        try {
            return response()->json($this->review->status(
                (string) $input['repo'],
                explode(',', (string) $input['hashes']),
            ));
        } catch (InvalidArgumentException $exception) {
            $status = $exception->getMessage() === 'repository_profile_not_found' ? 404 : 422;

            return response()->json([
                'error' => $exception->getMessage(),
                'repo' => $input['repo'],
            ], $status);
        }
    }
}
