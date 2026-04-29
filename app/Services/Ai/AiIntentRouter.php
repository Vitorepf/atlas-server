<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

class AiIntentRouter
{
    /**
     * @return array{agent:string,intent:string}
     */
    public function route(string $input, ?string $requestedAgent = null): array
    {
        if (is_string($requestedAgent) && trim($requestedAgent) !== '') {
            return [
                'agent' => trim($requestedAgent),
                'intent' => 'operator_selected',
            ];
        }

        $text = Str::lower($input);

        $rules = [
            'saude' => [
                'saude', 'sono', 'hrv', 'batimento', 'energia', 'mood', 'treino',
                'prontidao', 'healthkit', 'recuperacao', 'fadiga', 'corpo',
            ],
            'financas' => [
                'dinheiro', 'financa', 'financeiro', 'investimento', 'comprar',
                'custo', 'preco', 'preço', 'caixa', 'capital', 'reserva',
            ],
            'blackink' => [
                'blackink', 'cliente', 'saas', 'bug', 'produto', 'deploy',
                'codigo', 'código', 'arquitetura', 'roadmap', 'suporte',
            ],
            'vault-curador' => [
                'obsidian', 'vault', 'nota', 'livro', 'conceito', 'modelo mental',
                'principio', 'princípio', 'memoria semantica', 'memória semântica',
            ],
        ];

        foreach ($rules as $agent => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return [
                        'agent' => $agent,
                        'intent' => "keyword:{$needle}",
                    ];
                }
            }
        }

        return [
            'agent' => (string) config('atlas.ai.default_agent', 'orquestrador'),
            'intent' => 'general',
        ];
    }
}
