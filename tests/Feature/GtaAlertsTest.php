<?php

use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\SavedAlert;
use App\Models\TransitAlert;
use App\Models\User;
use App\Services\Alerts\DTOs\UnifiedAlertsCursor;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

test('the home page renders the gta-alerts component', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('gta-alerts')
        ->has('subscription_route_options')
    );
});

test('the home page provides unified alerts data', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    FireIncident::factory()->create([
        'event_num' => 'E1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(10),
        'feed_updated_at' => Carbon::now()->subMinutes(9),
    ]);

    FireIncident::factory()->create([
        'event_num' => 'E2',
        'is_active' => false,
        'dispatch_time' => Carbon::now()->subHour(),
        'feed_updated_at' => Carbon::now()->subMinutes(59),
    ]);

    $policeLatest = Carbon::now()->subMinutes(3);
    PoliceCall::factory()->create([
        'object_id' => 123,
        'is_active' => true,
        'occurrence_time' => Carbon::now()->subMinutes(5),
        'feed_updated_at' => $policeLatest,
    ]);

    $transitLatest = Carbon::now()->subMinutes(2);
    TransitAlert::factory()->create([
        'external_id' => 'api:62050',
        'route_type' => 'Subway',
        'route' => '1',
        'title' => 'Line 1 delay',
        'active_period_start' => Carbon::now()->subMinutes(7),
        'stop_start' => 'St Clair',
        'stop_end' => 'Lawrence',
        'is_active' => true,
        'feed_updated_at' => $transitLatest,
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gta-alerts')
            ->has('alerts')
            ->has('alerts.data', 4)
            ->where('alerts.data.0.id', 'police:123')
            ->where('alerts.data.1.id', 'transit:api:62050')
            ->where('alerts.data.2.id', 'fire:E1')
            ->where('alerts.data.3.id', 'fire:E2')
            ->where('latest_feed_updated_at', $transitLatest->toIso8601String())
        );
});

test('the home page allows filtering by status', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    FireIncident::factory()->create([
        'event_num' => 'E1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(10),
    ]);

    FireIncident::factory()->create([
        'event_num' => 'E2',
        'is_active' => false,
        'dispatch_time' => Carbon::now()->subMinutes(5),
    ]);

    PoliceCall::factory()->create([
        'object_id' => 555,
        'is_active' => true,
        'occurrence_time' => Carbon::now()->subMinutes(1),
    ]);

    $this->get(route('home', ['status' => 'active']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'active')
            ->has('alerts.data', 2)
            ->where('alerts.data.0.id', 'police:555')
            ->where('alerts.data.1.id', 'fire:E1')
        );
});

test('the home page supports sort direction toggle', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    FireIncident::factory()->create([
        'event_num' => 'E1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(1),
    ]);

    FireIncident::factory()->create([
        'event_num' => 'E2',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(2),
    ]);

    $this->get(route('home', ['sort' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'asc')
            ->where('alerts.data.0.id', 'fire:E2')
            ->where('alerts.data.1.id', 'fire:E1')
        );
});

test('the home page rejects invalid status values', function () {
    $this->get(route('home', ['status' => 'invalid-status']))
        ->assertRedirect()
        ->assertSessionHasErrors(['status']);
});

test('the home page rejects invalid source values', function () {
    $this->get(route('home', ['source' => 'hazard']))
        ->assertRedirect()
        ->assertSessionHasErrors(['source']);
});

test('the home page rejects invalid since values', function () {
    $this->get(route('home', ['since' => '2h']))
        ->assertRedirect()
        ->assertSessionHasErrors(['since']);
});

test('the home page rejects invalid sort values', function () {
    $this->get(route('home', ['sort' => 'invalid']))
        ->assertRedirect()
        ->assertSessionHasErrors(['sort']);
});

test('the home page rejects invalid cursor values', function () {
    $this->get(route('home', ['cursor' => 'not-a-cursor']))
        ->assertRedirect()
        ->assertSessionHasErrors(['cursor']);
});

test('the home page trims cursor values before validating', function () {
    $cursor = UnifiedAlertsCursor::fromTuple(
        Carbon::parse('2026-02-03 12:00:00')->toImmutable(),
        'fire:E1',
    )->encode();

    $this->get(route('home', ['cursor' => "  {$cursor}  "]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('gta-alerts')
        );
});

test('the home page trims q and treats whitespace-only q as unset', function () {
    $this->get(route('home', ['q' => " \n\t "]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', null)
        );
});

test('the home page supports server-side filtering by source, since, and q', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    FireIncident::factory()->create([
        'event_num' => 'E1',
        'event_type' => 'ALARM',
        'prime_street' => 'Yonge St',
        'cross_streets' => 'Dundas St',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(10),
    ]);

    FireIncident::factory()->create([
        'event_num' => 'E2',
        'event_type' => 'ALARM',
        'prime_street' => 'Bloor St',
        'cross_streets' => 'Bathurst St',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(45),
    ]);

    PoliceCall::factory()->create([
        'object_id' => 555,
        'is_active' => true,
        'call_type' => 'ASSAULT',
        'occurrence_time' => Carbon::now()->subMinutes(5),
    ]);

    $this->get(route('home', ['source' => 'fire', 'since' => '30m', 'q' => 'yonge']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.source', 'fire')
            ->where('filters.since', '30m')
            ->where('filters.q', 'yonge')
            ->has('alerts.data', 1)
            ->where('alerts.data.0.id', 'fire:E1')
        );
});

test('the home page sets latest_feed_updated_at from fire when police is missing', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    $latest = Carbon::now()->subMinutes(3);
    FireIncident::factory()->create([
        'event_num' => 'E1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(10),
        'feed_updated_at' => $latest,
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('latest_feed_updated_at', $latest->toIso8601String())
        );
});

test('the home page sets latest_feed_updated_at from police when fire is missing', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    $latest = Carbon::now()->subMinutes(3);
    PoliceCall::factory()->create([
        'object_id' => 123,
        'is_active' => true,
        'occurrence_time' => Carbon::now()->subMinutes(10),
        'feed_updated_at' => $latest,
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('latest_feed_updated_at', $latest->toIso8601String())
        );
});

test('the home page sets latest_feed_updated_at to the most recent of fire and police', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    $fireLatest = Carbon::now()->subMinutes(2);
    FireIncident::factory()->create([
        'event_num' => 'E1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(10),
        'feed_updated_at' => $fireLatest,
    ]);

    $policeLatest = Carbon::now()->subMinutes(5);
    PoliceCall::factory()->create([
        'object_id' => 123,
        'is_active' => true,
        'occurrence_time' => Carbon::now()->subMinutes(10),
        'feed_updated_at' => $policeLatest,
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('latest_feed_updated_at', $fireLatest->toIso8601String())
        );
});

test('the home page sets latest_feed_updated_at from transit when fire and police are missing', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    $latest = Carbon::now()->subMinutes(4);
    TransitAlert::factory()->create([
        'external_id' => 'api:62000',
        'feed_updated_at' => $latest,
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('latest_feed_updated_at', $latest->toIso8601String())
        );
});

test('the home page sets latest_feed_updated_at to the most recent of fire police and transit', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    $fireLatest = Carbon::now()->subMinutes(3);
    FireIncident::factory()->create([
        'event_num' => 'E1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(10),
        'feed_updated_at' => $fireLatest,
    ]);

    $policeLatest = Carbon::now()->subMinutes(4);
    PoliceCall::factory()->create([
        'object_id' => 123,
        'is_active' => true,
        'occurrence_time' => Carbon::now()->subMinutes(10),
        'feed_updated_at' => $policeLatest,
    ]);

    $transitLatest = Carbon::now()->subMinutes(1);
    TransitAlert::factory()->create([
        'external_id' => 'api:62001',
        'feed_updated_at' => $transitLatest,
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('latest_feed_updated_at', $transitLatest->toIso8601String())
        );
});

test('go transit alerts appear in unified feed', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-05 18:00:00'));

    GoTransitAlert::factory()->create([
        'external_id' => 'notif:LW:TDELAY:abc123',
        'message_subject' => 'Lakeshore West delays',
        'posted_at' => Carbon::now()->subMinutes(5),
        'is_active' => true,
        'feed_updated_at' => Carbon::now()->subMinutes(4),
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('alerts.data', 1)
            ->where('alerts.data.0.id', 'go_transit:notif:LW:TDELAY:abc123')
            ->where('alerts.data.0.source', 'go_transit')
            ->where('alerts.data.0.title', 'Lakeshore West delays')
        );
});

test('the home page sets latest_feed_updated_at from go transit when it is most recent', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-05 18:00:00'));

    $fireLatest = Carbon::now()->subMinutes(5);
    FireIncident::factory()->create([
        'event_num' => 'E1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(10),
        'feed_updated_at' => $fireLatest,
    ]);

    $goLatest = Carbon::now()->subMinutes(1);
    GoTransitAlert::factory()->create([
        'external_id' => 'notif:LW:TDELAY:abc123',
        'is_active' => true,
        'feed_updated_at' => $goLatest,
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('latest_feed_updated_at', $goLatest->toIso8601String())
        );
});

// Phase 2: Frontend URL Filters + UX Tests

test('the home page echoes all filter params in the filters prop for ui rehydration', function () {
    $this->get(route('home', [
        'status' => 'active',
        'sort' => 'asc',
        'source' => 'fire',
        'q' => 'test search',
        'since' => '1h',
    ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'active')
            ->where('filters.sort', 'asc')
            ->where('filters.source', 'fire')
            ->where('filters.q', 'test search')
            ->where('filters.since', '1h')
        );
});

test('the home page preserves filter params through pagination links', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    // Create 30 fire incidents to trigger pagination (default per page is 25)
    for ($i = 1; $i <= 30; $i++) {
        FireIncident::factory()->create([
            'event_num' => "E{$i}",
            'is_active' => true,
            'dispatch_time' => Carbon::now()->subMinutes($i),
        ]);
    }

    $response = $this->get(route('home', [
        'status' => 'active',
        'source' => 'fire',
        'since' => '3h',
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('filters.status', 'active')
        ->where('filters.source', 'fire')
        ->where('filters.since', '3h')
        ->has('alerts.data')
        ->has('alerts.next_cursor')
    );
});

test('the home page combines multiple filters correctly', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    // Create fire incidents at different times with different statuses
    FireIncident::factory()->create([
        'event_num' => 'E1',
        'event_type' => 'ALARM',
        'prime_street' => 'Yonge St',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(10),
    ]);

    FireIncident::factory()->create([
        'event_num' => 'E2',
        'event_type' => 'ALARM',
        'prime_street' => 'Bloor St',
        'is_active' => false, // cleared
        'dispatch_time' => Carbon::now()->subMinutes(20),
    ]);

    FireIncident::factory()->create([
        'event_num' => 'E3',
        'event_type' => 'FIRE',
        'prime_street' => 'Yonge St',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(5),
    ]);

    PoliceCall::factory()->create([
        'object_id' => 123,
        'is_active' => true,
        'occurrence_time' => Carbon::now()->subMinutes(15),
    ]);

    // Test status + source combination
    $this->get(route('home', ['status' => 'active', 'source' => 'fire']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'active')
            ->where('filters.source', 'fire')
            ->has('alerts.data', 2) // E1 and E3
        );

    // Test status + q combination
    $this->get(route('home', ['status' => 'cleared', 'q' => 'yonge']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'cleared')
            ->where('filters.q', 'yonge')
            ->has('alerts.data', 0) // E2 is cleared but at Bloor, not Yonge
        );
});

test('the home page handles all valid since options', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    FireIncident::factory()->create([
        'event_num' => 'E1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(5),
    ]);

    $validOptions = ['30m', '1h', '3h', '6h', '12h'];

    foreach ($validOptions as $option) {
        $this->get(route('home', ['since' => $option]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.since', $option)
            );
    }
});

test('the home page handles empty filters gracefully', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-03 12:00:00'));

    FireIncident::factory()->create([
        'event_num' => 'E1',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(5),
    ]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'all')
            ->where('filters.sort', 'desc')
            ->where('filters.source', null)
            ->where('filters.q', null)
            ->where('filters.since', null)
            ->has('alerts.data', 1)
        );
});

test('the home page returns empty results for short queries', function () {
    FireIncident::factory()->create([
        'event_num' => 'E1',
        'event_type' => 'ALARM',
        'prime_street' => 'Yonge St',
    ]);

    $this->get(route('home', ['q' => 'y']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', 'y')
            ->has('alerts.data', 0)
        );
});

test('the home page sanitizes search query', function () {
    $this->get(route('home', ['q' => '<script>alert(1)</script>']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', 'alert(1)')
        );
});

// --- saved_alert_ids bootstrap ---

test('guest receives empty saved_alert_ids in Inertia payload', function () {
    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gta-alerts')
            ->where('saved_alert_ids', [])
        );
});

test('authenticated user receives their saved_alert_ids in Inertia payload', function () {
    $user = User::factory()->create();

    // Insert fire first, police second — so police has a higher id (newest saved first).
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'fire:F26018618']);
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'police:12345']);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gta-alerts')
            ->has('saved_alert_ids', 2)
            ->where('saved_alert_ids.0', 'police:12345')
            ->where('saved_alert_ids.1', 'fire:F26018618')
        );
});

test('authenticated user with no saved alerts receives empty saved_alert_ids', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gta-alerts')
            ->where('saved_alert_ids', [])
        );
});

test('saved_alert_ids from other users are not included in the payload', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    SavedAlert::factory()->create(['user_id' => $otherUser->id, 'alert_id' => 'fire:OTHER111']);
    SavedAlert::factory()->create(['user_id' => $user->id, 'alert_id' => 'fire:MINE222']);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('gta-alerts')
            ->has('saved_alert_ids', 1)
            ->where('saved_alert_ids.0', 'fire:MINE222')
        );
});
