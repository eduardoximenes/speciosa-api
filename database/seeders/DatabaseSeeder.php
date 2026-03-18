<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::query()->updateOrCreate([
            'email' => 'reviewer@example.com',
        ], [
            'name' => 'Assessment Reviewer',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $lead = Lead::query()->updateOrCreate([
            'name' => 'Green Earth Market',
        ], [
            'assigned_user_id' => $user->id,
        ]);

        Lead::query()->updateOrCreate([
            'name' => 'Organic Wholesale Co.',
        ], [
            'assigned_user_id' => $user->id,
        ]);

        LeadNote::query()->firstOrCreate([
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'note' => 'Called buyer, interested in organic line.',
        ]);
    }
}
