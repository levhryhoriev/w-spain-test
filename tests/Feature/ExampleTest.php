<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function testApplicationReturnsSuccessfulResponse(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function testApplicationUsesUtc(): void
    {
        self::assertSame('UTC', config('app.timezone'));
        self::assertSame('UTC', date_default_timezone_get());
    }

    public function testApplicationEnablesStrictEloquentBehaviorOutsideProduction(): void
    {
        self::assertTrue(Model::preventsLazyLoading());
        self::assertTrue(Model::preventsSilentlyDiscardingAttributes());
        self::assertTrue(Model::preventsAccessingMissingAttributes());
    }
}
