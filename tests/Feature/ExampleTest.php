<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root URL requires authentication and redirects to login.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        // Unauthenticated requests redirect to /login
        $response->assertRedirect('/login');
    }
}
