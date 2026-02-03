<?php

use App\Services\Alerts\Providers\TransitAlertSelectProvider;

test('transit alert select provider returns empty placeholder results', function () {
    $rows = (new TransitAlertSelectProvider())->select()->get();

    expect($rows)->toBeEmpty();
});
