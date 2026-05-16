<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

/**
 * Deny-list for VerificationCommandRunner. Atlas Dev runs validation
 * commands defined in LightTaskContract.validation_commands; we still refuse
 * to spawn anything that mutates the host, exfiltrates secrets, or escalates
 * privileges, even if the contract asked us to.
 *
 * Pure: no I/O, no side effects. Both production and fake runners call it.
 */
final class UnsafeCommandPolicy
{
    public const DANGEROUS_TOKENS = [
        ' rm ',
        ' rm\t',
        'rm -rf',
        'rm -fr',
        ' sudo ',
        ' su ',
        ' chown ',
        'chmod 777',
        ' mkfs',
        ' dd if=',
        ' shutdown',
        ' reboot',
        ' halt',
        '> /dev/sda',
        ':(){:|:&};:',
        ' eval ',
        ' base64 -d',
        'curl http',
        'curl https',
        ' wget ',
        ' scp ',
        ' rsync ',
        ' nc -',
        ' ncat ',
        ' netcat ',
        ' ssh ',
        ' gpg --',
        ' aws s3 ',
        'git push',
        'git reset --hard',
        'git clean -fd',
        'git checkout --',
        'git restore --',
        'docker run',
        'docker compose up',
        'kubectl apply',
        'kubectl delete',
        'kubectl exec',
    ];

    public static function reasonIfUnsafe(string $command): ?string
    {
        // Normalise BEFORE matching so the token list cannot be bypassed by:
        //   - tab / newline / CR separators in place of spaces;
        //   - absolute paths to dangerous binaries (`/usr/bin/sudo`, etc.)
        //     where the space-bounded tokens above used to fail to match.
        $normalised = self::normalise($command);
        $padded = ' '.$normalised.' ';

        foreach (self::DANGEROUS_TOKENS as $token) {
            if (str_contains($padded, strtolower($token))) {
                return 'matched_dangerous_token:'.trim($token);
            }
        }

        return null;
    }

    /**
     * Lower-case, collapse runs of whitespace (incl. tab/newline/CR) into a
     * single space, then walk the resulting tokens stripping any leading
     * `/path/to/` prefix so `/usr/bin/sudo` reduces to `sudo` for matching.
     *
     * The original raw command is preserved by the caller for the reason
     * message; this normalisation is only used to drive the matcher.
     */
    private static function normalise(string $command): string
    {
        $lower = strtolower($command);
        $collapsed = preg_replace('/\s+/u', ' ', $lower);
        if (! is_string($collapsed)) {
            $collapsed = $lower;
        }

        $words = explode(' ', trim($collapsed));
        $stripped = [];
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            // Keep the original token, then add the basename for path-prefixed
            // binaries: `./bin/aws s3` becomes `./bin/aws aws s3`, preserving
            // multi-token deny-list matches such as `aws s3`.
            $stripped[] = $word;
            if (str_contains($word, '/') && ! str_contains($word, '://')) {
                $base = basename($word);
                if ($base !== '' && $base !== $word) {
                    $stripped[] = $base;
                }
            }
        }

        return implode(' ', $stripped);
    }
}
