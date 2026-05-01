<?php

namespace App\Services\Ai;

use RuntimeException;

class AiProviderChoiceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notAwaitingChoice(): self
    {
        return new self('NOT_AWAITING_CHOICE', 'Job is not waiting for an operator choice.');
    }

    public static function optionNotFound(string $optionId): self
    {
        return new self('OPTION_NOT_FOUND', "Option [{$optionId}] not found in choice_options.");
    }
}
