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
                                'ArrivalTimeTimeDisplay' => '3:15 PM',
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
