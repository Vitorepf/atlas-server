<?php

namespace App\Services\Ai\Rivals\Core;

use RuntimeException;

/** Loads the operator-authorized Verboo secret without ever serializing it. */
final class VerbooEnvironment
{
    public const BASE_URL = 'https://code.verboo.ai/router/v1';

    /** @return array<string, string> */
    public function processEnvironment(): array
    {
        $key = $this->apiKey();
        $environment = array_filter(
            getenv(),
            fn (mixed $value): bool => is_string($value),
        );
        $home = rtrim((string) ($environment['HOME'] ?? getenv('HOME') ?: ''), '/');
        $pathPrefix = implode(PATH_SEPARATOR, array_filter([
            $home !== '' ? $home.'/.local/bin' : null,
            '/opt/homebrew/bin',
            '/usr/local/bin',
        ]));
        $path = $pathPrefix.PATH_SEPARATOR.((string) ($environment['PATH'] ?? getenv('PATH') ?: '/usr/bin:/bin'));

        return array_merge($environment, [
            'PATH' => $path,
            'VERBOO_API_KEY' => $key,
            'OPENAI_API_KEY' => $key,
            // Harbor verifier.env for some suites (e.g. swe_marathon) requires this name.
            'ANTHROPIC_API_KEY' => $key,
            'OPENAI_BASE_URL' => self::BASE_URL,
            'OPENAI_API_BASE' => self::BASE_URL,
            'REMOTE_OPENAI_API_KEY' => $key,
            'REMOTE_OPENAI_BASE_URL' => self::BASE_URL,
            'AIDER_DOCKER' => '1',
            'SSB_OVERRIDE_VA_MODEL' => 'openai/kimi-k2.7',
            'SSB_OVERRIDE_ALL_JUDGE_MODEL' => 'openai/kimi-k2.7',
            'SSB_OVERRIDE_CLASSIFIER_MODEL' => 'openai/kimi-k2.7',
            'PYTHONPATH' => base_path('scripts').PATH_SEPARATOR.($environment['PYTHONPATH'] ?? ''),
            'WANDB_MODE' => 'offline',
            'WANDB_SILENT' => 'true',
            'WEAVE_DISABLED' => 'true',
            'ATLAS_RIVALS_ROOT' => base_path(),
        ]);
    }

    public function available(): bool
    {
        try {
            return $this->apiKey() !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private function apiKey(): string
    {
        $path = rtrim((string) getenv('HOME'), '/').'/.hermes/.env';
        if (! is_file($path)) {
            throw new RuntimeException('rivals_verboo_credentials_missing');
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (! str_starts_with($line, 'VERBOO_API_KEY=')) {
                continue;
            }
            $value = trim(substr($line, strlen('VERBOO_API_KEY=')), " \t\n\r\0\x0B\"'");
            if ($value !== '') {
                return $value;
            }
        }

        throw new RuntimeException('rivals_verboo_credentials_missing');
    }
}
