<?php

namespace App\Logging;

use App\Support\AtlasSecurity;
use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Logger;
use Monolog\LogRecord;

class RedactSecretsFromLogs
{
    public function __invoke(IlluminateLogger|Logger $logger): void
    {
        if ($logger instanceof IlluminateLogger) {
            $logger = $logger->getLogger();
        }

        $logger->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(
                message: AtlasSecurity::redactString($record->message),
                context: AtlasSecurity::redactArray($record->context),
                extra: AtlasSecurity::redactArray($record->extra),
            );
        });
    }
}
