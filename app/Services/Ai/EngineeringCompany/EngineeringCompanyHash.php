<?php

namespace App\Services\Ai\EngineeringCompany;

class EngineeringCompanyHash
{
    /**
     * @param  mixed  $value
     */
    public static function make($value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
