<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Gate;

/**
 * Shared byte-identical helper(s) de-duplicated across this family (aggregateStatus, safe).
 */
trait VoxGateStatusHelper
{
    private function aggregateStatus(array $checks): string
    {
        $hasFail = false;
        $hasWarn = false;
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? self::STATUS_FAIL);
            if ($status === self::STATUS_FAIL) {
                $hasFail = true;
            } elseif ($status === self::STATUS_WARN) {
                $hasWarn = true;
            }
        }
        if ($hasFail) {
            return self::STATUS_FAIL;
        }
        if ($hasWarn) {
            return self::STATUS_WARN;
        }

        return self::STATUS_PASS;
    }

    private function safe(string $checkId, callable $closure): array
    {
        try {
            $body = $closure();
        } catch (\Throwable $e) {
            return [
                'check' => $checkId,
                'status' => self::STATUS_FAIL,
                'message' => 'Check explodiu: '.$e->getMessage(),
                'details' => ['exception_class' => $e::class],
            ];
        }
        $body['check'] = $checkId;
        $body['status'] ??= self::STATUS_WARN;
        $body['message'] ??= '';
        $body['details'] ??= [];

        return $body;
    }
}
