<?php

namespace App\Actions\Agents;

use App\Models\Agent;
use Illuminate\Support\Str;

/**
 * Generate a fresh webhook secret. The user must update the X-Webhook-Secret
 * header in their Voiceflow Custom Action immediately after — until then,
 * incoming webhook calls will start failing auth.
 */
class RotateWebhookSecret
{
    public function execute(Agent $agent): Agent
    {
        $agent->forceFill(['webhook_secret' => Str::random(40)])->save();

        return $agent->refresh();
    }
}
