<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution;

final class AtlasLoopAutopoieticConstitutionRegistry
{
    public const UNKNOWN_CATEGORY_SENTINEL = 'forbidden';

    /**
     * @var array{
     *   forbidden_scopes:list<string>,
     *   mandatory_operator_approval_thresholds:array<string,string>
     * }
     */
    private const RULES = [
        'forbidden_scopes' => [
            'app/Services/Ai/MarketingDomain',
            'app/Services/Ai/Aaeos',
            'app/Services/Ai/Forge',
            'atlas-desktop',
            '.env',
            'vendor',
            'secrets',
        ],
        'mandatory_operator_approval_thresholds' => [
            'cross_boundary_wiring' => 'explicit',
            'financial_action' => 'two-step',
            'new_domain' => 'two-step',
            'new_provider' => 'explicit',
        ],
    ];

    /** @var array{forbidden_scopes:list<string>,mandatory_operator_approval_thresholds:array<string,string>} */
    private readonly array $rules;

    public function __construct()
    {
        $this->rules = $this->canonicalRules(self::RULES);
    }

    /**
     * @return list<string>
     */
    public function forbiddenScopes(): array
    {
        return array_values($this->rules['forbidden_scopes']);
    }

    public function approvalThresholdFor(string $category): string
    {
        $category = trim($category);

        return $this->rules['mandatory_operator_approval_thresholds'][$category] ?? self::UNKNOWN_CATEGORY_SENTINEL;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->rules(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{forbidden_scopes:list<string>,mandatory_operator_approval_thresholds:array<string,string>}
     */
    public function rules(): array
    {
        return [
            'forbidden_scopes' => $this->forbiddenScopes(),
            'mandatory_operator_approval_thresholds' => $this->rules['mandatory_operator_approval_thresholds'],
        ];
    }

    /**
     * @param  array{forbidden_scopes:list<string>,mandatory_operator_approval_thresholds:array<string,string>}  $rules
     * @return array{forbidden_scopes:list<string>,mandatory_operator_approval_thresholds:array<string,string>}
     */
    private function canonicalRules(array $rules): array
    {
        $forbiddenScopes = array_values(array_unique(array_map('strval', $rules['forbidden_scopes'])));
        sort($forbiddenScopes, SORT_STRING);

        $thresholds = $rules['mandatory_operator_approval_thresholds'];
        ksort($thresholds, SORT_STRING);

        return [
            'forbidden_scopes' => $forbiddenScopes,
            'mandatory_operator_approval_thresholds' => $thresholds,
        ];
    }
}
