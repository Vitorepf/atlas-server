<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMission;
use Illuminate\Support\Str;

class MissionFactoryService
{
    public const TYPE_TRIVIAL = 'trivial';

    public const TYPE_TASK = 'task';

    public const TYPE_MISSION = 'mission';

    public const TYPE_OBRA = 'obra';

    public const AUTONOMY_SUGGEST = 'suggest';

    public const AUTONOMY_DRAFT = 'draft';

    public const AUTONOMY_EXECUTE_WITH_APPROVAL = 'execute_with_approval';

    public const AUTONOMY_AUTONOMOUS = 'autonomous';

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    public const RISK_CRITICAL = 'critical';

    public function __construct(private readonly MissionLifecycleService $lifecycle) {}

    /**
     * @param  array<string,mixed>  $options
     */
    public function create(string $rawPrompt, array $options = []): AiMission
    {
        $rawPrompt = trim($rawPrompt);
        if ($rawPrompt === '') {
            throw new \InvalidArgumentException('raw_prompt cannot be empty.');
        }

        $normalized = $this->normalizeIntent($rawPrompt);
        $missionType = (string) ($options['mission_type'] ?? $this->classify($rawPrompt));
        $autonomy = (string) ($options['autonomy_level'] ?? self::AUTONOMY_SUGGEST);
        $risk = (string) ($options['risk_level'] ?? $this->inferRisk($missionType));
        $title = (string) ($options['title'] ?? Str::limit($rawPrompt, 120, ''));

        $definitionOfDone = $this->initialDefinitionOfDone($rawPrompt, $options);

        $mission = AiMission::query()->create([
            'uuid' => (string) Str::uuid(),
            'title' => $title,
            'raw_prompt' => $rawPrompt,
            'normalized_intent' => $normalized,
            'mission_type' => $missionType,
            'status' => MissionLifecycleService::STATUS_DRAFT,
            'autonomy_level' => $autonomy,
            'risk_level' => $risk,
            'definition_of_done' => $definitionOfDone,
            'context_summary' => $options['context_summary'] ?? null,
            'primary_domain' => $options['primary_domain'] ?? null,
            'secondary_domains' => $options['secondary_domains'] ?? null,
        ]);

        $this->lifecycle->recordEvent(
            $mission,
            'mission.created',
            (string) ($options['actor_type'] ?? 'system'),
            [
                'mission_type' => $missionType,
                'autonomy_level' => $autonomy,
                'risk_level' => $risk,
                'normalized_intent' => $normalized,
                'has_definition_of_done' => $definitionOfDone['criteria'] !== [],
            ],
            null,
            MissionLifecycleService::STATUS_DRAFT,
        );

        return $mission;
    }

    public function classify(string $rawPrompt): string
    {
        $text = $this->normalizeIntent($rawPrompt);
        $length = mb_strlen($text);

        if ($length === 0) {
            return self::TYPE_TRIVIAL;
        }

        if (str_contains($text, 'obra') || str_contains($text, 'epico') || str_contains($text, 'epic ')) {
            return self::TYPE_OBRA;
        }

        $bulletCount = substr_count($text, "\n- ") + substr_count($text, "\n* ") + substr_count($text, ';');
        $conjunctions = preg_match_all('/\b(e tambem|tambem|alem disso|depois|por fim| and |;| e )\b/u', $text);
        $actionVerbs = preg_match_all('/\b(implementar|criar|adicionar|build|implement|refatorar|migrar|integrar|deploy|publicar|escrever|gerar|testar|certificar|orquestrar)\b/u', $text);

        if ($bulletCount >= 2 || $conjunctions >= 2) {
            return self::TYPE_MISSION;
        }

        if ($length <= 80 && $actionVerbs === 0 && ! str_ends_with(rtrim($text, '. '), '?')) {
            return self::TYPE_TRIVIAL;
        }

        if (str_ends_with(rtrim($text, '. '), '?') && $actionVerbs === 0 && $length <= 200) {
            return self::TYPE_TRIVIAL;
        }

        if ($actionVerbs >= 1 && $length <= 200) {
            return self::TYPE_TASK;
        }

        return self::TYPE_MISSION;
    }

    public function normalizeIntent(string $prompt): string
    {
        $lower = mb_strtolower(trim($prompt));
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        if ($normalized === false) {
            $normalized = $lower;
        }

        return preg_replace('/\s+/u', ' ', $normalized) ?? $lower;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function initialDefinitionOfDone(string $rawPrompt, array $options): array
    {
        $base = [
            'version' => 'atlas.ai.mission.definition_of_done.v1',
            'primary_metric' => $options['primary_metric'] ?? null,
            'secondary_metrics' => array_values((array) ($options['secondary_metrics'] ?? [])),
            'constraints' => array_values((array) ($options['constraints'] ?? [])),
            'assumptions' => array_values((array) ($options['assumptions'] ?? [])),
            'criteria' => [],
        ];

        $criteria = $options['definition_of_done']['criteria'] ?? $options['criteria'] ?? null;
        if (is_array($criteria) && $criteria !== []) {
            $base['criteria'] = array_values(array_map(
                static fn ($criterion) => is_string($criterion)
                    ? ['description' => $criterion, 'checked' => false, 'evidence_ref' => null]
                    : $criterion,
                $criteria,
            ));
        } else {
            $base['criteria'] = [[
                'description' => 'Deliver the request: '.Str::limit($rawPrompt, 200, ''),
                'checked' => false,
                'evidence_ref' => null,
            ]];
        }

        return $base;
    }

    private function inferRisk(string $missionType): string
    {
        return match ($missionType) {
            self::TYPE_OBRA => self::RISK_HIGH,
            self::TYPE_MISSION => self::RISK_MEDIUM,
            default => self::RISK_LOW,
        };
    }
}
