<?php

namespace Database\Seeders;

use App\Models\FireIncident;
use App\Models\PoliceCall;
use Illuminate\Database\Seeder;

class UnifiedAlertsTestSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->startOfMinute();

        $fireRows = [
            ['event_num' => 'FIRE-0001', 'minutes_ago' => 5, 'is_active' => true, 'event_type' => 'STRUCTURE FIRE', 'alarm_level' => 2],
            ['event_num' => 'FIRE-0002', 'minutes_ago' => 20, 'is_active' => true, 'event_type' => 'ALARM', 'alarm_level' => 0],
            ['event_num' => 'FIRE-0003', 'minutes_ago' => 75, 'is_active' => false, 'event_type' => 'GAS LEAK', 'alarm_level' => 1],
            ['event_num' => 'FIRE-0004', 'minutes_ago' => 180, 'is_active' => false, 'event_type' => 'RESCUE', 'alarm_level' => 0],
        ];

        foreach ($fireRows as $row) {
            $timestamp = $now->copy()->subMinutes($row['minutes_ago']);

            FireIncident::factory()->create([
                'event_num' => $row['event_num'],
                'event_type' => $row['event_type'],
                'prime_street' => null,
                'cross_streets' => null,
                'dispatch_time' => $timestamp,
                'alarm_level' => $row['alarm_level'],
                'is_active' => $row['is_active'],
                'feed_updated_at' => $timestamp,
            ]);
        }

        $policeRows = [
            ['object_id' => 900001, 'minutes_ago' => 10, 'is_active' => true, 'call_type_code' => 'ASLTPR', 'call_type' => 'ASSAULT IN PROGRESS'],
            ['object_id' => 900002, 'minutes_ago' => 35, 'is_active' => true, 'call_type_code' => 'MVC', 'call_type' => 'MOTOR VEHICLE COLLISION'],
            ['object_id' => 900003, 'minutes_ago' => 90, 'is_active' => false, 'call_type_code' => 'THEFT', 'call_type' => 'THEFT'],
            ['object_id' => 900004, 'minutes_ago' => 240, 'is_active' => false, 'call_type_code' => 'SUSP', 'call_type' => 'SUSPICIOUS PERSON'],
        ];

        foreach ($policeRows as $row) {
            $timestamp = $now->copy()->subMinutes($row['minutes_ago']);

            PoliceCall::factory()->create([
                'object_id' => $row['object_id'],
                'call_type_code' => $row['call_type_code'],
                'call_type' => $row['call_type'],
                'occurrence_time' => $timestamp,
                'is_active' => $row['is_active'],
                'cross_streets' => null,
                'latitude' => null,
                'longitude' => null,
                'feed_updated_at' => $timestamp,
            ]);
        }
    }
}
