<?php

use App\Services\Alerts\Providers\TransitAlertSelectProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('transit alert select provider returns empty placeholder results', function () {
    $rows = (new TransitAlertSelectProvider())->select()->get();

    expect($rows)->toBeEmpty();
});
