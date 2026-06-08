<?php

namespace App\Events\Domain;

use App\Models\Conversation;
use Illuminate\Foundation\Events\Dispatchable;

class ConversationStarted
{
    use Dispatchable;

    public function __construct(public readonly Conversation $conversation) {}
}
