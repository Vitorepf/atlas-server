<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

final class AtlasCognitiveFunctionDecomposerServiceSupport
{
    /**
     * @param  list<string>  $functions
     * @param  array<string, array<int,string>>  $rules
     * @return array<string,int>
     */
    public function scoreRuleHits(array $functions, array $rules, string $normalized): array
    {
        $hits = array_fill_keys($functions, 0);
        foreach ($rules as $axis => $keywords) {
            foreach ($keywords as $kw) {
                $needle = $this->normalize($kw);
                if ($needle === '') {
                    continue;
                }
                if (str_contains($normalized, $needle)) {
                    $hits[$axis]++;
                }
            }
        }

        return $hits;
    }

    public function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        // Strip accents.
        $accent = ['á', 'à', 'â', 'ã', 'ä', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü', 'ç', 'ñ'];
        $plain = ['a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'c', 'n'];

        return str_replace($accent, $plain, $s);
    }
}
