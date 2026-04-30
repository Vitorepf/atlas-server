<?php

namespace App\Http\Requests\Concerns;

use App\Services\AtlasDomainRegistry;
use Illuminate\Validation\Rule;

trait ValidatesAtlasDomain
{
    /**
     * @return array<int, mixed>
     */
    protected function atlasDomainRule(bool $required = true): array
    {
        $rule = Rule::in(app(AtlasDomainRegistry::class)->activeSlugs());

        return $required ? ['required', $rule] : ['sometimes', $rule];
    }
}
