<?php

namespace App\Support;

use Illuminate\Support\Str;

class BehaviorCategories
{
    public const CANONICAL = [
        'substancias',
        'alimentacao',
        'sono_ritmo',
        'treino_movimento',
        'recuperacao',
        'digital',
        'trabalho_cognicao',
        'relacional',
        'saude_sintoma',
        'ambiente_rotina',
        'outro',
    ];

    public const LEGACY_ALIASES = [
        'bebida' => 'substancias',
        'suplemento' => 'substancias',
        'conflito' => 'relacional',
        'social' => 'relacional',
        'sono' => 'sono_ritmo',
        'treino' => 'treino_movimento',
        'trabalho' => 'trabalho_cognicao',
        'saude' => 'saude_sintoma',
    ];

    public static function allowed(): array
    {
        return array_values(array_unique([
            ...self::CANONICAL,
            ...array_keys(self::LEGACY_ALIASES),
        ]));
    }

    public static function canonicalize(?string $category): string
    {
        $category = preg_replace(
            '/_+/',
            '_',
            Str::of((string) $category)
                ->trim()
                ->lower()
                ->ascii()
                ->replace(['/', ' ', '-'], '_')
                ->toString(),
        ) ?? '';

        if ($category === '') {
            return 'outro';
        }

        if (in_array($category, self::CANONICAL, true)) {
            return $category;
        }

        return self::LEGACY_ALIASES[$category] ?? 'outro';
    }

    public static function allowedSqlList(): string
    {
        return collect(self::allowed())
            ->map(fn (string $category): string => "'{$category}'")
            ->implode(', ');
    }
}
