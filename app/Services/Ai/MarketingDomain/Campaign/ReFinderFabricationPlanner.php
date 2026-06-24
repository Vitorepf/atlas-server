<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * ReFinderFabricationPlanner — a execução da regra #8, o MOAT da dissecação: o ativo de longo prazo não é
 * capturar a busca de marca existente (leilão lotado), é FABRICAR a busca de amanhã. Você PLANTA um nome
 * coined proprietário no advertorial de sintoma frio (leilão sem concorrente), e ele vira o "orivelle" que
 * a próxima onda googla — num leilão onde só VOCÊ está.
 *
 * O segredo operacional: no INSTANTE do plantio, você já tem que POSSUIR o espaço de busca inteiro do token
 * — exact + todo o cone de mistype (a memória auditiva mobile reconstrói de ouvido) + a matriz de descritor —
 * senão um arbitrageiro pega a busca que VOCÊ fabricou. Este planner compõe as forges provadas pra entregar:
 * (1) o plano de plantio pra recall, (2) a campanha que pré-possui T, (3) a chave de tracking do re-find.
 * Provider-free, determinístico. (A COPY do advertorial em si é Conversion-OS; aqui é o PLANO + a posse.)
 */
class ReFinderFabricationPlanner
{
    public function __construct(
        private readonly PhoneticMistypeForge $mistype = new PhoneticMistypeForge,
        private readonly DescriptorMatrixForge $descriptor = new DescriptorMatrixForge,
    ) {}

    /**
     * @param  array{categories?:array<int,string>,forms?:array<int,string>,seed_count?:int}  $opts
     * @return array<string,mixed>
     */
    public function plan(string $coinedToken, array $opts = []): array
    {
        $token = mb_strtolower(trim(preg_replace('/\s+/', ' ', $coinedToken)));
        if ($token === '') {
            return ['token' => '', 'plantable' => false, 'why' => 'token vazio — nada a fabricar'];
        }

        $tokens = preg_split('/\s+/', $token) ?: [];
        $head = $this->head($tokens);
        $suffix = trim(mb_substr($token, mb_strlen((string) $tokens[0])));
        $categories = (array) ($opts['categories'] ?? array_slice($tokens, 1));
        $forms = (array) ($opts['forms'] ?? []);
        $seed = max(3, (int) ($opts['seed_count'] ?? 4));

        // POSSE-NO-INSTANTE: o espaço de busca inteiro de T, pré-construído pra você ganhar o leilão sozinho
        $mistypeCone = $this->mistype->forge($head, $suffix !== '' ? [$suffix] : []);
        $descriptorMatrix = $this->descriptor->forge($token, $categories, $forms, null, 60);

        return [
            'token' => $token,
            'plantable' => true,
            // (1) como semear T no advertorial pra MÁXIMO recall (memória auditiva mobile)
            'plant' => [
                'seed_count' => $seed,
                'positions' => ['lead/gancho', 'nomear-o-mecanismo', 'prova/demonstração', 'fechamento/CTA'],
                'recall_frame' => "nomeie o mecanismo '{$token}' CEDO e repita idêntico no clímax; fale + escreva (mobile = memória de OUVIDO) — o token tem que ser impossível de gerar sem ter lido/ouvido isto",
            ],
            // (2) a campanha que POSSUI T no instante do plantio (exact + mistype + descritor)
            'own_now' => [
                'exact' => $token,
                'mistype_cone' => $mistypeCone,              // captura orvelle/orville (33% CVR, leilão virgem)
                'descriptor_matrix' => $descriptorMatrix,     // [token]×[categoria]×[forma]×[buy-intent]
                'match' => 'exact + 1 broad de marca governado SÓ pros mistypes; STAG no exact',
                'count' => 1 + count($mistypeCone) + count($descriptorMatrix),
            ],
            // (3) como medir o re-find que VOCÊ fabricou (atribuição ao advertorial que plantou)
            'track' => [
                'branded_search_key' => $token,
                'cone_keys' => array_slice($mistypeCone, 0, 10),
                'signal' => "utm_term contendo '{$token}' ou seu cone = re-find atribuível ao advertorial-plantio; mede a demanda FABRICADA (não capturada)",
            ],
            'why' => "fabrica o re-finder de amanhã: planta '{$token}' no advertorial de sintoma frio (leilão sem concorrente) e JÁ possui exact+mistype+descritor de '{$token}' no instante do plantio — ninguém arbitra a busca que você criou",
        ];
    }

    /** head pro cone de mistype = a palavra mais longa (a mais coinável/incomum) do token. */
    private function head(array $tokens): string
    {
        $words = array_values(array_filter($tokens, fn ($w) => $w !== ''));
        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return (string) ($words[0] ?? '');
    }
}
