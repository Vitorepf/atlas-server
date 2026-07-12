<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

/**
 * ASI-12 — derive the actor/origin label for a usage row from the writer's
 * context. Provider-safe: labels are short taxonomy strings, never raw ids
 * from user input.
 *
 * Vocabulary follows the plan:
 *   `interactive:{session}` · `autonomos:{worker}` · `brain` · `watchdog` ·
 *   `unknown` (fallback — never fabricated)
 */
final class AtlasMemoryActorTagger
{
    public const ACTOR_UNKNOWN = 'unknown';

    public const ACTOR_BRAIN = 'brain';

    public const ACTOR_WATCHDOG = 'watchdog';

    public const PREFIX_INTERACTIVE = 'interactive';

    public const PREFIX_AUTONOMOS = 'autonomos';

    /**
     * @param  array<string,mixed>  $context  writer-supplied hints (session_id, worker, created_by, provider, source).
     */
    public function derive(array $context): string
    {
        $explicit = $this->trim($context['actor'] ?? null);
        if ($explicit !== '') {
            return $this->truncate($explicit);
        }

        $createdBy = $this->trim($context['created_by'] ?? null);
        $source = $this->trim($context['source'] ?? null);
        $worker = $this->trim($context['worker'] ?? null);
        $session = $this->trim($context['session_id'] ?? null);

        // Explicit background writers.
        if ($worker !== '' || in_array($createdBy, ['atlas_autonomos', 'atlas_task_serving', 'atlas_loop', 'autonomos_landing'], true)) {
            $ref = $worker !== '' ? $worker : ($createdBy !== '' ? $createdBy : 'unknown');

            return $this->truncate(self::PREFIX_AUTONOMOS.':'.$ref);
        }
        if (in_array($createdBy, ['atlas_brain', 'atlas_open_brain', 'brain_originator'], true)) {
            return self::ACTOR_BRAIN;
        }
        if (in_array($createdBy, ['atlas_watchdog', 'wdg', 'watchdog'], true) || $source === 'atlas_watchdog') {
            return self::ACTOR_WATCHDOG;
        }

        // Interactive: session-bound recall through the operator surface.
        if ($session !== '') {
            return $this->truncate(self::PREFIX_INTERACTIVE.':'.$session);
        }

        return self::ACTOR_UNKNOWN;
    }

    private function trim(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function truncate(string $label): string
    {
        return mb_substr($label, 0, 120);
    }
}
