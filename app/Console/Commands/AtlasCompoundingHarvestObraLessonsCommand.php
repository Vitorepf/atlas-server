<?php

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\Compounding\AtlasObraLessonHarvester;
use Illuminate\Console\Command;

class AtlasCompoundingHarvestObraLessonsCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:compounding:harvest-obra-lessons
        {doc : Caminho do doc de obra (markdown)}
        {--dry-run : Lista as lições sem persistir candidates}
        {--json : Print machine-readable JSON}';

    protected $description = 'Colhe refutações/NÃO-FAZER de um doc de obra e cria learning candidates governados em quarentena (nunca auto-promove).';

    public function handle(AtlasObraLessonHarvester $harvester): int
    {
        $doc = (string) $this->argument('doc');
        if (! is_file($doc)) {
            $this->error("Doc não encontrado: {$doc}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $lessons = $harvester->harvest($doc, $dryRun);
        $created = count(array_filter($lessons, fn (array $l): bool => $l['candidate_id'] !== null));
        $skipped = count(array_filter($lessons, fn (array $l): bool => $l['already_exists']));

        $payload = [
            'ok' => true,
            'doc' => $doc,
            'dry_run' => $dryRun,
            'lessons_found' => count($lessons),
            'candidates_created' => $created,
            'already_existing' => $skipped,
            'lessons' => $lessons,
        ];

        if ($this->option('json')) {
            $this->jsonLine($payload);

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d lições em %s — %d candidates criados, %d já existiam%s',
            count($lessons),
            $doc,
            $created,
            $skipped,
            $dryRun ? ' (dry-run, nada persistido)' : '',
        ));
        foreach ($lessons as $lesson) {
            $this->line(sprintf(
                '- [%s]%s %s',
                $lesson['kind'],
                $lesson['already_exists'] ? ' (dedupe)' : '',
                $lesson['claim'],
            ));
        }

        return self::SUCCESS;
    }
}
