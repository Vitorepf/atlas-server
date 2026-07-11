<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use InvalidArgumentException;

/** Governor-issued, canonical capability for one canary-attributed revert. */
final readonly class AuthorizedRevertAction
{
    public function __construct(
        public AuthorizedMergeAction $action,
        public string $canaryRequestHash,
        public string $originatingLandedEventId,
    ) {
        if ($action->action !== 'revert_task' || $action->canonicalEventId === '' || $action->canonicalEventHash === ''
            || $canaryRequestHash === '' || $originatingLandedEventId === '') {
            throw new InvalidArgumentException('authorized_revert_action_incomplete');
        }
    }
}
