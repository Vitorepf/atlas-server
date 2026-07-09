<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentResolver;

/**
 * Container binding keys for the intent-ambiguity resolver substrate (Autônomos naming).
 *
 * Survives hard-delete of the deprecated atlas:loop:intent:resolve command.
 */
final class AtlasAutonomosIntentResolverBindings
{
    public const INTENTS_SOURCE_BINDING = 'atlas.loop.intent_resolver.intents_source';
}
