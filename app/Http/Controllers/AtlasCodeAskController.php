<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AtlasCode\AtlasCodeAskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * H6 · a pílula pergunta ao grafo.
 *
 * Somente leitura: perguntar nunca muta o repositório. Mandar fazer é outro
 * verbo, e vai ter outra porta — com recibo e desfazer.
 */
final class AtlasCodeAskController extends Controller
{
    public function __construct(private readonly AtlasCodeAskService $ask) {}

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'repo' => ['required', 'string', 'max:120'],
            'question' => ['required', 'string', 'max:500'],
            // Quem sabe que horas são para o operador é o aparelho na mão dele.
            // Sem isso o servidor responde em UTC — e "hoje" seria o dia errado.
            //
            // SEM a regra `timezone` de propósito: fuso inválido derrubava a
            // pergunta inteira com 422 (em inglês), contra o contrato fail-open
            // — o serviço já cai para UTC quando não entende o fuso, e hora
            // errada de fuso nunca deve calar uma resposta sobre git.
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            // `facts` = leitura pura, para o agente do card ler antes de
            // responder. Um modo desconhecido não vira `answer` em silêncio:
            // `answer` pode despachar frota, e adivinhar verbo é como um
            // coletor de fato acaba mandando 12 agentes trabalharem sozinho.
            'mode' => ['sometimes', 'string', 'in:answer,facts'],
        ]);

        try {
            return response()->json($this->ask->answer(
                (string) $input['repo'],
                (string) $input['question'],
                null,
                isset($input['timezone']) && is_string($input['timezone']) ? $input['timezone'] : null,
                isset($input['mode']) && is_string($input['mode'])
                    ? $input['mode']
                    : AtlasCodeAskService::MODE_ANSWER,
            ));
        } catch (InvalidArgumentException $exception) {
            $status = in_array($exception->getMessage(), ['repository_profile_not_found'], true) ? 404 : 422;

            return response()->json([
                'error' => $exception->getMessage(),
                'repo' => $input['repo'],
            ], $status);
        }
    }
}
