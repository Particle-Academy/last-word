<?php

declare(strict_types=1);

/**
 * The agent guide ships, and is the same bytes in all three engines.
 *
 * Each engine pins this hash over LF-normalised content, so a one-sided edit
 * fails a build instead of telling a model one thing in PHP and another in
 * Node. Edit all three copies, then all three constants.
 */
const AGENT_GUIDE_SHA256 = 'df27c8208737d11f7daceca317d8ab7ed937b9904af165383424c6f90d860cfb';

it('returns the guide, and it is the shared one', function () {
    $guide = LastWord\Agent::guide();

    expect(hash('sha256', str_replace("\r\n", "\n", $guide)))->toBe(AGENT_GUIDE_SHA256)
        ->and($guide)->toStartWith('# Agent guide:');
});
