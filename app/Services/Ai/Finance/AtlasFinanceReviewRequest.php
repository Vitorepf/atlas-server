<?php

namespace App\Services\Ai\Finance;

class AtlasFinanceReviewRequest
{
    private function __construct(
        public readonly ?string $subject,
        public readonly ?string $timeHorizon,
        public readonly string $jurisdiction,
        public readonly ?string $portfolioRef,
        public readonly ?string $requestedAction,
        public readonly bool $humanApprovalRequired,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     */
    public static function fromOptions(array $options): self
    {
        return new self(
            subject: self::nullableString($options['subject'] ?? null),
            timeHorizon: self::nullableString($options['time_horizon'] ?? null),
            jurisdiction: self::nullableString($options['jurisdiction'] ?? null) ?: 'unspecified',
            portfolioRef: self::nullableString($options['portfolio_ref'] ?? null),
            requestedAction: self::nullableString($options['requested_action'] ?? null),
            humanApprovalRequired: (bool) ($options['human_approval_required'] ?? false),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function operatorOptions(): array
    {
        return [
            'subject' => $this->subject,
            'time_horizon' => $this->timeHorizon,
            'jurisdiction' => $this->jurisdiction,
            'portfolio_ref' => $this->portfolioRef,
            'requested_action' => $this->requestedAction,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function scopePayload(string $flow): array
    {
        return [
            'flow' => $flow,
            'subject' => $this->subject,
            'time_horizon' => $this->timeHorizon,
            'jurisdiction' => $this->jurisdiction,
            'portfolio_ref' => $this->portfolioRef,
            'requested_action' => $this->requestedAction,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
