<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * @deprecated Remove after the M3 compatibility cycle once AcosMax envelope
 *             FQCNs have drained from queues and deployed workers.
 */
final class AcosMaxNamespaceAlias
{
    /** @var array<class-string, class-string> */
    private const ENVELOPE_CLASS_MAP = [
        'App\\Services\\Ai\\AcosMax\\OutcomeEnvelope' => 'App\\Services\\Ai\\Aemor\\Envelope\\OutcomeEnvelope',
        'App\\Services\\Ai\\AcosMax\\OutcomeEnvelopeAdapter' => 'App\\Services\\Ai\\Aemor\\Envelope\\OutcomeEnvelopeAdapter',
        'App\\Services\\Ai\\AcosMax\\OutcomeEnvelopeBridge' => 'App\\Services\\Ai\\Aemor\\Envelope\\OutcomeEnvelopeBridge',
        'App\\Services\\Ai\\AcosMax\\AemorOutcomeEnvelopeAdapter' => 'App\\Services\\Ai\\Aemor\\Envelope\\AemorOutcomeEnvelopeAdapter',
        'App\\Services\\Ai\\AcosMax\\CompoundingOutcomeEnvelopeAdapter' => 'App\\Services\\Ai\\Aemor\\Envelope\\CompoundingOutcomeEnvelopeAdapter',
        'App\\Services\\Ai\\AcosMax\\DevProceduralOutcomeEnvelopeAdapter' => 'App\\Services\\Ai\\Aemor\\Envelope\\DevProceduralOutcomeEnvelopeAdapter',
    ];

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        spl_autoload_register(static function (string $class): void {
            $canonical = self::ENVELOPE_CLASS_MAP[$class] ?? null;
            if ($canonical === null || (! class_exists($canonical) && ! interface_exists($canonical))) {
                return;
            }

            class_alias($canonical, $class);
        }, true, true);
    }
}
