<?php

declare(strict_types=1);

namespace App\Domain\Inbox;

use App\Domain\Captures\CaptureRepository;

final class InboxLeak2
{
    public function __construct(private readonly CaptureRepository $repo) {}
}
