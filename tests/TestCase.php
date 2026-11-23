<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests that refresh the database roll back changes on every connection
     * Fort Vale writes to: the default one (people, actions) and the tickets
     * one (Oracle by default).
     *
     * @var array<int, string|null>
     */
    protected array $connectionsToTransact = [null];

    protected function setUp(): void
    {
        // Read from the environment because the configuration isn't loaded yet
        // when RefreshDatabase starts its transactions inside parent::setUp().
        $default = getenv('DB_CONNECTION') ?: null;
        $tickets = getenv('TICKETS_DB_CONNECTION') ?: 'oracle';

        $this->connectionsToTransact = $tickets === $default ? [$default] : [$default, $tickets];

        parent::setUp();
    }
}
