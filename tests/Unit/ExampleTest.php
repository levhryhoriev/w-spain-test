<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function testPhpRuntimeProvidesPdoMysql(): void
    {
        self::assertTrue(extension_loaded('pdo_mysql'));
    }
}
