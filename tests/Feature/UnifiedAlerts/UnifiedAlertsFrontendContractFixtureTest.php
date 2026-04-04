<?php

use App\Http\Resources\UnifiedAlertResource;
use App\Models\DrtAlert;
use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\MiwayAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use App\Models\YrtAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\UnifiedAlertsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

function frontendContractFixturePath(): string
{
    return base_path('resources/js/features/gta-alerts/domain/alerts/__fixtures__/backend-unified-alerts.json');
}

function shouldUpdateFrontendContractFixture(): bool
{
    $raw = env('UPDATE_CONTRACT_FIXTURES');

    if ($raw === false || $raw === null) {
        return false;
    }

    $normalized = strtolower((string) $raw);

    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function seedFrontendContractFixtureDataset(): void
{
    $base = Carbon::parse('2026-02-06 14:00:00', 'UTC');

    FireIncident::factory()->create([
        'event_num' => 'FIRE-CONTRACT-001',
        'event_type' => 'STRUCTURE FIRE',
        'prime_street' => 'Main St',
        'cross_streets' => 'Queen St',
        'dispatch_time' => $base->copy()->subMinutes(5),
        'alarm_level' => 2,
        'units_dispatched' => 'P1, A1',
        'beat' => 'B11',
        'is_active' => true,
        'feed_updated_at' => $base->copy()->subMinutes(4),
    ]);

    FireIncident::factory()->create([
        'event_num' => 'FIRE-CONTRACT-002',
        'event_type' => 'ALARM',
        'prime_street' => null,
        'cross_streets' => null,
        'dispatch_time' => $base->copy()->subMinutes(40),
        'alarm_level' => 0,
        'units_dispatched' => null,
        'beat' => null,
        'is_active' => false,
        'feed_updated_at' => $base->copy()->subMinutes(39),
    ]);

    PoliceCall::factory()->create([
        'object_id' => 910001,
        'call_type' => 'ASSAULT IN PROGRESS',
        'call_type_code' => 'ASLTPR',
        'division' => 'D31',
        'cross_streets' => 'King St W / Spadina Ave',
        'latitude' => 43.6456,
        'longitude' => -79.3957,
        'occurrence_time' => $base->copy()->subMinutes(2),
        'is_active' => true,
        'feed_updated_at' => $base->copy()->subMinute(),
    ]);

    PoliceCall::factory()->create([
        'object_id' => 910002,
        'call_type' => 'THEFT',
        'call_type_code' => 'THEFT',
        'division' => null,
        'cross_streets' => null,
        'latitude' => null,
        'longitude' => null,
        'occurrence_time' => $base->copy()->subHour(),
        'is_active' => false,
        'feed_updated_at' => $base->copy()->subMinutes(59),
    ]);

    TransitAlert::factory()->create([
        'external_id' => 'api:CONTRACT-001',
        'source_feed' => 'live-api',
        'route_type' => 'Subway',
        'route' => '1',
        'title' => 'Line 1 delay',
        'severity' => 'Critical',
        'effect' => 'REDUCED_SERVICE',
        'alert_type' => 'advisory',
        'description' => 'Shuttle buses are running between stations.',
        'url' => null,
        'direction' => 'Both Ways',
        'cause' => null,
        'stop_start' => 'St Clair',
        'stop_end' => 'Eglinton',
        'active_period_start' => $base->copy()->subMinutes(3),
        'is_active' => true,
        'feed_updated_at' => $base->copy()->subMinutes(2),
    ]);

    TransitAlert::factory()->create([
        'external_id' => 'sxa:CONTRACT-002',
        'source_feed' => 'sxa',
        'route_type' => 'Streetcar',
        'route' => '501',
        'title' => '501 diversion cleared',
        'severity' => 'Minor',
        'effect' => 'DETOUR',
        'alert_type' => null,
        'description' => null,
        'url' => null,
        'direction' => null,
        'cause' => null,
        'stop_start' => null,
        'stop_end' => null,
        'active_period_start' => $base->copy()->subMinutes(70),
        'is_active' => false,
        'feed_updated_at' => $base->copy()->subMinutes(69),
    ]);

    GoTransitAlert::factory()->create([
        'external_id' => 'notif:LW:TDELAY:contract001',
        'alert_type' => 'notification',
        'service_mode' => 'GO Train',
        'corridor_or_route' => 'Lakeshore West',
        'corridor_code' => 'LW',
        'sub_category' => 'TDELAY',
        'message_subject' => 'Lakeshore West delays',
        'message_body' => 'Expect 10 minute delays near Port Credit.',
        'direction' => 'EASTBOUND',
        'trip_number' => null,
        'delay_duration' => null,
        'status' => 'UPD',
        'line_colour' => '#0099cc',
        'posted_at' => $base->copy()->subMinute(),
        'is_active' => true,
        'feed_updated_at' => $base,
    ]);

    GoTransitAlert::factory()->create([
        'external_id' => 'saag:KI:1234',
        'alert_type' => 'saag',
        'service_mode' => 'GO Bus',
        'corridor_or_route' => 'Kitchener',
        'corridor_code' => 'KI',
        'sub_category' => null,
        'message_subject' => 'Kitchener schedule update',
        'message_body' => null,
        'direction' => null,
        'trip_number' => '1234',
        'delay_duration' => '00:10:00',
        'status' => 'INIT',
        'line_colour' => null,
        'posted_at' => $base->copy()->subMinutes(90),
        'is_active' => false,
        'feed_updated_at' => $base->copy()->subMinutes(89),
    ]);

    MiwayAlert::factory()->create([
        'external_id' => 'miway:contract:active:001',
        'header_text' => 'Route 101 detour in effect',
        'description_text' => 'Route 101 is detoured via Queen St due to construction.',
        'cause' => 'CONSTRUCTION',
        'effect' => 'DETOUR',
        'starts_at' => $base->copy()->subMinutes(10),
        'ends_at' => $base->copy()->addHours(2),
        'url' => 'https://www.miapp.ca/alerts/101-detour',
        'detour_pdf_url' => null,
        'is_active' => true,
        'feed_updated_at' => $base->copy()->subMinutes(5),
    ]);

    MiwayAlert::factory()->create([
        'external_id' => 'miway:contract:inactive:001',
        'header_text' => 'Route 44 reduced service',
        'description_text' => 'Route 44 operating on reduced schedule.',
        'cause' => 'UNKNOWN_CAUSE',
        'effect' => 'REDUCED_SERVICE',
        'starts_at' => $base->copy()->subHours(3),
        'ends_at' => $base->copy()->subHours(1),
        'url' => null,
        'detour_pdf_url' => null,
        'is_active' => false,
        'feed_updated_at' => $base->copy()->subHours(2),
    ]);

    YrtAlert::factory()->create([
        'external_id' => '91001',
        'title' => '52 - Holland Landing detour',
        'route_text' => '52',
        'details_url' => 'https://www.yrt.ca/en/service-updates/91001.aspx',
        'description_excerpt' => 'Temporary detour in effect near Green Lane.',
        'body_text' => 'Routes affected: 52, 58. Expect 15 minute delays.',
        'posted_at' => $base->copy()->subHours(5)->subMinutes(15),
        'feed_updated_at' => $base->copy()->subMinutes(16),
        'is_active' => true,
    ]);

    DrtAlert::factory()->create([
        'external_id' => 'conlin-grandview-detour',
        'title' => 'Conlin Grandview Detour',
        'route_text' => '900, 920',
        'details_url' => 'https://www.durhamregiontransit.com/en/news/conlin-grandview-detour.aspx',
        'when_text' => 'Effective until further notice',
        'body_text' => 'Routes 900 and 920 are detoured via Grandview Drive.',
        'posted_at' => $base->copy()->subHours(3)->subMinutes(40),
        'feed_updated_at' => $base->copy()->subHours(3)->subMinutes(35),
        'is_active' => true,
    ]);
}

/**
 * @return array{alerts: array<int, array<string, mixed>>}
 */
function buildFrontendContractFixturePayload(): array
{
    $criteria = new UnifiedAlertsCriteria(status: 'all', perPage: 50, page: 1);
    $paginator = app(UnifiedAlertsQuery::class)->paginate($criteria);
    $request = Request::create('/', 'GET');

    $alerts = array_map(
        fn ($item): array => (new UnifiedAlertResource($item))->toArray($request),
        $paginator->items(),
    );

    return ['alerts' => array_values($alerts)];
}

function sortFixturePayloadRecursively(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map(sortFixturePayloadRecursively(...), $value);
    }

    foreach ($value as $key => $nested) {
        $value[$key] = sortFixturePayloadRecursively($nested);
    }

    ksort($value);

    return $value;
}

test('unified alert resource payload matches the frontend contract fixture', function () {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    Carbon::setTestNow(Carbon::parse('2026-02-06 14:00:00', 'UTC'));

    seedFrontendContractFixtureDataset();

    $actual = buildFrontendContractFixturePayload();

    $sources = collect($actual['alerts'])
        ->map(fn (array $alert): string => (string) ($alert['source'] ?? ''))
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($sources)->toBe(['drt', 'fire', 'go_transit', 'miway', 'police', 'transit', 'yrt']);
    expect($actual['alerts'])->toHaveCount(12);

    $fixturePath = frontendContractFixturePath();
    $refreshHint = 'UPDATE_CONTRACT_FIXTURES=1 ./vendor/bin/pest --filter=UnifiedAlertsFrontendContractFixtureTest';

    if (shouldUpdateFrontendContractFixture()) {
        $directory = dirname($fixturePath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $encoded = json_encode(
            $actual,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        file_put_contents($fixturePath, $encoded.PHP_EOL);

        expect(file_exists($fixturePath))->toBeTrue();

        return;
    }

    if (! file_exists($fixturePath)) {
        $this->fail("Missing contract fixture at {$fixturePath}. Generate it with: {$refreshHint}");
    }

    $expected = json_decode(
        (string) file_get_contents($fixturePath),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect(sortFixturePayloadRecursively($actual))->toBe(sortFixturePayloadRecursively($expected));
});
