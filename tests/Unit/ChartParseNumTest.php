<?php

declare(strict_types=1);

use LastWord\Helpers\Chart;

it('reads a very long run of digits as 0, not as a pattern that gave up', function () {
    $started = hrtime(true);
    expect(Chart::parseNum(str_repeat('9', 100_000).'x'))->toBe(0);
    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0);
    expect(preg_last_error())->toBe(PREG_NO_ERROR);
});

it('reads every shape of decimal, and anything else as 0', function (string $text, int|float $want) {
    expect(Chart::parseNum($text))->toBe($want);
})->with([
    ['5', 5],
    ['5.', 5],
    ['5.25', 5.25],
    ['.5', 0.5],
    ['-5.5', -5.5],
    ['+5', 5],
    ['1e2', 100],
    ['5.e2', 500],
    ['.', 0],
    ['0x10', 0],
    ['1.2.3', 0],
]);
