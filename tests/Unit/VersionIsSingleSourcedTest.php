<?php

declare(strict_types=1);

use LastWord\Agent;

/**
 * `version()` must not be able to lie.
 *
 * It is a public API method on every sibling in this family, and on ALL of them
 * it misreported: each PHP `VERSION` constant was stale against its own
 * CHANGELOG, each Node constant stale against its own `package.json`.
 *
 * None of that was carelessness. It is the predictable result of a number
 * living in two files with nothing comparing them — the same failure the
 * envelope's `kit.json` rule exists to stop, and the same one that let a footer
 * drift twelve minor versions behind before anyone noticed.
 *
 * `dark-slide-py` already had this test, and it is the reason that engine was
 * the ONLY one of the family to catch itself: its release preflight went red on
 * the bump. The peers shipped their stale numbers because nothing asked.
 *
 * So there is one constant, and this pins it to the packaging metadata. It
 * costs one assertion and removes the whole class.
 */
it('reports the version it actually ships as', function () {
    $changelog = (string) file_get_contents(__DIR__.'/../../CHANGELOG.md');

    // The newest RELEASE heading — skipping `## [Unreleased]`, which carries no
    // version and is always on top.
    preg_match_all('/^## \[?v?(\d+\.\d+\.\d+)\]?/m', $changelog, $m);

    expect($m[1])->not->toBeEmpty('no released version heading in CHANGELOG.md');
    expect(Agent::VERSION)->toBe(
        $m[1][0],
        'the VERSION constant and the newest changelog entry disagree. Fix the constant — '
        .'do not relax this test, and do not "fix" it by editing the changelog.'
    );
});

it('is a semver triple', function () {
    expect(Agent::VERSION)->toMatch('/^\d+\.\d+\.\d+$/');
});
