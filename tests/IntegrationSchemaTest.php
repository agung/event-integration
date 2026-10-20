<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

it('creates channel and per-message activity tables from the package migration', function (): void {
    $capsule = new Capsule(new Container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    Facade::setFacadeApplication($capsule->getContainer());
    $capsule->getContainer()->instance(
        'db.schema',
        $capsule->getConnection()->getSchemaBuilder(),
    );

    $migration = require dirname(__DIR__).'/database/migrations/2026_10_20_000001_create_event_integration_tables.php';
    $migration->up();

    expect(Schema::hasTable('event_integration_channels'))->toBeTrue()
        ->and(Schema::hasTable('event_integration_activities'))->toBeTrue()
        ->and(Schema::hasColumns('event_integration_channels', [
            'owner_type',
            'owner_id',
            'event_name',
            'filters',
            'output_format',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('event_integration_activities', [
            'channel_id',
            'payload',
            'status',
            'idempotency_key',
            'attempt_count',
        ]))->toBeTrue();
});
