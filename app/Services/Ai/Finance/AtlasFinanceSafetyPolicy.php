<?php

namespace App\Services\Ai\Finance;

class AtlasFinanceSafetyPolicy
{
    /**
     * @var array<string,array<int,string>>
     */
    private const ACTION_PATTERNS = [
        'place_order' => [
            'place order',
            'submit order',
            'send order',
            'execute order',
            'market order',
            'limit order',
            'buy ',
            'comprar',
            'purchase ',
            'sell ',
            'vender',
            'short ',
            'shortar',
            'cover ',
        ],
        'modify_order' => [
            'modify order',
            'change order',
            'adjust order',
            'alterar ordem',
            'modificar ordem',
        ],
        'cancel_order' => [
            'cancel order',
            'cancelar ordem',
            'pull order',
        ],
        'rebalance_account' => [
            'rebalance',
            'rebalancear',
            'realocar carteira',
            'rotate portfolio',
        ],
        'transfer_cash' => [
            'transfer cash',
            'withdraw cash',
            'deposit cash',
            'transferir dinheiro',
            'sacar',
            'depositar',
        ],
        'exercise_option' => [
            'exercise option',
            'exercer opcao',
            'exercer opção',
        ],
        'connect_broker_for_execution' => [
            'connect broker',
            'broker execution',
            'corretora',
            'conectar broker',
            'api da corretora',
        ],
        'publish_investment_advice_as_personal_recommendation' => [
            'personal recommendation',
            'recomendacao personalizada',
            'recomendação personalizada',
            'investment advice',
            'aconselhamento de investimento',
        ],
    ];

    /**
     * @return array<int,string>
     */
    public function blockedActions(string $requestedAction): array
    {
        $normalized = $this->normalize($requestedAction);
        if ($normalized === '') {
            return [];
        }

        $blocked = [];
        foreach (AtlasFinanceDomainContract::FORBIDDEN_MARKET_ACTIONS as $action) {
            if ($this->matchesCanonicalAction($normalized, $action) || $this->matchesPatterns($normalized, self::ACTION_PATTERNS[$action] ?? [])) {
                $blocked[] = $action;
            }
        }

        return array_values(array_unique($blocked));
    }

    private function matchesCanonicalAction(string $requestedAction, string $action): bool
    {
        return str_contains($requestedAction, $action)
            || str_contains($requestedAction, str_replace('_', ' ', $action));
    }

    /**
     * @param  array<int,string>  $patterns
     */
    private function matchesPatterns(string $requestedAction, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains($requestedAction, $this->normalize($pattern))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?: ''));
    }
}
