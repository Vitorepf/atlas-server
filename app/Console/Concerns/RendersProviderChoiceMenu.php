<?php

namespace App\Console\Concerns;

use App\Models\AiJob;

trait RendersProviderChoiceMenu
{
    protected function promptProviderChoice(AiJob $job): string
    {
        $errorCode = (string) data_get($job->metadata, 'provider_choice_error_code', 'rate_limited');
        $resetHint = data_get($job->metadata, 'reset_hint');
        $options = (array) data_get($job->metadata, 'choice_options', []);

        $this->line('');
        $header = $errorCode === 'auth_expired'
            ? "<fg=yellow;options=bold>⚠ Login expirado em {$job->provider}</>"
            : "<fg=yellow;options=bold>⚠ {$job->provider} sem créditos".($resetHint ? " até {$resetHint}" : '').'</>';
        $this->line($header);
        $this->line('');

        $labels = [];
        foreach ($options as $index => $option) {
            $key = $option['id'] === 'retry_same' ? 'r' : (string) ($index + 1);
            $label = (string) ($option['label'] ?? $option['id']);
            $description = (string) ($option['description'] ?? '');

            $this->line("  <fg=cyan>[{$key}]</> <options=bold>{$label}</>");
            if ($description !== '') {
                $this->line("      <fg=gray>{$description}</>");
            }
            $labels[$key] = $option['id'];
        }
        $this->line('');

        $valid = array_keys($labels);
        do {
            $answer = trim((string) $this->ask('Escolha ['.implode('/', $valid).']'));
            $answer = strtolower($answer);
        } while (! isset($labels[$answer]));

        return (string) $labels[$answer];
    }

    protected function announceChoiceOutcome(string $action, array $option): void
    {
        $message = match ($action) {
            'switch_provider' => "<fg=green>Migrando pra {$option['provider']}...</>",
            'downgrade_model' => "<fg=green>Continuando no {$option['provider']} com {$option['model']}...</>",
            'wait' => '<fg=yellow>Pausado até '.(data_get($option, 'available_at_iso') ?? '?').'. Rode `atlas chat continue` quando quiser retomar.</>',
            'fail' => isset($option['cli_command'])
                ? "<fg=red>Login expirado. Rode `{$option['cli_command']}` no terminal e dê retry.</>"
                : '<fg=red>Job marcado como falho.</>',
            'cancel' => '<fg=gray>Job cancelado.</>',
            'retry_same' => '<fg=green>Tentando novamente...</>',
            default => '',
        };

        if ($message !== '') {
            $this->line($message);
        }
    }
}
