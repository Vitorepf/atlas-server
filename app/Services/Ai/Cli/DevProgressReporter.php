<?php

namespace App\Services\Ai\Cli;

use Symfony\Component\Console\Output\OutputInterface;

class DevProgressReporter
{
    private const PHASE_LABELS = [
        'inspect' => 'analisando',
        'plan' => 'planejando',
        'edit' => 'editando',
        'test' => 'testando',
        'repair' => 'corrigindo',
        'review' => 'revisando',
        'finish' => 'finalizado',
    ];

    /** @var array<string,float> */
    private array $started = [];

    /**
     * @var list<array{phase:string,label:string,duration_ms:int,status:string,note:?string}>
     */
    private array $entries = [];

    private ?string $currentPhase = null;

    private bool $decorated;

    public function __construct(private readonly OutputInterface $output)
    {
        $this->decorated = $output->isDecorated();
    }

    public function note(string $key, string $value): void
    {
        $this->finalizePending();
        $line = $this->ansi('2', '· '.$key.' ').$value;
        $this->output->writeln($line);
    }

    public function blank(): void
    {
        $this->finalizePending();
        $this->output->writeln('');
    }

    public function start(string $phase): void
    {
        $this->finalizePending();
        $this->started[$phase] = microtime(true);
        $this->currentPhase = $phase;

        $label = self::PHASE_LABELS[$phase] ?? $phase;
        $this->output->write($this->ansi('2;3', '  · '.$label).' ');
    }

    public function done(string $phase, ?string $note = null): void
    {
        $this->complete($phase, 'done', $note);
    }

    public function fail(string $phase, ?string $reason = null): void
    {
        $this->complete($phase, 'failed', $reason);
    }

    public function summarize(string $phase, string $status, int $durationMs, ?string $note = null): void
    {
        $this->finalizePending();
        $label = self::PHASE_LABELS[$phase] ?? $phase;
        $tail = $this->formatDuration($durationMs);
        if ($note !== null && $note !== '') {
            $tail .= ' · '.$note;
        }
        if ($status === 'failed') {
            $tail = 'falhou · '.$tail;
        }
        $line = $this->ansi('2;3', '  · '.$label).' '.$this->ansi('2', $tail);
        $this->output->writeln($line);
        $this->entries[] = [
            'phase' => $phase,
            'label' => $label,
            'duration_ms' => $durationMs,
            'status' => $status,
            'note' => $note,
        ];
    }

    private function complete(string $phase, string $status, ?string $note): void
    {
        $start = $this->started[$phase] ?? null;
        unset($this->started[$phase]);
        if ($this->currentPhase === $phase) {
            $this->currentPhase = null;
        }
        $duration = $start !== null ? max(0, (microtime(true) - $start) * 1000) : 0;
        $tail = $this->formatDuration((int) $duration);
        if ($note !== null && $note !== '') {
            $tail .= ' · '.$note;
        }
        if ($status === 'failed') {
            $tail = 'falhou · '.$tail;
        }
        $this->output->writeln($this->ansi('2', $tail));

        $this->entries[] = [
            'phase' => $phase,
            'label' => self::PHASE_LABELS[$phase] ?? $phase,
            'duration_ms' => (int) $duration,
            'status' => $status,
            'note' => $note,
        ];
    }

    private function finalizePending(): void
    {
        if ($this->currentPhase !== null) {
            $this->output->writeln($this->ansi('2', 'pendente'));
            $this->currentPhase = null;
            $this->started = [];
        }
    }

    /**
     * @return list<array{phase:string,label:string,duration_ms:int,status:string,note:?string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function totalDurationMs(): int
    {
        return array_sum(array_column($this->entries, 'duration_ms'));
    }

    public function formatDuration(int $ms): string
    {
        $seconds = (int) round($ms / 1000);
        if ($seconds < 1) {
            return '<1s';
        }
        if ($seconds < 60) {
            return $seconds.'s';
        }
        $minutes = intdiv($seconds, 60);
        $remaining = $seconds - $minutes * 60;

        return $remaining === 0 ? $minutes.'m' : $minutes.'m'.$remaining.'s';
    }

    public static function labelFor(string $phase): string
    {
        return self::PHASE_LABELS[$phase] ?? $phase;
    }

    private function ansi(string $code, string $text): string
    {
        if (! $this->decorated) {
            return $text;
        }

        return "\033[".$code.'m'.$text."\033[0m";
    }
}
