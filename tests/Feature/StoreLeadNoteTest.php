<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreLeadNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_note_for_the_given_lead(): void
    {
        $user = User::factory()->create();
        $lead = Lead::query()->create([
            'name' => 'Green Earth Market',
            'assigned_user_id' => $user->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson("/api/leads/{$lead->id}/notes", [
                'note' => 'Buyer wants to review wholesale pricing.',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.lead_id', $lead->id)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.note', 'Buyer wants to review wholesale pricing.');

        $this->assertDatabaseHas('lead_notes', [
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'note' => 'Buyer wants to review wholesale pricing.',
        ]);
    }

    public function test_note_is_required(): void
    {
        $user = User::factory()->create();
        $lead = Lead::query()->create([
            'name' => 'Organic Wholesale Co.',
            'assigned_user_id' => $user->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson("/api/leads/{$lead->id}/notes", [
                'note' => '',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note']);
    }
}
