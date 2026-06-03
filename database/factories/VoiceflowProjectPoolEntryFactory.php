<?php

namespace Database\Factories;

use App\Models\VoiceflowProjectPoolEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VoiceflowProjectPoolEntry>
 */
class VoiceflowProjectPoolEntryFactory extends Factory
{
    protected $model = VoiceflowProjectPoolEntry::class;

    public function definition(): array
    {
        return [
            // 24-char hex like real Voiceflow project ids.
            'voiceflow_project_id' => substr(bin2hex(random_bytes(12)), 0, 24),
            'voiceflow_api_key' => 'VF.DM.'.Str::random(20).'.'.Str::random(10),
            'voiceflow_workspace_api_key' => null,
            'voiceflow_environment' => 'main',
            'status' => VoiceflowProjectPoolEntry::STATUS_AVAILABLE,
            'assigned_to_agent_id' => null,
            'assigned_at' => null,
        ];
    }

    public function assigned(): self
    {
        return $this->state(['status' => VoiceflowProjectPoolEntry::STATUS_ASSIGNED, 'assigned_at' => now()]);
    }

    public function retired(): self
    {
        return $this->state(['status' => VoiceflowProjectPoolEntry::STATUS_RETIRED]);
    }
}
