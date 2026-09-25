<?php

use Rushing\Versioning\MigrationStatus;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\Calendars\Data\SeriesData;
use Splicewire\Beam\Calendars\Enums\SpawnMode;
use Splicewire\Beam\Schema\SchemaLadderMigrator;

/**
 * `calendar/series` v1 → v2, the payload step the version bump registers.
 *
 * v1 is the artifact frozen from the engine tier (`tests/Fixtures/schemas/calendar-series-1.schema.json`
 * is a byte copy of the flagship's committed one). A row written under it stores its spawn template in
 * that tier's vocabulary: `composition_id` and `definition_ref`. v2 changed only the nested `$defs`, so
 * the read path's structural rung must carry such a row forward UNCHANGED and stamp it v2. The readers
 * own the nested vocabulary, and a migration that renamed those keys would strand them. See
 * {@see SeriesData::schemaVersion()}.
 */
const SERIES_AUTHORITY = 'https://app.splicewire.com/schemas';

beforeEach(function () {
    config(['data-schemas.base_uri' => SERIES_AUTHORITY]);

    $this->frozenDir = sys_get_temp_dir().'/cal-series-v1-'.getmypid().'-'.uniqid();
    @mkdir($this->frozenDir, 0775, true);

    $this->registry = new FilesystemSchemaRegistry($this->frozenDir);
    $this->registry->register(json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/schemas/calendar-series-1.schema.json'),
        true,
    ));

    $this->migrator = new SchemaLadderMigrator(
        $this->registry,
        new JsonSchemaGenerator(config('data-schemas', [])),
    );
});

afterEach(function () {
    array_map('unlink', glob($this->frozenDir.'/*') ?: []);
    @rmdir($this->frozenDir);
});

function seriesV1Payload(array $spawn): array
{
    return [
        'kind' => 'series',
        'channel' => 'channel',
        'anchor' => '2026-07-01',
        'rule' => ['freq' => 'WEEKLY', 'interval' => 1],
        'spawn' => $spawn,
        'window' => null,
    ];
}

it('is version 2 of calendar/series', function () {
    expect(SeriesData::schemaName())->toBe('calendar/series')
        ->and(SeriesData::schemaVersion())->toBe(2)
        ->and($this->migrator->currentId(SeriesData::class))->toBe(SERIES_AUTHORITY.'/calendar/series/2');
});

it('carries a v1 reference-mode row forward to v2 without touching its stored spawn keys', function () {
    $payload = seriesV1Payload(['mode' => 'reference', 'composition_id' => 'comp-123']);

    $outcome = $this->migrator->reconcile($payload, SERIES_AUTHORITY.'/calendar/series/1', SeriesData::class);

    // A migrated outcome reads Current at the NEW version, with the candidate to write back.
    expect($outcome->status)->toBe(MigrationStatus::Current)
        ->and($outcome->shouldWriteBack)->toBeTrue()
        ->and($outcome->versionId)->toBe(SERIES_AUTHORITY.'/calendar/series/2')
        ->and($outcome->payload)->toBe($payload);
});

it('carries a v1 generate-mode row forward with its definition_ref still readable', function () {
    $payload = seriesV1Payload(['mode' => 'generate', 'definition_ref' => 'def-9', 'instructions' => 'Read']);

    $outcome = $this->migrator->reconcile($payload, SERIES_AUTHORITY.'/calendar/series/1', SeriesData::class);

    expect($outcome->status)->toBe(MigrationStatus::Current)
        ->and($outcome->shouldWriteBack)->toBeTrue()
        ->and($outcome->payload)->toBe($payload);

    $series = SeriesData::from($outcome->payload);

    expect($series->spawn->mode)->toBe(SpawnMode::Generate)
        ->and($series->spawn->definitionRef)->toBe('def-9');
});

it('treats a row already stamped v2 as current', function () {
    $payload = seriesV1Payload(['mode' => 'generate', 'instructions' => 'Read']);

    $outcome = $this->migrator->reconcile($payload, SERIES_AUTHORITY.'/calendar/series/2', SeriesData::class);

    expect($outcome->status)->toBe(MigrationStatus::Current)
        ->and($outcome->shouldWriteBack)->toBeFalse();
});
