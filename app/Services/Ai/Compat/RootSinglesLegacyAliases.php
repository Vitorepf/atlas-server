<?php

declare(strict_types=1);

namespace App\Services\Ai\Compat;

/**
 * @deprecated Remove after the GOD-DEBULK root-singles compatibility cycle
 *             once deployed workers and queued payloads no longer reference
 *             root-level AI owner names.
 */
final class RootSinglesLegacyAliases
{
    /** @var array<class-string, class-string> */
    private const CLASS_MAP = [
        'App\\Services\\Ai\\AiCouncilCoordinator' => 'App\\Services\\Ai\\Arena\\AiCouncilCoordinator',
        'App\\Services\\Ai\\AiIntentRouter' => 'App\\Services\\Ai\\Router\\AiIntentRouter',
        'App\\Services\\Ai\\AiQualityActionService' => 'App\\Services\\Ai\\Analysis\\AiQualityActionService',
        'App\\Services\\Ai\\AiQualityEvaluator' => 'App\\Services\\Ai\\Analysis\\AiQualityEvaluator',
        'App\\Services\\Ai\\AiSkill' => 'App\\Services\\Ai\\Skills\\AiSkill',
        'App\\Services\\Ai\\AiSkillStore' => 'App\\Services\\Ai\\Skills\\AiSkillStore',
        'App\\Services\\Ai\\AiStreamRecorder' => 'App\\Services\\Ai\\Streaming\\AiStreamRecorder',
        'App\\Services\\Ai\\CompactionLossPolicy' => 'App\\Services\\Ai\\Compaction\\CompactionLossPolicy',
        'App\\Services\\Ai\\YouTubeKnowledgeIngestionService' => 'App\\Services\\Ai\\Knowledge\\YouTubeKnowledgeIngestionService',
        'App\\Services\\Ai\\YoutubeCanonicalProjection' => 'App\\Services\\Ai\\Knowledge\\YoutubeCanonicalProjection',
    ];

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        spl_autoload_register(static function (string $class): void {
            $canonical = self::CLASS_MAP[$class] ?? null;
            if ($canonical === null || ! class_exists($canonical)) {
                return;
            }

            class_alias($canonical, $class);
        }, true, true);
    }
}

RootSinglesLegacyAliases::register();
