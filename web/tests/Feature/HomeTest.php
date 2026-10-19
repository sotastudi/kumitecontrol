<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_is_spanish_and_offers_login(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<html lang="es"', false)
            ->assertSee('Iniciar sesión');
    }

    public function test_header_has_the_theme_selector_and_user_button_outside_the_collapsible_menu(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $collapseStart = strpos($html, 'id="nav"');
        $this->assertNotFalse($collapseStart);
        $this->assertLessThan($collapseStart, strpos($html, 'id="theme-menu"'), 'el selector de tema va fuera del menú hamburguesa');
        $this->assertLessThan(strpos($html, 'navbar-toggler'), strpos($html, 'id="theme-menu"'), 'a la izquierda de la hamburguesa');

        // Botón de usuario (solo móvil) hacia el inicio de sesión.
        $this->assertMatchesRegularExpression('#<a class="btn btn-outline-secondary btn-sm d-flex align-items-center user-chip"[^>]*href="'.preg_quote(route('login'), '#').'"[^>]*aria-label="Iniciar sesión"[^>]*data-user-chip>\s*<svg[^>]*>.*?</svg>\s*<span class="user-chip-text">Iniciar sesión</span>#s', $html, 'icono con el texto oculto, visible en todos los anchos');
        $this->assertStringNotContainsString('d-md-none d-flex align-items-center user-chip', $html);
        $this->assertDoesNotMatchRegularExpression('#class="nav-link" href="'.preg_quote(route('login'), '#').'"#', $html, 'el enlace de texto «Iniciar sesión» ya no está en el menú');

        $this->get(route('login'))->assertOk()->assertSee('Registrarse (crear cuenta)')->assertSee(route('register'), false);
    }

    public function test_main_menu_has_no_register_link_for_guests(): void
    {
        $this->get(route('home'))->assertOk()->assertDontSee('href="'.route('register').'"', false);
        $this->get(route('login'))->assertSee(route('register'), false);
    }

    public function test_site_header_is_sticky(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('<nav class="navbar navbar-expand-md bg-body border-bottom mb-4 sticky-top">', false);
    }

    public function test_signed_in_users_get_the_panel_button_in_the_header(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('home'))->assertSee('aria-label="Mi perfil ('.$user->name.')"', false)->assertSee('user-chip-text">'.e($user->name).'<', false)->assertDontSee('user-chip-text">Mi panel', false);
    }

    public function test_logout_is_an_icon_button_in_the_header_that_reveals_its_text_and_not_a_menu_link(): void
    {
        $user = User::factory()->create(['name' => 'Ana Salida']);

        $html = $this->actingAs($user)->get(route('home'))->getContent();

        $this->assertMatchesRegularExpression('#<form method="POST" action="'.preg_quote(route('logout'), '#').'" class="d-flex">.*?<button type="submit" class="btn btn-outline-secondary btn-sm d-flex align-items-center user-chip" data-user-chip data-chip-two-tap aria-label="Cerrar sesión" title="Cerrar sesión \(Ana Salida\)">\s*<svg[^>]*><use href="\#logout"/></svg>\s*<span class="user-chip-text">Cerrar sesión</span>#s', $html);
        $this->assertLessThan(strpos($html, 'id="nav"'), strpos($html, 'href="#logout"'), 'el botón está en la cabecera, fuera del menú plegable');
        $this->assertDoesNotMatchRegularExpression('#nav-link">Cerrar sesión#', $html);
        $this->assertStringNotContainsString('Cerrar sesión (Ana Salida)</button>', $html);
    }
}
