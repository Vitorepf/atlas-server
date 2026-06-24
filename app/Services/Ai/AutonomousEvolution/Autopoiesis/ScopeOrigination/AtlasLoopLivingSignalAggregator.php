<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination;

use Carbon\CarbonInterval;
use DateTimeImmutable;
use DateTimeInterface;

final class AtlasLoopLivingSignalAggregator
{
    public function __construct(
        private readonly object|array|null $operatorIntent = null,
        private readonly object|array|null $cortexMeaning = null,
        private readonly object|array|null $maestroOutcomes = null,
        private readonly object|array|null $loopTelemetry = null,
        private readonly mixed $clock = null,
    ) {}

    public function aggregate(CarbonInterval $window): LivingSignalSnapshot
    {
        $windowEnd = $this->now();
        $windowStart = $windowEnd->sub($window);

        $sources = [
            'operator_intent' => $this->facts($this->operatorIntent, $windowStart, $windowEnd),
            'cortex_meaning' => $this->facts($this->cortexMeaning, $windowStart, $windowEnd),
            'maestro_outcomes' => $this->facts($this->maestroOutcomes, $windowStart, $windowEnd),
            'loop_telemetry' => $this->facts($this->loopTelemetry, $windowStart, $windowEnd),
        ];

        $sourceStatus = [];
        foreach ($sources as $name => $facts) {
            $sourceStatus[$name] = $facts === [] ? 'empty' : 'present';
        }

        $coherence = in_array('empty', $sourceStatus, true)
            ? null
            : $this->coherence($sources);

        return new LivingSignalSnapshot([
            'coherence' => $coherence,
            'source_status' => $sourceStatus,
            'sources' => $sources,
            'window_end' => $windowEnd->format(DATE_ATOM),
            'window_start' => $windowStart->format(DATE_ATOM),
        ]);
    }

    /**
     * @param  object|array|null  $source
     * @return list<array<string,mixed>>
     */
    private function facts(object|array|null $source, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
    {
        $facts = [];

        if (is_array($source)) {
            $facts = $source;
        } elseif (is_object($source)) {
            foreach (['facts', 'withinWindow', 'read', 'recent'] as $method) {
                if (! method_exists($source, $method)) {
                    continue;
                }

                $result = $source->{$method}($windowStart->format(DATE_ATOM), $windowEnd->format(DATE_ATOM));
                $facts = is_array($result) ? $result : [];
                break;
            }
        }

        $normalized = [];
        foreach ($facts as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $normalized[] = $this->canonicalize($fact);
        }

        return array_values($normalized);
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $sources
     */
    private function coherence(array $sources): ?float
    {
        $symbolSets = [];
        foreach ($sources as $facts) {
            $symbols = [];
            foreach ($facts as $fact) {
                foreach ($this->symbols($fact) as $symbol) {
                    $symbols[$symbol] = true;
                }
            }

            if ($symbols === []) {
                return null;
            }

            $symbolSets[] = array_keys($symbols);
        }

        if ($symbolSets === []) {
            return null;
        }

        $intersection = array_shift($symbolSets) ?? [];
        $union = $intersection;

        foreach ($symbolSets as $set) {
            $intersection = array_values(array_intersect($intersection, $set));
            $union = array_values(array_unique(array_merge($union, $set)));
        }

        if ($union === []) {
            return null;
        }

        return round(count($intersection) / count($union), 6);
    }

    /**
     * @param  array<string,mixed>  $fact
     * @return list<string>
     */
    private function symbols(array $fact): array
    {
        $symbols = [];

        foreach (['symbol', 'symbols', 'cited_symbols', 'references'] as $key) {
            $value = $fact[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $symbols[] = trim($value);
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $symbols[] = trim($item);
                    }
                }
            }
        }

        $symbols = array_values(array_unique($symbols));
        sort($symbols);

        return $symbols;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $value
     * @return array<string, mixed>|list<mixed>
     */
    private function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                if (is_array($item)) {
                    $value[$index] = $this->canonicalize($item);
                }
            }

            return $value;
        }

        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }

    private function now(): DateTimeImmutable
    {
        $value = is_callable($this->clock) ? ($this->clock)() : 'now';

        return $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable(is_string($value) ? $value : 'now');
    }
}
