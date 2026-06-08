<?php

namespace App\Services\Ai\Hermes\Kanban;

/**
 * The single seam through which Atlas drives the real `hermes kanban` CLI.
 *
 * Isolating ALL process I/O behind one interface is what lets
 * {@see HermesKanbanSwarmService} orchestration + governance be proven WITHOUT
 * spawning Hermes or spending tokens — tests inject a fake that scripts the JSON
 * each subcommand would return. The real implementation is
 * {@see HermesKanbanProcessCli}.
 */
interface HermesKanbanCli
{
    /**
     * Run one `hermes kanban [--board <slug>] <args…> [--json]` invocation.
     *
     * @param  array<int,string>  $args  the kanban subcommand + its args (e.g. ['swarm', $goal, '--verifier', …])
     * @param  array{board?:string,hermes_home?:string,timeout?:int,json?:bool}  $options
     */
    public function invoke(array $args, array $options = []): HermesKanbanResult;
}
