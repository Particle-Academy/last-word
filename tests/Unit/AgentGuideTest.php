<?php

declare(strict_types=1);

/**
 * The agent guide ships, and is the same bytes in all three engines.
 *
 * Each engine pins this hash over LF-normalised content, so a one-sided edit
 * fails a build instead of telling a model one thing in PHP and another in
 * Node. Edit all three copies, then all three constants.
 */
const AGENT_GUIDE_SHA256 = '5f897d2a73063379598a31aad83aea7935c84ffc4a23026d8ffaf3e5034aa31a';

it('returns the guide, and it is the shared one', function () {
    $guide = LastWord\Agent::guide();

    expect(hash('sha256', str_replace("\r\n", "\n", $guide)))->toBe(AGENT_GUIDE_SHA256)
        ->and($guide)->toStartWith('# Agent guide:');
});
