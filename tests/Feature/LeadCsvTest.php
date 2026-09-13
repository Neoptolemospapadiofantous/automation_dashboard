<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Lead;
use App\Models\User;
use App\Support\LeadCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LeadCsvTest extends TestCase
{
    use RefreshDatabase;

    public function test_parse_maps_lenient_headers_and_reports_bad_rows(): void
    {
        $csv = "Full name;E-mail;Phone number;Company name;Tags;Stage;Score\n"
            ."Maria K;MARIA@Example.com;99123456;Karma;hot, vip;qualified;88\n"
            ."Nobody;not-an-email;;;;;\n"
            .";;;;;;\n"
            ."Only Name;;;;;bogus;500\n";

        $parsed = LeadCsv::parse($csv);

        $this->assertCount(2, $parsed['rows']);
        $this->assertSame('maria@example.com', $parsed['rows'][0]['email']);
        $this->assertSame(['hot', 'vip'], $parsed['rows'][0]['tags']);
        $this->assertSame('qualified', $parsed['rows'][0]['status']);
        $this->assertSame(88, $parsed['rows'][0]['score']);
        $this->assertSame('new', $parsed['rows'][1]['status']);
        $this->assertSame(100, $parsed['rows'][1]['score']);
        $this->assertSame('import', $parsed['rows'][1]['source']);
        $this->assertCount(2, $parsed['errors']);
        $this->assertFalse($parsed['truncated']);
    }

    public function test_parse_rejects_a_file_without_a_name_or_email_column(): void
    {
        $parsed = LeadCsv::parse("phone,company\n99123456,Karma\n");
        $this->assertSame([], $parsed['rows']);
        $this->assertNotEmpty($parsed['errors']);
    }

    public function test_export_row_neutralises_formula_injection(): void
    {
        $lead = Lead::factory()->make(['name' => '=HYPERLINK("https://phish")', 'tags' => ['a']]);
        $row = LeadCsv::row($lead);
        $this->assertSame("'=HYPERLINK(\"https://phish\")", $row[1]);
        $this->assertSame('a', $row[9]);
    }

    public function test_export_streams_the_filtered_board_as_csv(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();
        Lead::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id, 'name' => 'Maria In', 'status' => 'new', 'email' => 'in@example.com', 'tags' => ['hot']]);
        Lead::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id, 'name' => 'Nikos Out', 'status' => 'new', 'email' => 'out@example.com']);

        $response = $this->actingAs($user)->get(route('leads.export', ['tag' => 'hot']));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $body = $response->streamedContent();
        $this->assertStringContainsString('id,name,email', $body);
        $this->assertStringContainsString('Maria In', $body);
        $this->assertStringNotContainsString('Nikos Out', $body);
    }

    public function test_import_creates_and_merges_by_email_without_touching_pipeline_state(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $agent = Agent::factory()->for($team)->create();
        $team->forceFill(['current_agent_id' => $agent->id])->save();

        $existing = Lead::factory()->create([
            'team_id' => $team->id, 'agent_id' => $agent->id, 'email' => 'maria@example.com',
            'name' => 'Maria K', 'status' => 'won', 'score' => 90, 'phone' => null, 'tags' => ['vip'],
        ]);

        $csv = "name,email,phone,status,score,tags\n"
            ."Maria Kyriakou,maria@example.com,99123456,new,1,hot\n"
            ."Nikos P,nikos@example.com,,qualified,40,\n";
        $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

        $this->actingAs($user)
            ->post(route('leads.import'), ['file' => $file])
            ->assertRedirect(route('leads.index'))
            ->assertSessionHas('lead_import', fn ($r) => $r['created'] === 1 && $r['updated'] === 1 && $r['skipped'] === 0);

        $existing->refresh();
        $this->assertSame('won', $existing->status->value, 'import must not overwrite pipeline state');
        $this->assertSame(90, $existing->score);
        $this->assertSame('99123456', $existing->phone, 'import fills a blank');
        $this->assertSame(['vip', 'hot'], $existing->tags);

        $nikos = Lead::query()->where('email', 'nikos@example.com')->firstOrFail();
        $this->assertSame('import', $nikos->source);
        $this->assertSame('qualified', $nikos->status->value);
        $this->assertSame($agent->id, (int) $nikos->getAttribute('agent_id'));
    }
}
