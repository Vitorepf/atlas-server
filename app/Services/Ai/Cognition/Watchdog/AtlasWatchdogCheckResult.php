<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog;

use InvalidArgumentException;

final readonly class AtlasWatchdogCheckResult
{
    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    public const STATUS_ALERT = 'alert';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_ERROR = 'error';

    public const FIELD_ALERT = 'alert';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_OK,
        self::STATUS_WARNING,
        self::STATUS_ALERT,
        self::STATUS_SKIPPED,
        self::STATUS_ERROR,
    ];

    /**
     * @param  array<string,mixed>  $evidence
     * @param  array<string,mixed>|null  $alert
     */
    public function __construct(
        public string $status,
        public array $evidence = [],
        public ?array $alert = null,
    ) {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid watchdog check status: '.$this->status);
        }
    }

    /** @param array<string,mixed> $evidence */
    public static function ok(array $evidence = []): self
    {
        return new self(self::STATUS_OK, $evidence);
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @param  array<string,mixed>|null  $alert
     */
    public static function warning(array $evidence = [], ?array $alert = null): self
    {
        return new self(self::STATUS_WARNING, $evidence, $alert);
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @param  array<string,mixed>  $alert
     */
    public static function alert(array $evidence = [], array $alert = []): self
    {
        return new self(self::STATUS_ALERT, $evidence, $alert);
    }

    /** @param array<string,mixed> $evidence */
    public static function skipped(array $evidence = []): self
    {
        return new self(self::STATUS_SKIPPED, $evidence);
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @param  array<string,mixed>  $alert
     */
    public static function error(array $evidence = [], array $alert = []): self
    {
        return new self(self::STATUS_ERROR, $evidence, $alert);
    }

    /** @return array{status:string,evidence:array<string,mixed>,alert:array<string,mixed>|null} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'evidence' => $this->evidence,
            self::FIELD_ALERT => $this->alert,
        ];
    }
}
