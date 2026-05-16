<?php

declare(strict_types=1);

namespace App\Domain\Captures\Format;

final class CaptureFormatter
{
    /** @param array{capture_id:string,body:string,received_at_epoch:int} $input */
    public static function format(array $input): string
    {
        return $input['capture_id'].'|'.$input['body'].'|'.$input['received_at_epoch'];
    }
}
