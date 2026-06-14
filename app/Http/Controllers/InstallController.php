<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Install Flowstack on your website" — a dedicated dashboard page for the
 * embed snippet. Mirrors the section on Agents/Show but lives at /install
 * so customers can find it via the sidebar without remembering which
 * agent owns it.
 *
 * The snippet is for the team's CURRENT agent. Switching agents in the
 * top-left dropdown changes which snippet renders here.
 */
class InstallController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        $agent = $team instanceof Team ? $team->currentAgent : null;

        return Inertia::render('Install/Index', [
            'agent' => $agent instanceof Agent ? [
                'slug' => $agent->slug,
                'name' => $agent->name,
                'status' => $agent->status,
            ] : null,
        ]);
    }
}
