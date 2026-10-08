<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Normal tests: fast, in-memory SQLite
pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Concurrency tests: real MySQL, committed data so parallel processes can see it
pest()->extend(Tests\TestCase::class)
    ->use(DatabaseMigrations::class)
    ->in('Concurrency');

// Safety: never run MySQL tests against anything except a *_test database
if (getenv('DB_CONNECTION') === 'mysql' && !str_ends_with((string) getenv('DB_DATABASE'), '_test')) {
    exit("Refusing to run: the MySQL database name must end in _test.\n");
}