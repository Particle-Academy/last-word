<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Schema\Schema;

/*
 * Façade-level checks: jsonSchema export, version, and the mirror-contract
 * surface every sibling package (holy-sheet, dark-slide) exposes.
 */

it('exports a JSON Schema for LLM tool registration', function () {
    $schema = Agent::jsonSchema();

    expect($schema['type'])->toBe('object')
        ->and($schema['required'])->toBe(['blocks'])
        ->and($schema['properties'])->toHaveKeys(['title', 'blocks'])
        ->and($schema['$defs'])->toHaveKeys(['run', 'listItem', 'block'])
        ->and(count($schema['$defs']['block']['oneOf']))->toBe(count(Schema::BLOCK_TYPES));

    // Must be JSON-serializable as-is.
    expect(json_encode($schema))->toBeString();
});

it('reports its version, which is the PACKAGE version', function () {
    // This used to assert `version() === Schema::VERSION === '0.2.0'`, which
    // pinned the defect rather than the contract: `Schema::VERSION` is the
    // version of the document MODEL and moves when the shape of a `Doc`
    // changes, not when the package ships. Tying them meant `version()` reported
    // 0.2.0 from a 0.4.x release, and the two numbers had no reason to converge.
    //
    // Both still exist and both still mean what they say — they are just no
    // longer the same number by accident. `VersionIsSingleSourcedTest` pins the
    // package one to the changelog.
    expect(Agent::version())->toBe(Agent::VERSION);
    expect(Schema::VERSION)->toMatch('/^\d+\.\d+\.\d+$/');
});

it('accepts an already-valid document through validateAndRepair unchanged', function () {
    $doc = lwCanonical();
    $result = Agent::validateAndRepair($doc);

    expect($result['ok'])->toBeTrue()
        ->and($result['errors'])->toBe([])
        ->and($result['schema'])->toBe($doc);
});
