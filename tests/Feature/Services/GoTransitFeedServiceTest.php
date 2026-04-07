<?php

use App\Services\GoTransitFeedService;
use Illuminate\Support\Facades\Http;

test('it parses a valid json response with trains buses and stations', function () {
    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => [
            'Train' => [
                [
                    'Code' => 'LW',
                    'Name' => 'Lakeshore West',
                    'LineColour' => '#8B4513',
                    'Notifications' => [
                        'Notification' => [
                            [
                                'SubCategory' => 'TDELAY',
                                'MessageSubject' => 'Lakeshore West delays',
                                'MessageBody' => '<p>Expect 15 min delays</p>',
                                'PostedDateTime' => '02/05/2026 14:00:00',
                                'Status' => 'INIT',
                            ],
                        ],
                    ],
                    'SaagNotifications' => [
                        'SaagNotification' => [
                            [
                                'Direction' => 'EASTBOUND',
                                'HeadSign' => 'Union Station',
                                'DelayDuration' => '00:12:00',
                                'DepartureTimeDisplay' => '2:30 PM',
                                'ArrivalTimeDisplay' => '3:15 PM',
                                'Status' => 'Moving',
                                'TripNumbers' => ['4521'],
                                'PostedDateTime' => '2026-02-05 14:25:00',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'Buses' => [
            'Bus' => [
                [
                    'Code' => '12',
                    'Name' => 'Route 12',
                    'LineColour' => '',
                    'Notifications' => [
                        'Notification' => [
                            [
                                'SubCategory' => 'BCANCEL',
                                'MessageSubject' => 'Route 12 cancelled',
                                'MessageBody' => '<b>All trips cancelled</b>',
                                'PostedDateTime' => '02/05/2026 13:00:00',
                                'Status' => 'UPD',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'Stations' => [
            'Station' => [
                [
                    'Code' => 'UN',
                    'Name' => 'Union Station',
                    'LineColour' => '',
                    'Notifications' => [
                        'Notification' => [
                            [
                                'SubCategory' => 'SADIS',
                                'MessageSubject' => 'Elevator out of service',
                                'MessageBody' => '',
                                'PostedDateTime' => '02/05/2026 10:00:00',
                                'Status' => 'INIT',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    Http::fake([
        '*' => Http::response($json, 200, ['Content-Type' => 'application/json']),
    ]);

    $service = app(GoTransitFeedService::class);
    $results = $service->fetch();

    expect($results['updated_at'])->toBe('2026-02-05T14:30:00-05:00');
    expect($results['alerts'])->toHaveCount(4);

    // Notification alert
    $notification = $results['alerts'][0];
    expect($notification['alert_type'])->toBe('notification');
    expect($notification['service_mode'])->toBe('GO Train');
    expect($notification['corridor_or_route'])->toBe('Lakeshore West');
    expect($notification['corridor_code'])->toBe('LW');
    expect($notification['sub_category'])->toBe('TDELAY');
    expect($notification['message_subject'])->toBe('Lakeshore West delays');
    expect($notification['message_body'])->toBe('Expect 15 min delays');
    expect($notification['line_colour'])->toBe('#8B4513');
    expect($notification['external_id'])->toStartWith('notif:LW:TDELAY:');

    // SAAG alert
    $saag = $results['alerts'][1];
    expect($saag['alert_type'])->toBe('saag');
    expect($saag['service_mode'])->toBe('GO Train');
    expect($saag['corridor_code'])->toBe('LW');
    expect($saag['direction'])->toBe('EASTBOUND');
    expect($saag['trip_number'])->toBe('4521');
    expect($saag['delay_duration'])->toBe('00:12:00');
    expect($saag['external_id'])->toBe('saag:LW:4521');
    expect($saag['message_subject'])->toContain('Lakeshore West');
    expect($saag['message_body'])->toContain('Departure: 2:30 PM');
    expect($saag['message_body'])->toContain('Arrival: 3:15 PM');

    // Bus notification
    $bus = $results['alerts'][2];
    expect($bus['service_mode'])->toBe('GO Bus');
    expect($bus['sub_category'])->toBe('BCANCEL');
    expect($bus['message_body'])->toBe('All trips cancelled');

    // Station notification
    $station = $results['alerts'][3];
    expect($station['service_mode'])->toBe('Station');
    expect($station['sub_category'])->toBe('SADIS');
    expect($station['message_body'])->toBeNull();
});

test('it handles SAAG notifications missing Name and Code without generating malformed subjects', function () {
    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => [
            'Train' => [
                [
                    'Notifications' => ['Notification' => []],
                    'SaagNotifications' => [
                        'SaagNotification' => [
                            [
                                'Direction' => 'EASTBOUND',
                                'HeadSign' => 'Union Station',
                                'DelayDuration' => '00:12:00',
                                'DepartureTimeDisplay' => '2:30 PM',
                                'ArrivalTimeDisplay' => '3:15 PM',
                                'Status' => 'Moving',
                                'TripNumbers' => ['4521'],
                                'PostedDateTime' => '2026-02-05 14:25:00',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'Buses' => ['Bus' => []],
        'Stations' => ['Station' => []],
    ];

    Http::fake([
        '*' => Http::response($json, 200, ['Content-Type' => 'application/json']),
    ]);

    $service = app(GoTransitFeedService::class);
    $results = $service->fetch();

    expect($results['alerts'])->toHaveCount(1);

    $saag = $results['alerts'][0];

    expect($saag['message_subject'])->toBe('GO Train - Union Station delayed (00:12:00)');
    expect($saag['corridor_or_route'])->toBe('GO Train');
    expect($saag['message_body'])->toContain('Arrival: 3:15 PM');
});

test('it strips html from message body', function () {
    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => [
            'Train' => [
                [
                    'Code' => 'LW',
                    'Name' => 'Lakeshore West',
                    'Notifications' => [
                        'Notification' => [
                            [
                                'SubCategory' => 'TDELAY',
                                'MessageSubject' => 'Test',
                                'MessageBody' => '<p>Delay on <b>Lakeshore West</b> line.</p><br><a href="http://example.com">Details</a>',
                                'PostedDateTime' => '02/05/2026 14:00:00',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'Buses' => ['Bus' => []],
        'Stations' => ['Station' => []],
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    $results = app(GoTransitFeedService::class)->fetch();

    expect($results['alerts'][0]['message_body'])->toBe('Delay on Lakeshore West line.Details');
});

test('it strips embedded style blocks and decodes html entities from message body', function () {
    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => ['Train' => []],
        'Buses' => ['Bus' => []],
        'Stations' => [
            'Station' => [
                [
                    'Code' => 'AG',
                    'Name' => 'Agincourt GO',
                    'Notifications' => [
                        'Notification' => [
                            [
                                'SubCategory' => 'SADIS',
                                'MessageSubject' => 'Power outage at station',
                                'MessageBody' => '<style type="text/css">.masteroverridePublic_En {font-family: "Arial" !important;font-size:10.5pt !important;}.masteroverridePublic_En p {font-family: "Arial" !important;font-size:10.5pt !important;}.masteroverridePublic_En div {font-family: "Arial" !important;font-size:10.5pt !important;}</style><div class="masteroverridePublic_En"><div><span style="font-size:10.5pt;font-family:&quot;Arial&quot;,sans-serif">Local power outage affecting your bus station.&nbsp;&nbsp;To purchase an e-ticket, click here.&nbsp;</span></div></div>',
                                'PostedDateTime' => '02/05/2026 10:00:00',
                                'Status' => 'INIT',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    $results = app(GoTransitFeedService::class)->fetch();

    expect($results['alerts'][0]['message_body'])
        ->toBe('Local power outage affecting your bus station. To purchase an e-ticket, click here.');
});

test('it skips notifications without message subject', function () {
    config(['feeds.allow_empty_feeds' => true]);

    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => [
            'Train' => [
                [
                    'Code' => 'LW',
                    'Name' => 'Lakeshore West',
                    'Notifications' => [
                        'Notification' => [
                            [
                                'SubCategory' => 'TDELAY',
                                'MessageSubject' => '',
                                'MessageBody' => 'Body without subject',
                                'PostedDateTime' => '02/05/2026 14:00:00',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'Buses' => ['Bus' => []],
        'Stations' => ['Station' => []],
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    $results = app(GoTransitFeedService::class)->fetch();

    expect($results['alerts'])->toBeEmpty();
});

test('it skips saag notifications without trip numbers', function () {
    config(['feeds.allow_empty_feeds' => true]);

    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => [
            'Train' => [
                [
                    'Code' => 'LW',
                    'Name' => 'Lakeshore West',
                    'Notifications' => ['Notification' => []],
                    'SaagNotifications' => [
                        'SaagNotification' => [
                            [
                                'Direction' => 'EASTBOUND',
                                'HeadSign' => 'Union',
                                'DelayDuration' => '00:05:00',
                                'TripNumbers' => [],
                                'PostedDateTime' => '2026-02-05 14:25:00',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'Buses' => ['Bus' => []],
        'Stations' => ['Station' => []],
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    $results = app(GoTransitFeedService::class)->fetch();

    expect($results['alerts'])->toBeEmpty();
});

test('it returns empty alerts for empty feed sections', function () {
    config(['feeds.allow_empty_feeds' => true]);

    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => ['Train' => []],
        'Buses' => ['Bus' => []],
        'Stations' => ['Station' => []],
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    $results = app(GoTransitFeedService::class)->fetch();

    expect($results['alerts'])->toBeEmpty();
});

test('it throws exception on http error', function () {
    Http::fake([
        '*' => Http::response('server error', 500),
    ]);

    app(GoTransitFeedService::class)->fetch();
})->throws(RuntimeException::class, 'GO Transit feed request failed: 500');

test('it throws exception on invalid json', function () {
    Http::fake([
        '*' => Http::response('not json', 200),
    ]);

    app(GoTransitFeedService::class)->fetch();
})->throws(RuntimeException::class, 'GO Transit feed returned invalid JSON');

test('it throws exception when LastUpdated is missing', function () {
    Http::fake([
        '*' => Http::response(['Trains' => []], 200),
    ]);

    app(GoTransitFeedService::class)->fetch();
})->throws(RuntimeException::class, 'GO Transit feed missing LastUpdated');

test('it handles missing feed sections gracefully', function () {
    config(['feeds.allow_empty_feeds' => true]);

    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    $results = app(GoTransitFeedService::class)->fetch();

    expect($results['alerts'])->toBeEmpty();
});

test('it throws exception on empty alerts when empty feeds are not allowed', function () {
    config(['feeds.allow_empty_feeds' => false]);

    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => ['Train' => []],
        'Buses' => ['Bus' => []],
        'Stations' => ['Station' => []],
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    app(GoTransitFeedService::class)->fetch();
})->throws(RuntimeException::class, 'GO Transit feed returned zero alerts');

/*
 * Phase 8: GoTransitFeedService Remaining Branch Coverage
 *
 * Gap lines targeted: 102, 107, 128, 133, 153, 158, 178, 183, 201,
 * 232, 237, 260, 280.
 *
 * These are all type-guard early returns / continues for non-array inputs
 * and empty-field skip paths inside the private parse methods.
 */

// Line 102: parseTrains returns early when Trains.Train is not an array
// Line 128: parseBuses returns early when Buses.Bus is not an array
// Line 153: parseStations returns early when Stations.Station is not an array
test('it skips non-array train bus and station collections gracefully', function () {
    config(['feeds.allow_empty_feeds' => true]);

    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => ['Train' => 'not-an-array'],
        'Buses' => ['Bus' => 42],
        'Stations' => ['Station' => 'also-not-array'],
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    $results = app(GoTransitFeedService::class)->fetch();

    expect($results['alerts'])->toBeEmpty();
});

// Line 107: parseTrains skips non-array entries in the Train array
// Line 133: parseBuses skips non-array entries in the Bus array
// Line 158: parseStations skips non-array entries in the Station array
test('it skips non-array entries within train bus and station arrays', function () {
    config(['feeds.allow_empty_feeds' => true]);

    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => [
            'Train' => [
                'not-an-array',
                [
                    'Code' => 'LW',
                    'Name' => 'Lakeshore West',
                    'Notifications' => ['Notification' => []],
                    'SaagNotifications' => ['SaagNotification' => []],
                ],
            ],
        ],
        'Buses' => [
            'Bus' => [
                'not-an-array',
                [
                    'Code' => '12',
                    'Name' => 'Route 12',
                    'Notifications' => ['Notification' => []],
                ],
            ],
        ],
        'Stations' => [
            'Station' => [
                'not-an-array',
                [
                    'Code' => 'UN',
                    'Name' => 'Union Station',
                    'Notifications' => ['Notification' => []],
                ],
            ],
        ],
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    $results = app(GoTransitFeedService::class)->fetch();

    // Valid entries produce no notifications (empty arrays), so alerts are empty
    expect($results['alerts'])->toBeEmpty();
});

// Line 178: parseNotifications returns early when Notification is not an array
test('it skips non-array notification collections', function () {
    config(['feeds.allow_empty_feeds' => true]);

    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => [
            'Train' => [
                [
                    'Code' => 'LW',
                    'Name' => 'Lakeshore West',
                    'Notifications' => ['Notification' => 'not-an-array'],
                    'SaagNotifications' => ['SaagNotification' => []],
                ],
            ],
        ],
        'Buses' => ['Bus' => []],
        'Stations' => ['Station' => []],
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    $results = app(GoTransitFeedService::class)->fetch();

    expect($results['alerts'])->toBeEmpty();
});

// Line 183: parseNotifications skips non-array entries in Notification array
test('it skips non-array entries within notification arrays', function () {
    config(['feeds.allow_empty_feeds' => true]);

    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => [
            'Train' => [
                [
                    'Code' => 'LW',
                    'Name' => 'Lakeshore West',
                    'Notifications' => [
                        'Notification' => [
                            'not-an-array',
                            [
                                'SubCategory' => 'TDELAY',
                                'MessageSubject' => 'Valid notification',
                                'MessageBody' => 'Body',
                                'PostedDateTime' => '02/05/2026 14:00:00',
                            ],
                        ],
                    ],
                    'SaagNotifications' => ['SaagNotification' => []],
                ],
            ],
        ],
        'Buses' => ['Bus' => []],
        'Stations' => ['Station' => []],
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    $results = app(GoTransitFeedService::class)->fetch();

    expect($results['alerts'])->toHaveCount(1);
    expect($results['alerts'][0]['message_subject'])->toBe('Valid notification');
});

// Line 201: parseNotifications skips entries with empty PostedDateTime
test('it skips notifications with empty posted date time', function () {
    config(['feeds.allow_empty_feeds' => true]);

    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => [
            'Train' => [
                [
                    'Code' => 'LW',
                    'Name' => 'Lakeshore West',
                    'Notifications' => [
                        'Notification' => [
                            [
                                'SubCategory' => 'TDELAY',
                                'MessageSubject' => 'No date notification',
                                'MessageBody' => 'Body',
                                'PostedDateTime' => '',
                            ],
                        ],
                    ],
                    'SaagNotifications' => ['SaagNotification' => []],
                ],
            ],
        ],
        'Buses' => ['Bus' => []],
        'Stations' => ['Station' => []],
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    $results = app(GoTransitFeedService::class)->fetch();

    expect($results['alerts'])->toBeEmpty();
});

// Line 232: parseSaagNotifications returns early when SaagNotification is not an array
test('it skips non-array saag notification collections', function () {
    config(['feeds.allow_empty_feeds' => true]);

    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => [
            'Train' => [
                [
                    'Code' => 'LW',
                    'Name' => 'Lakeshore West',
                    'Notifications' => ['Notification' => []],
                    'SaagNotifications' => ['SaagNotification' => 'not-an-array'],
                ],
            ],
        ],
        'Buses' => ['Bus' => []],
        'Stations' => ['Station' => []],
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    $results = app(GoTransitFeedService::class)->fetch();

    expect($results['alerts'])->toBeEmpty();
});

// Line 237: parseSaagNotifications skips non-array entries
test('it skips non-array entries within saag notification arrays', function () {
    config(['feeds.allow_empty_feeds' => true]);

    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => [
            'Train' => [
                [
                    'Code' => 'LW',
                    'Name' => 'Lakeshore West',
                    'Notifications' => ['Notification' => []],
                    'SaagNotifications' => [
                        'SaagNotification' => [
                            'not-an-array',
                            [
                                'Direction' => 'EASTBOUND',
                                'HeadSign' => 'Union Station',
                                'DelayDuration' => '00:12:00',
                                'TripNumbers' => ['4521'],
                                'PostedDateTime' => '2026-02-05 14:25:00',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'Buses' => ['Bus' => []],
        'Stations' => ['Station' => []],
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    $results = app(GoTransitFeedService::class)->fetch();

    expect($results['alerts'])->toHaveCount(1);
    expect($results['alerts'][0]['trip_number'])->toBe('4521');
});

// Line 260: SAAG notification without HeadSign uses fallback subject
test('it uses fallback subject when saag head sign is empty', function () {
    config(['feeds.allow_empty_feeds' => true]);

    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => [
            'Train' => [
                [
                    'Code' => 'LW',
                    'Name' => 'Lakeshore West',
                    'Notifications' => ['Notification' => []],
                    'SaagNotifications' => [
                        'SaagNotification' => [
                            [
                                'Direction' => '',
                                'HeadSign' => '',
                                'DelayDuration' => '00:15:00',
                                'TripNumbers' => ['4521'],
                                'PostedDateTime' => '2026-02-05 14:25:00',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'Buses' => ['Bus' => []],
        'Stations' => ['Station' => []],
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    $results = app(GoTransitFeedService::class)->fetch();

    expect($results['alerts'])->toHaveCount(1);
    expect($results['alerts'][0]['message_subject'])->toBe('Lakeshore West train delayed (00:15:00)');
});

// Line 280: parseSaagNotifications skips entries with empty PostedDateTime
test('it skips saag notifications with empty posted date time', function () {
    config(['feeds.allow_empty_feeds' => true]);

    $json = [
        'LastUpdated' => '2026-02-05T14:30:00-05:00',
        'Trains' => [
            'Train' => [
                [
                    'Code' => 'LW',
                    'Name' => 'Lakeshore West',
                    'Notifications' => ['Notification' => []],
                    'SaagNotifications' => [
                        'SaagNotification' => [
                            [
                                'Direction' => 'EASTBOUND',
                                'HeadSign' => 'Union Station',
                                'DelayDuration' => '00:12:00',
                                'TripNumbers' => ['4521'],
                                'PostedDateTime' => '',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'Buses' => ['Bus' => []],
        'Stations' => ['Station' => []],
    ];

    Http::fake([
        '*' => Http::response($json, 200),
    ]);

    $results = app(GoTransitFeedService::class)->fetch();

    expect($results['alerts'])->toBeEmpty();
});
