<?php

declare(strict_types=1);

namespace App\Domain\Inbox;

final class InboxIngest
{
    /** @param array{capture_id:string,body:string} $payload
     *  @return array{capture_id:string,body:string,received_at_epoch:int}
     */
    public static function produce(array $payload): array
    {
        return [
            'capture_id' => $payload['capture_id'],
            'body' => trim($payload['body']),
            'received_at_epoch' => 1_700_000_000,
        ];
    }
}
