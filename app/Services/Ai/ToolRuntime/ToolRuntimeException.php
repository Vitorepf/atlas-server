<?php

namespace App\Services\Ai\ToolRuntime;

use RuntimeException;

class ToolRuntimeException extends RuntimeException
{
    public static function duplicateTool(string $toolId): self
    {
        return new self("Tool with tool_id [{$toolId}] already exists; tool_id is globally unique.");
    }

    public static function unknownTool(string $toolId): self
    {
        return new self("Tool [{$toolId}] not found in registry.");
    }

    public static function capabilityExists(string $toolId, string $capabilityId): self
    {
        return new self("Capability [{$capabilityId}] already exists for tool [{$toolId}].");
    }

    public static function invalidEnum(string $field, string $value, array $allowed): self
    {
        return new self("invalid {$field} [{$value}]; allowed: ".implode(',', $allowed));
    }

    public static function missingField(string $toolId, string $field): self
    {
        return new self("Tool [{$toolId}] missing required field [{$field}].");
    }

    public static function denied(string $toolId, string $reason): self
    {
        return new self("Tool [{$toolId}] denied by policy: {$reason}");
    }

    public static function externalActionBlocked(string $toolId): self
    {
        return new self("Tool [{$toolId}] requires authority_group beyond read_only/local_mutation/draft and was not approved; Tool Runtime does not execute external actions without policy allow.");
    }
}
