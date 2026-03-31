<?php

use App\Services\FeedCircuitBreaker;
use App\Services\MiwayGtfsRtAlertsFeedService;
use Carbon\Carbon;
use Google\Transit\Realtime\Alert;
use Google\Transit\Realtime\EntitySelector;
use Google\Transit\Realtime\FeedEntity;
use Google\Transit\Realtime\FeedHeader;
use Google\Transit\Realtime\FeedMessage;
use Google\Transit\Realtime\TimeRange;
use Google\Transit\Realtime\TranslatedString;
use Google\Transit\Realtime\TranslatedString\Translation;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;


function createMiwayFeedMessage(): string
{
    $feed = new FeedMessage();
    
    $header = new FeedHeader();
    $header->setGtfsRealtimeVersion('2.0');
    $header->setTimestamp(1711900000);
    $feed->setHeader($header);
    
    $entity1 = new FeedEntity();
    $entity1->setId('alert_1');
    $alert1 = new Alert();
    $alert1->setCause(Alert\Cause::TECHNICAL_PROBLEM);
    $alert1->setEffect(Alert\Effect::DETOUR);
    $alert1->setUrl((new TranslatedString())->setTranslation([(new Translation())->setLanguage('en')->setText('https://example.com/alert1')]));
    
    $headerText = new TranslatedString();
    $headerText->setTranslation([(new Translation())->setLanguage('en')->setText('Stop 1234 Closed')]);
    $alert1->setHeaderText($headerText);
    
    $descText = new TranslatedString();
    $descText->setTranslation([(new Translation())->setLanguage('en')->setText('Stop 1234 is closed due to construction. Use stop 5678.')]);
    $alert1->setDescriptionText($descText);
    
    $activePeriod = new TimeRange();
    $activePeriod->setStart(1711800000);
    $activePeriod->setEnd(1712000000);
    $alert1->setActivePeriod([$activePeriod]);
    
    $entity1->setAlert($alert1);
    
    $feed->setEntity([$entity1]);
    
    return $feed->serializeToString();
}

it('decodes GTFS-RT protobuf and normalizes alerts successfully', function () {
    Http::fake([
        'https://www.miapp.ca/gtfs_rt/Alerts/Alerts.pb' => Http::response(createMiwayFeedMessage(), 200, [
            'ETag' => '"some-etag"',
            'Last-Modified' => 'Wed, 21 Oct 2015 07:28:00 GMT'
        ]),
    ]);

    $service = app(MiwayGtfsRtAlertsFeedService::class);
    $result = $service->fetch();

    expect($result)->toHaveKey('updated_at')
        ->and($result['updated_at']->timestamp)->toBe(1711900000)
        ->and($result)->toHaveKey('alerts')
        ->and($result['alerts'])->toHaveCount(1)
        ->and($result['alerts'][0])->toMatchArray([
            'external_id' => 'alert_1',
            'header_text' => 'Stop 1234 Closed',
            'description_text' => 'Stop 1234 is closed due to construction. Use stop 5678.',
            'cause' => 'TECHNICAL_PROBLEM',
            'effect' => 'DETOUR',
            'starts_at' => Carbon::createFromTimestamp(1711800000)->utc(),
            'ends_at' => Carbon::createFromTimestamp(1712000000)->utc(),
            'url' => 'https://example.com/alert1',
        ]);
});

it('returns not_modified when 304 is received', function () {
    Http::fake([
        'https://www.miapp.ca/gtfs_rt/Alerts/Alerts.pb' => Http::response('', 304),
    ]);

    $service = app(MiwayGtfsRtAlertsFeedService::class);
    $result = $service->fetch('some-etag', 'Wed, 21 Oct 2015 07:28:00 GMT');

    expect($result)->toHaveKey('not_modified')
        ->and($result['not_modified'])->toBeTrue();
});

it('throws exception on timeout or 500', function () {
    Http::fake([
        'https://www.miapp.ca/gtfs_rt/Alerts/Alerts.pb' => Http::response('', 500),
    ]);

    $service = app(MiwayGtfsRtAlertsFeedService::class);
    $service->fetch();
})->throws(RuntimeException::class);

it('throws exception on malformed protobuf', function () {
    Http::fake([
        'https://www.miapp.ca/gtfs_rt/Alerts/Alerts.pb' => Http::response('not-a-protobuf-string', 200),
    ]);

    $service = app(MiwayGtfsRtAlertsFeedService::class);
    $service->fetch();
})->throws(RuntimeException::class);

it('throws exception on empty payload when allow_empty_feeds is false', function () {
    config(['feeds.allow_empty_feeds' => false]);
    
    $feed = new FeedMessage();
    $header = new FeedHeader();
    $header->setGtfsRealtimeVersion('2.0');
    $header->setTimestamp(1711900000);
    $feed->setHeader($header);
    
    Http::fake([
        'https://www.miapp.ca/gtfs_rt/Alerts/Alerts.pb' => Http::response($feed->serializeToString(), 200),
    ]);

    $service = app(MiwayGtfsRtAlertsFeedService::class);
    $service->fetch();
})->throws(RuntimeException::class);

it('handles allow_empty_feeds successfully', function () {
    config(['feeds.allow_empty_feeds' => true]);
    Http::fake([
        'https://www.miapp.ca/gtfs_rt/Alerts/Alerts.pb' => Http::response('', 200),
    ]);

    $service = app(MiwayGtfsRtAlertsFeedService::class);
    $result = $service->fetch();

    expect($result)->toHaveKey('updated_at')
        ->and($result['alerts'])->toBeEmpty();
});

it('handles missing timestamp and edge cases in alerts', function () {
    $feed = new FeedMessage();
    $header = new FeedHeader();
    // No timestamp set => should use Carbon::now()
    $feed->setHeader($header);
    
    // Entity 1: no alert
    $entity1 = new FeedEntity();
    $entity1->setId('no_alert');
    
    // Entity 2: empty id
    $entity2 = new FeedEntity();
    $entity2->setId(' ');
    $alert2 = new Alert();
    $entity2->setAlert($alert2);

    // Entity 3: missing headerText
    $entity3 = new FeedEntity();
    $entity3->setId('missing_header');
    $alert3 = new Alert();
    $entity3->setAlert($alert3);
    
    // Entity 4: valid alert, fallback language, pdf url, missing startsAt and endsAt
    $entity4 = new FeedEntity();
    $entity4->setId('valid_edge_case');
    $alert4 = new Alert();
    
    $headerText = new TranslatedString();
    $translation1 = new Translation();
    $translation1->setLanguage('fr')->setText('Bonjour');
    $headerText->setTranslation([$translation1]);
    $alert4->setHeaderText($headerText);
    
    $urlText = new TranslatedString();
    $translation2 = new Translation();
    $translation2->setLanguage('en')->setText('https://example.com/file.pdf');
    $urlText->setTranslation([$translation2]);
    $alert4->setUrl($urlText);

    // Provide empty translations for description
    $descText = new TranslatedString();
    $alert4->setDescriptionText($descText);
    
    $entity4->setAlert($alert4);
    
    $feed->setEntity([$entity1, $entity2, $entity3, $entity4]);
    
    Http::fake([
        'https://www.miapp.ca/gtfs_rt/Alerts/Alerts.pb' => Http::response($feed->serializeToString(), 200),
    ]);

    $service = app(MiwayGtfsRtAlertsFeedService::class);
    $result = $service->fetch();

    expect($result['alerts'])->toHaveCount(1)
        ->and($result['alerts'][0])->toMatchArray([
            'external_id' => 'valid_edge_case',
            'header_text' => 'Bonjour',
            'detour_pdf_url' => 'https://example.com/file.pdf',
            'starts_at' => null,
            'ends_at' => null,
            'description_text' => null,
        ]);
});

it('handles empty text in translations', function () {
    $feed = new FeedMessage();
    $header = new FeedHeader();
    $header->setTimestamp(1711900000);
    $feed->setHeader($header);
    
    $entity1 = new FeedEntity();
    $entity1->setId('alert_1');
    $alert1 = new Alert();
    
    $headerText = new TranslatedString();
    $headerText->setTranslation([
        (new Translation())->setLanguage('fr')->setText(' '),
        (new Translation())->setLanguage('en')->setText('Real Title')
    ]);
    $alert1->setHeaderText($headerText);
    
    $entity1->setAlert($alert1);
    $feed->setEntity([$entity1]);
    
    Http::fake([
        'https://www.miapp.ca/gtfs_rt/Alerts/Alerts.pb' => Http::response($feed->serializeToString(), 200),
    ]);

    $service = app(MiwayGtfsRtAlertsFeedService::class);
    $result = $service->fetch();

    expect($result['alerts'][0]['header_text'])->toBe('Real Title');
});
