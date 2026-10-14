<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalamanLegalTest extends TestCase
{
    use RefreshDatabase;

    public function test_kebijakan_privasi_dan_ketentuan_layanan_terbuka_tanpa_login(): void
    {
        $this->get('/kebijakan-privasi')->assertOk()->assertSee('Kebijakan Privasi')->assertSee('Limited Use');
        $this->get('/ketentuan-layanan')->assertOk()->assertSee('Ketentuan Layanan');
    }

    public function test_halaman_login_menautkan_kedua_halaman(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee(route('kebijakan-privasi'))
            ->assertSee(route('ketentuan-layanan'));
    }
}
