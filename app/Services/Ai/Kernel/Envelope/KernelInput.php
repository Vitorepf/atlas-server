<?php

namespace App\Services\Ai\Kernel\Envelope;

final readonly class KernelInput
{
    /**
     * @param  array<int,array<string,mixed>>  $attachments
     * @param  array<string,mixed>  $hints
     */
    public function __construct(
        public string $primaryType,
        public string $primaryText,
        public array $attachments,
        public array $hints,
        public string $locale,
        public string $inputHash,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $primaryType = self::string($input['primary_type'] ?? 'text') ?: 'text';
        $primaryText = self::string($input['text'] ?? $input['primary_text'] ?? '');
        $attachments = is_array($input['attachments'] ?? null) ? array_values($input['attachments']) : [];
        $hints = is_array($input['hints'] ?? null) ? $input['hints'] : [];
        $locale = self::string($input['locale'] ?? 'pt-BR') ?: 'pt-BR';
        $hash = self::hash([
            'primary_type' => $primaryType,
            'primary_text' => $primaryText,
            'attachments' => $attachments,
            'hints' => $hints,
            'locale' => $locale,
        ]);

        return new self($primaryType, $primaryText, $attachments, $hints, $locale, $hash);
    }

    private static function string(mixed $value): string
    {
        return trim((string) $value);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private static function hash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
