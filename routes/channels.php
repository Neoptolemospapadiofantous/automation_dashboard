<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// A team's live lead board. Only members of the team may subscribe.
Broadcast::channel('team.{teamId}', function (User $user, int $teamId) {
    return $user->allTeams()->contains(fn ($team) => (int) $team->id === $teamId)
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});
