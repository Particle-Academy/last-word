<?php

declare(strict_types=1);

use LastWord\Tests\Support\UnchangedOutput;

/**
 * Nothing that existed before 0.16.0 writes differently.
 *
 * `templateFirstPageOnly` absent or false, no `firstHeader` / `evenHeader`, no
 * `meta`, and none of the new field tokens: the output is what 0.15.0 wrote,
 * part for part and in the same archive order. The goldens were produced by
 * running released 0.15.0 (4172ed5) over {@see UnchangedOutput::cases()}.
 *
 * The body's `{date}` / `{author}` and the header's `{Title} {titles} {date:}
 * {foo}` are in the fixtures on purpose: the body keeps every token literal,
 * and none of the header's is a token (names are lower-case, exact, and a
 * date format is never empty), so they stay literal text in a band too.
 */
const UNCHANGED_DIGESTS = [
    'slot bands with page fields' => '20a846e21564e495a47962fa25c56a5053e3115c416585aef79e86819ad0416b',
    'blocks band with page fields' => 'aeaefd34e1c9669c8e368fa2c30d748b127f672bb706e62018e457ec5d251518',
    'title only' => '987a45b31a5c49fb1f4006417e10689f5f6edd0de277b054b60500fbc303a047',
    'letterhead, every page' => 'cce13a9819abdde91de32c2473a82c08371356ffea2a00709d0024e610a4dd3f',
    'letterhead with its own first page' => '2bc4acb8bb5b683c67f21f7d319255c1ec12d22bf637c8beb8eeb45ce32dd70e',
    'letterhead with an even page' => '077e46887b44832b9d68a03d8b1cede0fca76a03e111bcc7b1ad987f11d3dac2',
];

it('writes what 0.15.0 wrote', function (string $name): void {
    $case = UnchangedOutput::cases()[$name];

    expect(UnchangedOutput::digest(UnchangedOutput::write($case)))->toBe(UNCHANGED_DIGESTS[$name]);
})->with(array_keys(UNCHANGED_DIGESTS));

it('writes the same with templateFirstPageOnly false as without it', function (string $name): void {
    $case = UnchangedOutput::cases()[$name];
    $off = $case;
    $off['options']['templateFirstPageOnly'] = false;

    expect(UnchangedOutput::write($off))->toBe(UnchangedOutput::write($case));
})->with(array_keys(UNCHANGED_DIGESTS));
