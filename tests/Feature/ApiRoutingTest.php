<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ApiRoutingTest extends TestCase
{
    public function testUnknownApiRoutesReturnJsonNotFound(): void
    {
        foreach (['/api', '/api/unknown-resource'] as $uri) {
            $response = $this->get($uri);

            $response
                ->assertNotFound()
                ->assertHeader('content-type', 'application/json')
                ->assertJsonStructure(['message'])
                ->assertJsonMissingPath('exception')
                ->assertJsonMissingPath('trace');
        }
    }
}
