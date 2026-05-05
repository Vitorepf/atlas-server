<?php

namespace App\Services\Ai\PersonalDevelopment;

class PersonalDevelopmentSafetyPolicy
{
    /**
     * @var array<int,string>
     */
    private const SENSITIVE_MARKERS = [
        'crisis',
        'crise',
        'diagnosis',
        'diagnostico',
        'diagnóstico',
        'medication',
        'medicacao',
        'medicação',
        'therapy',
        'terapia',
        'trauma',
        'self-harm',
        'autoagressao',
        'autoagressão',
        'suicide',
        'suicidio',
        'suicídio',
        'burnout',
        'esgotamento',
        'depression',
        'depressao',
        'depressão',
        'anxiety',
        'ansiedade',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function sensitivity(array $input): array
    {
        $text = strtolower(json_encode($input, JSON_THROW_ON_ERROR));
        $matched = array_values(array_filter(
            self::SENSITIVE_MARKERS,
            fn (string $marker): bool => str_contains($text, $marker)
        ));

        return [
            'requires_review' => $matched !== [],
            'markers' => $matched,
        ];
    }

    /**
     * @param  array<string,mixed>  $sensitivity
     */
    public function approvalRequired(string $flow, array $sensitivity): bool
    {
        return $flow === 'personal_development.forge' || (bool) ($sensitivity['requires_review'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $sensitivity
     * @return array<int,string>
     */
    public function approvalReasons(string $flow, array $sensitivity): array
    {
        return array_values(array_filter([
            $flow === 'personal_development.forge' ? 'forge_flow_requires_human_approval' : null,
            ($sensitivity['requires_review'] ?? false) ? 'sensitive_personal_recommendation_requires_human_review' : null,
        ]));
    }

    public function runtimeStatus(bool $approvalRequired, bool $humanApproved): string
    {
        return $approvalRequired && ! $humanApproved ? 'needs_human_review' : 'planned';
    }

    /**
     * @return array<string,mixed>
     */
    public function safetyContract(): array
    {
        return [
            'non_clinical' => true,
            'no_medical_treatment' => true,
            'no_psychological_diagnosis' => true,
            'language_style' => ['plan', 'reflection', 'evidence', 'routine', 'experiment'],
            'private_by_default' => true,
            'sensitive_recommendations_require_review' => true,
            'recommendations_are_drafts_until_review' => true,
        ];
    }

    /**
     * @return array<int,string>
     */
    public function blockedActions(): array
    {
        return [
            'calendar_write',
            'task_write',
            'habit_tracker_write',
            'external_publish',
            'clinical_diagnosis',
            'medical_treatment_advice',
        ];
    }

    /**
     * @return array<string,bool>
     */
    public function sideEffects(): array
    {
        return [
            'calendar_mutations' => false,
            'task_mutations' => false,
            'habit_mutations' => false,
            'external_messages' => false,
            'destructive_actions' => false,
        ];
    }
}
