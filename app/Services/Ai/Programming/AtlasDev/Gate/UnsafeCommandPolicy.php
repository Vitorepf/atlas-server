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
        $needle = ' '.strtolower($command).' ';
        foreach (self::DANGEROUS_TOKENS as $token) {
            if (str_contains($needle, strtolower($token))) {
                return 'matched_dangerous_token:'.trim($token);
            }
        }

        return null;
    }
}
