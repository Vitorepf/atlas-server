<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfModel\Corpus;

final class AtlasLoopOutcomeCorpusBuilder
{
    /**
     * @param  list<array<string,mixed>>  $deliveries
     * @return array{schema:string,examples:list<array{prompt_context:string,action:string,label:string}>,positive_count:int,negative_count:int,corpus_hash:string}
     */
    public function build(array $deliveries): array
    {
        $examples = [];
        foreach ($deliveries as $delivery) {
            $shape = trim((string) ($delivery['shape_token'] ?? ''));
            $action = trim((string) ($delivery['action_summary'] ?? ''));
            if ($shape === '' || $action === '') {
                continue;
            }

            $examples[] = [
                'prompt_context' => $this->redact($this->promptContext($delivery, $shape)),
                'action' => $this->redact($action),
                'label' => $this->label($delivery),
            ];
        }

        usort($examples, static function (array $a, array $b): int {
            return strcmp((string) $a['prompt_context'], (string) $b['prompt_context'])
                ?: strcmp((string) $a['action'], (string) $b['action'])
                ?: strcmp((string) $a['label'], (string) $b['label']);
        });

        $positive = count(array_filter($examples, static fn (array $example): bool => $example['label'] === 'positive'));
        $negative = count($examples) - $positive;

        return [
            'schema' => 'atlas.loop.self_model.corpus.v1',
            'examples' => $examples,
            'positive_count' => $positive,
            'negative_count' => $negative,
            'corpus_hash' => hash('sha256', $this->canonicalJson($examples)),
        ];
    }

    /**
     * @param  array<string,mixed>  $delivery
     */
    private function promptContext(array $delivery, string $shape): string
    {
        return implode("\n", [
            'objective_class: '.trim((string) ($delivery['objective_class'] ?? 'unknown')),
            'shape_token: '.$shape,
            'provider: '.trim((string) ($delivery['provider'] ?? 'unknown')),
            'net_behavior_delta: '.(string) ($delivery['net_behavior_delta'] ?? 0),
        ]);
    }

    /**
     * @param  array<string,mixed>  $delivery
     */
    private function label(array $delivery): string
    {
        $certified = ($delivery['certified'] ?? false) === true;
        $delta = (float) ($delivery['net_behavior_delta'] ?? 0);

        return $certified && $delta > 0 ? 'positive' : 'negative';
    }

    private function redact(string $value): string
    {
        return (string) preg_replace('/\S*(?:sk-|token|password|api_key)\S*/i', '[REDACTED]', $value);
    }

    /**
     * @param  array<int|string,mixed>  $value
     */
    private function canonicalJson(array $value): string
    {
        return json_encode($this->sortKeys($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<int|string,mixed>  $value
     * @return array<int|string,mixed>
     */
    private function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortKeys($item);
            }
        }

        return $value;
    }
}
