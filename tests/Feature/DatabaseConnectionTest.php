<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DatabaseConnectionTest extends TestCase
{
    public function testSuiteUsesMySql84(): void
    {
        self::assertSame('mysql', DB::connection()->getDriverName());

        $version = DB::scalar('SELECT VERSION()');

        self::assertIsString($version);
        self::assertStringStartsWith('8.4.', $version);
    }

    public function testDatabaseSessionUsesUtc(): void
    {
        self::assertSame('+00:00', DB::scalar('SELECT @@session.time_zone'));
    }
}
