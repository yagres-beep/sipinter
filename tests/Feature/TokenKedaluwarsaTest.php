<?php

namespace Tests\Feature;

use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TokenKedaluwarsaTest extends TestCase
{
    // Middleware CSRF dilewati saat unit test, jadi token basi disimulasikan lewat rute yang
    // langsung melempar TokenMismatchException — persis yang dilakukan middleware-nya.
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->post('/_uji-token-basi', fn () => throw new TokenMismatchException);
    }

    public function test_form_dengan_token_basi_dikembalikan_ke_halaman_sebelumnya(): void
    {
        $this->from(route('login'))
            ->post('/_uji-token-basi', ['username' => 'budi', 'password' => 'rahasia'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('sesi')
            ->assertSessionHasInput('username', 'budi')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_request_livewire_tetap_419_agar_ditangani_livewire(): void
    {
        $this->withHeader('X-Livewire', 'true')
            ->post('/_uji-token-basi')
            ->assertStatus(419);
    }
}
