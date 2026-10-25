<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app['db']->connection();
        $database = $connection->getDatabaseName();
        if ($connection->getDriverName() !== 'sqlite' || $database !== ':memory:') {
            if (! preg_match('/(_test|testing)(_|$)/', $database)) {
                throw new RuntimeException('Refusing to run destructive tests outside a dedicated test database.');
            }
        }

        return $app;
    }
}
