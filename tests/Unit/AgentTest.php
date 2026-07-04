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

it('reports its version', function () {
    expect(Agent::version())->toBe('0.2.0')
        ->and(Agent::version())->toBe(Schema::VERSION);
});

it('accepts an already-valid document through validateAndRepair unchanged', function () {
    $doc = lwCanonical();
    $result = Agent::validateAndRepair($doc);

    expect($result['ok'])->toBeTrue()
        ->and($result['errors'])->toBe([])
        ->and($result['schema'])->toBe($doc);
});
