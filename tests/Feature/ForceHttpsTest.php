<?php

use App\Providers\AppServiceProvider;

test('FORCE_HTTPS makes generated URLs use https', function () {
    config(['app.force_https' => true]);

    (new AppServiceProvider(app()))->boot();

    expect(url('/entries'))->toStartWith('https://');
});

test('URLs keep the request scheme by default', function () {
    expect(config('app.force_https'))->toBeFalse()
        ->and(url('/entries'))->toStartWith('http://');
});
