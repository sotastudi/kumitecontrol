<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpTest extends TestCase
{
    use RefreshDatabase;

    public function test_help_is_public_and_shows_the_three_guides_and_the_faq(): void
    {
        $this->get(route('help'))->assertOk()
            ->assertSee('Soy organizador')->assertSee('Soy un club')->assertSee('Soy controlador de tatami')
            ->assertSee('Preguntas frecuentes')->assertSee('Buscar en la ayuda')
            ->assertSee('Crea tu cuenta de organizador')->assertSee('Añade tus competidores')->assertSee('Puntúa el combate');
    }

    public function test_help_explains_how_to_show_or_hide_competitor_photos(): void
    {
        $this->get(route('help'))->assertOk()
            ->assertSee('¿Cómo muestro u oculto las fotos de los inscritos?')
            ->assertSee('Mostrar públicamente las fotos de los inscritos')
            ->assertSee('consentimiento');
    }

    public function test_help_icon_is_in_the_header_for_guests_and_users(): void
    {
        $this->get('/')->assertSee('aria-label="Ayuda"', false)->assertSee('href="#help"', false)->assertSee(route('help'), false);
        $this->actingAs(User::factory()->create())->get('/')->assertSee('aria-label="Ayuda"', false);
    }

    public function test_search_finds_steps_and_faqs_without_caring_about_case_or_accents(): void
    {
        $this->get(route('help', ['q' => 'MENORES']))->assertOk()->assertSee('resultados para', false)->assertSee('Cómo inscribo a un menor');
        $this->get(route('help', ['q' => 'representante']))->assertSee('<mark>', false);
        $this->get(route('help', ['q' => 'numeros asignar']))->assertSee('Cierra las inscripciones y asigna los números', false);
        $this->get(route('help', ['q' => 'invitar club']))->assertSee('invita', false);
    }

    public function test_search_without_results_shows_a_message_and_empty_search_shows_everything(): void
    {
        $this->get(route('help', ['q' => 'xyzzy-inexistente']))->assertOk()->assertSee('Sin resultados')->assertSee('No hemos encontrado nada');
        $this->get(route('help', ['q' => '  ']))->assertOk()->assertDontSee('Sin resultados')->assertSee('Soy organizador');
    }

    public function test_search_input_is_escaped(): void
    {
        $this->get(route('help', ['q' => '<script>alert(1)</script>']))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_the_guide_of_the_current_profile_is_highlighted(): void
    {
        $event = Event::factory()->registrationOpen()->create();
        $this->actingAs($this->clubFor($event))->get(route('help'))->assertSee('Tu perfil');
        $this->get(route('help'))->assertSee('btn btn-sm btn-primary">Soy un club', false);
        auth()->logout();
        $this->get(route('help'))->assertDontSee('Tu perfil');
    }

    public function test_guest_sees_no_panel_links_in_the_help(): void
    {
        $this->get(route('help'))->assertOk()
            ->assertDontSee(route('panel.events.index'), false)->assertDontSee(route('panel.athletes.index'), false)
            ->assertSee(route('register'), false);
    }

    public function test_organizer_gets_links_to_the_sections_it_can_open_only(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('help'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('panel.events.create').'"', $html);
        $this->assertStringContainsString('href="'.route('panel.events.index').'"', $html);
        $this->assertStringContainsString('href="'.route('panel.profile.edit').'"', $html);
        // Guías de otros perfiles: ni «Mis competidores» ni invitaciones (solo clubes).
        $this->assertStringNotContainsString('href="'.route('panel.athletes.index').'"', $html);
        $this->assertStringNotContainsString('href="'.route('panel.invitations.index').'"', $html);
        // Con sesión iniciada sobran los enlaces de crear cuenta.
        $this->assertStringNotContainsString('href="'.route('register').'"', $html);
    }

    public function test_club_and_tatami_accounts_get_their_own_links(): void
    {
        $event = Event::factory()->create();
        $club = $this->clubFor($event);

        $html = $this->actingAs($club)->get(route('help'))->getContent();
        $this->assertStringContainsString('href="'.route('panel.athletes.index').'"', $html);
        $this->assertStringContainsString('href="'.route('panel.invitations.index').'"', $html);
        $this->assertStringNotContainsString('href="'.route('panel.events.create').'"', $html);

        $tatami = User::factory()->create();
        $tatami->forceFill(['role' => 'tatami'])->save();
        $html = $this->actingAs($tatami)->get(route('help'))->getContent();
        $this->assertStringContainsString('Mis tatamis', $html);
        $this->assertStringNotContainsString('href="'.route('panel.events.create').'"', $html);
        $this->assertStringNotContainsString('href="'.route('panel.athletes.index').'"', $html);
    }
}
