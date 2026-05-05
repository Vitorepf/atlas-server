<?php

namespace App\Services\Ai\Cli\Repl;

/**
 * Formata uma linha compacta de status pra ficar fixa acima do prompt.
 *
 * Inclui: provider, modelo, contagem de imagens, tokens estimados, duracao
 * da sessao. Sem dependencia de IO — devolve string pra quem quiser pintar.
 */
class StatusBarFormatter
{
    public function format(
        ?string $providerLabel,
        ?string $modelLabel,
        ReplComposer $composer,
        \DateTimeImmutable $sessionStartedAt,
        ?\DateTimeImmutable $now = null,
    ): string {
        $now ??= new \DateTimeImmutable();
        $segments = [];

        if ($providerLabel !== null && $providerLabel !== '') {
            $segments[] = $providerLabel;
        }
        if ($modelLabel !== null && $modelLabel !== '') {
            $segments[] = $modelLabel;
        }

        $imageCount = count($composer->images());
        if ($imageCount === 1) {
            $segments[] = '1 imagem';
        } elseif ($imageCount > 1) {
            $segments[] = $imageCount.' imagens';
        }

        $tokens = $this->estimateTokens($composer);
        if ($tokens > 0) {
            $segments[] = '~'.$this->humanCount($tokens).' tokens';
        }

        $segments[] = $this->humanDuration($sessionStartedAt, $now);

        return 'atlas · '.implode(' · ', $segments);
    }

    public function estimateTokens(ReplComposer $composer): int
    {
        $textTokens = (int) ceil(mb_strlen($composer->text()) / 4);
        $imageTokens = count($composer->images()) * 1024;

        return $textTokens + $imageTokens;
    }

    public function humanDuration(\DateTimeImmutable $startedAt, \DateTimeImmutable $now): string
    {
        $seconds = max(0, $now->getTimestamp() - $startedAt->getTimestamp());
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%02d:%02d', $minutes, $secs);
    }

    private function humanCount(int $count): string
    {
        if ($count < 1000) {
            return (string) $count;
        }
        if ($count < 1_000_000) {
            return number_format($count / 1000, 1, '.', '').'k';
        }

        return number_format($count / 1_000_000, 1, '.', '').'m';
    }
}
