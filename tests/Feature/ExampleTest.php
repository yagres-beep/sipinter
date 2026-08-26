<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * "/" (routes/web.php) tidak pernah merender halaman sendiri — selalu redirect
     * ke "login" (tamu) atau "dashboard" (sudah login), lihat routes/web.php:12-14.
     */
    public function test_root_redirects_guest_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
