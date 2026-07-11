<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * FEE-06 — remove historical demote votes caused by path-containment on memory refs.
 */
final class AtlasSpuriousMemoryNoisePurgeService
{
    /**
     * @return array{matched:int,purged:int,dry_run:bool}
     */
    public function purge(bool $dryRun = true): array
    {
        if (! DatabaseTableAvailability::has('ai_rag_feedback_events')) {
            return ['matched' => 0, 'purged' => 0, 'dry_run' => $dryRun];
        }

        $matched = 0;
        $purged = 0;

        foreach (AiRagFeedbackEvent::query()->cursor() as $event) {
            $cleaned = $this->cleanEvent($event);
            if ($cleaned === null) {
                continue;
            }

            $matched++;
            if ($dryRun) {
                continue;
            }

            $event->forceFill($cleaned)->save();
            $purged++;
        }

        return ['matched' => $matched, 'purged' => $purged, 'dry_run' => $dryRun];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function cleanEvent(AiRagFeedbackEvent $event): ?array
    {
        $sourceUtility = (array) $event->source_utility;
        $payload = (array) $event->payload;
        $changed = false;

        foreach ($sourceUtility as $ref => $utility) {
            if ((string) $utility === 'noise' && AtlasCanonicalContextRef::isMemoryRef((string) $ref)) {
                unset($sourceUtility[$ref]);
                $changed = true;
            }
        }

        foreach (['context_ref_attribution', 'payload.context_ref_attribution'] as $path) {
            $noiseRefs = data_get($payload, $path.'.noise_refs');
            if (! is_array($noiseRefs)) {
                continue;
            }

            $filtered = array_values(array_filter($noiseRefs, function (mixed $entry): bool {
                if (! is_array($entry)) {
                    return true;
                }

                $ref = (string) ($entry['ref'] ?? '');
                $sourceType = (string) ($entry['source_type'] ?? '');

                return ! ($sourceType === 'memory' || ($ref !== '' && AtlasCanonicalContextRef::isMemoryRef($ref)));
            }));

            if (count($filtered) !== count($noiseRefs)) {
                data_set($payload, $path.'.noise_refs', $filtered);
                data_set($payload, $path.'.noise_count', count($filtered));
                $changed = true;
            }
        }

        foreach (['next_context_policy', 'payload.next_context_policy'] as $path) {
            $demoteRefs = data_get($payload, $path.'.demote_context_refs');
            if (! is_array($demoteRefs)) {
                continue;
            }

            $filtered = array_values(array_filter($demoteRefs, static fn (mixed $ref): bool => ! AtlasCanonicalContextRef::isMemoryRef(is_scalar($ref) ? (string) $ref : '')));
            if (count($filtered) !== count($demoteRefs)) {
                data_set($payload, $path.'.demote_context_refs', $filtered);
                $actions = (array) data_get($payload, $path.'.actions', []);
                if ($filtered === [] && in_array('demote_noise_context_refs', $actions, true)) {
                    data_set($payload, $path.'.actions', array_values(array_filter(
                        $actions,
                        static fn (mixed $action): bool => (string) $action !== 'demote_noise_context_refs',
                    )));
                }
                $changed = true;
            }
        }

        if (! $changed) {
            return null;
        }

        $noiseSources = count(array_filter($sourceUtility, static fn (mixed $utility): bool => (string) $utility === 'noise'));

        return [
            'source_utility' => $sourceUtility,
            'payload' => $payload,
            'noise_sources' => $noiseSources,
        ];
    }
}
