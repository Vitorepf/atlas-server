<?php

namespace App\Services\Ai\Kernel\Architecture;

use App\Models\AtlasStrategyRivalsCase;
use App\Models\AtlasStrategyRivalsReview;
use App\Services\Ai\Support\AiStringListNormalizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AtlasRivalsStrategyCaseRegistrar
{
    public const SCHEMA_VERSION = 'atlas.rivals_strategy.case_registration.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function register(array $input): array
    {
        $title = $this->string($input['title'] ?? '');
        if ($title === '') {
            throw new InvalidArgumentException('Rivals Strategy case title is required.');
        }

        $horizonDays = max(1, min(3650, (int) ($input['horizon_days'] ?? $input['horizon'] ?? 90)));
        $sourceHash = $this->sourceHash($input, $title, $horizonDays);
        $reviewHorizons = $this->reviewHorizons($input['review_horizons_days'] ?? [30, 90, 180, 365]);

        return DB::transaction(function () use ($input, $title, $horizonDays, $sourceHash, $reviewHorizons): array {
            $case = AtlasStrategyRivalsCase::query()
                ->where('source_hash', $sourceHash)
                ->first();
            $created = false;

            if (! $case instanceof AtlasStrategyRivalsCase) {
                $case = AtlasStrategyRivalsCase::query()->create([
                    'title' => $title,
                    'decision_domain' => $this->string($input['decision_domain'] ?? 'strategic_decision') ?: 'strategic_decision',
                    'status' => 'active',
                    'decision_made_at' => now(),
                    'baseline_choice' => $this->nullableString($input['baseline_choice'] ?? $input['baseline'] ?? null),
                    'atlas_assisted_choice' => $this->nullableString($input['atlas_assisted_choice'] ?? $input['atlas'] ?? null),
                    'context_summary' => $this->nullableString($input['context_summary'] ?? null),
                    'horizon_days' => $horizonDays,
                    'source_hash' => $sourceHash,
                    'tags_json' => $this->stringList($input['tags'] ?? []),
                    'metrics_json' => is_array($input['metrics'] ?? null) ? $input['metrics'] : [],
                    'metadata' => [
                        'schema_version' => self::SCHEMA_VERSION,
                        'source' => $this->string($input['source'] ?? 'atlas.rivals_strategy'),
                        'mode' => $this->string($input['mode'] ?? 'explicit_registration'),
                        'no_external_action' => true,
                        'operator_approved' => true,
                        'source_envelope_id' => $this->nullableString($input['source_envelope_id'] ?? null),
                        'source_receipt_id' => $this->nullableString($input['source_receipt_id'] ?? null),
                    ],
                ]);
                $created = true;
            }

            foreach ($reviewHorizons as $horizon) {
                AtlasStrategyRivalsReview::query()->firstOrCreate([
                    'case_id' => $case->id,
                    'horizon_days' => $horizon,
                ], [
                    'review_due_at' => now()->addDays($horizon),
                    'status' => 'pending',
                    'metadata' => [
                        'created_by' => 'atlas.rivals_strategy.case_registrar',
                        'case_source_hash' => $sourceHash,
                    ],
                ]);
            }

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'ok',
                'case_id' => $case->id,
                'created' => $created,
                'source_hash' => $sourceHash,
                'scheduled_reviews' => $case->reviews()->count(),
                'review_horizons_days' => $reviewHorizons,
            ];
        });
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function sourceHash(array $input, string $title, int $horizonDays): string
    {
        $explicit = $this->string($input['source_hash'] ?? '');
        if ($explicit !== '') {
            return preg_match('/^[a-f0-9]{64}$/i', $explicit) === 1
                ? strtolower($explicit)
                : hash('sha256', $explicit);
        }

        $source = [
            'title' => $title,
            'baseline' => $this->nullableString($input['baseline_choice'] ?? $input['baseline'] ?? null),
            'atlas' => $this->nullableString($input['atlas_assisted_choice'] ?? $input['atlas'] ?? null),
            'horizon_days' => $horizonDays,
            'decision_domain' => $this->string($input['decision_domain'] ?? 'strategic_decision'),
            'source' => $this->string($input['source'] ?? 'atlas.rivals_strategy'),
            'context_summary' => $this->nullableString($input['context_summary'] ?? null),
        ];
        ksort($source);

        return hash('sha256', json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<int,int>
     */
    private function reviewHorizons(mixed $value): array
    {
        return collect((array) $value)
            ->map(fn (mixed $horizon): int => max(1, min(3650, (int) $horizon)))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        return AiStringListNormalizer::uniqueTruthyTrimmedCastValues($value);
    }

    private function string(mixed $value): string
    {
        return trim((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        $string = $this->string($value);

        return $string !== '' ? $string : null;
    }
}
