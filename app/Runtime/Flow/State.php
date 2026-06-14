<?php

namespace App\Runtime\Flow;

/**
 * One state in a conversation flow.
 *
 * prompt        — state-specific instructions appended to the base system
 *                 prompt (what to do, when to move on)
 * tools         — names of the registry tools the model may call here
 * onToolSuccess — map tool name => next state, applied centrally by the
 *                 FlowExecutor when that tool runs without error. Tools
 *                 themselves never write flow_state.
 * autoNext      — state to advance to after a completed turn when no tool
 *                 transition fired (e.g. greeting → discovery).
 */
class State
{
    /**
     * @param  list<string>  $tools
     * @param  array<string, string>  $onToolSuccess
     */
    public function __construct(
        public readonly string $prompt,
        public readonly array $tools = [],
        public readonly array $onToolSuccess = [],
        public readonly ?string $autoNext = null,
    ) {}
}
