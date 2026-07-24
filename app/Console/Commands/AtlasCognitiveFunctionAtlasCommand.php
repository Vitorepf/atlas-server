<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AtlasCognitiveFunctionAtlasService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasCognitiveFunctionAtlasCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:cognitive-function
        {--action=self-model : self-model|taxonomy|shape|gaps|owns|overloaded|subsystems-by-group}
        {--group= : group filter for subsystems-by-group / overloaded}
        {--acronym= : acronym for owns}
        {--threshold=8 : overload threshold}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Cognitive Function Atlas — read-model lens over ACOS scorecard projecting groups, gaps, shape, ownership.';

    public function handle(AtlasCognitiveFunctionAtlasService $svc): int
    {
        $json = (bool) $this->option('json');
        $action = (string) $this->option('action');

        try {
            $payload = match ($action) {
                'self-model' => $svc->selfModel(),
                'taxonomy' => ['groups' => $svc->groupTaxonomy()],
                'shape' => ['shape' => $svc->cognitiveShape()],
                'gaps' => ['gaps' => $svc->gapsByGroup()],
                'owns' => ['acronym' => (string) $this->option('acronym'), 'owner_group' => $svc->whichGroupOwns((string) $this->option('acronym'))],
                'overloaded' => ['group' => (string) $this->option('group'), 'overloaded' => $svc->isGroupOverloaded((string) $this->option('group'), (int) $this->option('threshold'))],
                'subsystems-by-group' => ['group' => (string) $this->option('group'), 'subsystems' => $svc->subsystemsByGroup((string) $this->option('group'))],
                default => throw new \InvalidArgumentException("Unknown action '{$action}'."),
            };
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($json) {
            $this->line($this->encode($payload));
        } else {
            foreach ($payload as $k => $v) {
                $this->line(is_scalar($v) ? "{$k}: {$v}" : "{$k}: ".json_encode($v));
            }
        }

        return self::SUCCESS;
    }
}
