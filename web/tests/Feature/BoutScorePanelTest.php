<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Category;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Tournament\BracketGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BoutScorePanelTest extends TestCase
{
    use RefreshDatabase;

    private function setUpBracket(): array
    {
        $user = User::factory()->create();
        $category = Category::factory()->for(Event::factory()->for($user)->create(['is_public' => true, 'status' => EventStatus::InProgress]))->create(['referees' => 3, 'is_published' => true]);
        Registration::factory()->count(4)->confirmed()->forCategory($category)->create();
        app(BracketGenerator::class)->generate($category, fn ($ids) => $ids->values());

        return [$user, $category->event, $category, $category->bouts()->where('round', 1)->where('position', 1)->firstOrFail()];
    }

    public function test_owner_saves_a_score_and_the_winner_is_recorded(): void
    {
        [$user, $event, $category, $bout] = $this->setUpBracket();

        // Con ippon gana al instante y todos los jueces quedan con su color.
        $this->actingAs($user)->put(route('panel.bouts.score', [$event, $category, $bout]), [
            'a_wazari' => 1, 'b_wazari' => 0, 'a_ippon' => 0, 'b_ippon' => 1, 'a_warnings' => 1, 'b_warnings' => 0,
            'abandoned_slot' => '', 'judge_votes' => [0 => 'aka', 2 => 'shiro'],
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $bout->refresh();
        $this->assertSame($bout->slot_b_id, $bout->winner_id);
        $this->assertSame(['shiro', 'shiro', 'shiro'], $bout->judge_votes);
        $this->assertSame(1, $bout->a_warnings);
    }

    public function test_partial_judge_positions_are_kept_when_an_abandonment_decides(): void
    {
        [$user, $event, $category, $bout] = $this->setUpBracket();

        $this->actingAs($user)->put(route('panel.bouts.score', [$event, $category, $bout]), [
            'abandoned_slot' => 'b', 'judge_votes' => [0 => 'aka', 2 => 'shiro'],
        ])->assertSessionHasNoErrors();

        $bout->refresh();
        $this->assertSame($bout->slot_a_id, $bout->winner_id);
        $this->assertSame(['aka', null, 'shiro'], $bout->judge_votes, 'se conserva la posición de cada juez');
    }

    public function test_invalid_input_and_contradictions_show_an_error(): void
    {
        [$user, $event, $category, $bout] = $this->setUpBracket();
        $url = route('panel.bouts.score', [$event, $category, $bout]);

        $this->actingAs($user)->put($url, ['a_wazari' => 5])->assertSessionHasErrors('a_wazari');
        $this->actingAs($user)->put($url, ['judge_votes' => ['rojo']])->assertSessionHasErrors('judge_votes.0');
        $this->actingAs($user)->put($url, ['a_ippon' => 1, 'b_ippon' => 1])->assertSessionHasErrors('error');
        $this->assertNull($bout->fresh()->winner_id);
    }

    public function test_intruder_is_forbidden_and_the_form_shows_one_selector_per_judge(): void
    {
        [$user, $event, $category, $bout] = $this->setUpBracket();

        $this->actingAs(User::factory()->create())->put(route('panel.bouts.score', [$event, $category, $bout]), [])->assertForbidden();

        $html = $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->getContent();
        $this->assertStringContainsString('name="a_wazari" id="score-form-'.$bout->id.'-a-w2" value="2" form="score-form-'.$bout->id.'"', $html, 'wazari 0-1-2 como botones bajo cada participante');
        $this->assertStringNotContainsString('<select name="a_wazari"', $html, 'wazari ya no es un selector');
        $this->assertStringContainsString('class="collapse score-panel-'.$bout->id.' no-print small mt-1"', $html);
        $this->assertStringNotContainsString('<strong class="me-1"', $html, 'sin filas con la etiqueta de texto AKA (rojo)/SHIRO (blanco)');
        $this->assertStringContainsString('Juez 3', $html);
        $this->assertStringNotContainsString('Juez 4', $html);
        $this->assertStringContainsString('Empate: bandera partida, blanca y roja', $html);
        $this->assertStringContainsString('name="judge_votes[2]"', $html);
        $this->assertStringContainsString('flag-hikiwake', $html);
        $this->assertStringNotContainsString('<select name="judge_votes', $html);
    }

    public function test_public_page_shows_points_and_judges_but_never_the_faltas(): void
    {
        [$user, $event, $category, $bout] = $this->setUpBracket();
        $this->actingAs($user)->put(route('panel.bouts.score', [$event, $category, $bout]), [
            'a_wazari' => 1, 'a_warnings' => 4, 'judge_votes' => ['aka', 'aka', 'shiro'],
        ]);
        auth()->logout();

        $html = $this->seeResults($event)->get(route('public.categories.show', [$event, $category]))
            ->assertOk()->assertSee('W:1')->assertDontSee('F:4')->assertDontSee('Falta')->assertDontSee('name="a_warnings"', false)->getContent();

        // Votos de los jueces con banderas (sin la palabra «hikiwake»): roja 2, blanca 1, partida 0.
        $this->assertMatchesRegularExpression('#Jueces:\s*<span class="flag flag-aka[^>]*></span>\s*2 ·\s*<span class="flag flag-shiro[^>]*></span>\s*1 ·\s*<span class="flag flag-hikiwake[^>]*></span>\s*0#', $html);
        $this->assertStringNotContainsString('hikiwake 0', $html);
    }

    public function test_participant_box_shows_w_i_and_f_marks_only_f_for_the_organizer(): void
    {
        [$user, $event, $category, $bout] = $this->setUpBracket();
        $this->actingAs($user)->put(route('panel.bouts.score', [$event, $category, $bout]), [
            'a_wazari' => 1, 'a_warnings' => 3, 'b_ippon' => 1, 'b_warnings' => 0, 'judge_votes' => [],
        ])->assertSessionHasNoErrors();

        $panel = $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->getContent();
        $this->assertStringContainsString('W:1 F:3', $panel);
        $this->assertMatchesRegularExpression('#score-marks">I</small>#', $panel);
        $this->assertStringContainsString('name="a_warnings" id="score-form-'.$bout->id.'-a-warnings" form="score-form-'.$bout->id.'"', $panel);
        foreach (['Wazari', 'Ippon', 'Falta'] as $label) {
            $this->assertMatchesRegularExpression('#score-field-label"[^>]*>'.$label.'<#', $panel, 'nombre completo encima de cada bloque');
        }
        $this->assertStringNotContainsString('Amonest', $panel);

        auth()->logout();
        $public = $this->seeResults($event)->get(route('public.categories.show', [$event, $category]))->getContent();
        $this->assertStringContainsString('W:1', $public);
        $this->assertStringNotContainsString('F:3', $public);
        $this->assertMatchesRegularExpression('#score-marks">I</small>#', $public);
    }

    public function test_faltas_accept_only_0_to_4(): void
    {
        [$user, $event, $category, $bout] = $this->setUpBracket();
        $this->actingAs($user)->put(route('panel.bouts.score', [$event, $category, $bout]), ['a_warnings' => 5])->assertSessionHasErrors('a_warnings');
        $this->actingAs($user)->put(route('panel.bouts.score', [$event, $category, $bout]), ['a_warnings' => 4, 'judge_votes' => ['aka', 'aka', 'shiro']])->assertSessionHasNoErrors();
    }

    public function test_aka_and_shiro_are_colour_chips_with_hidden_text_inside_the_box(): void
    {
        [$user, $event, $category] = $this->setUpBracket();

        $html = $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->getContent();

        $this->assertStringContainsString('class="side-chip side-aka"', $html);
        $this->assertStringContainsString('class="side-chip side-shiro"', $html);
        $this->assertStringContainsString('<span class="side-text">AKA</span>', $html);
        $this->assertStringContainsString('<span class="side-text">SHIRO</span>', $html);
        $this->assertStringContainsString('aria-label="SHIRO (blanco)"', $html);
        $this->assertStringNotContainsString('data-bs-toggle="tooltip"', $html);
        $this->assertDoesNotMatchRegularExpression('#class="badge[^"]*"[^>]*>\s*(AKA|SHIRO)\s*<#', $html);
    }

    public function test_scoring_help_is_a_collapsible_behind_a_question_mark_button(): void
    {
        [$user, $event, $category, $bout] = $this->setUpBracket();

        $html = $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->getContent();

        $this->assertStringContainsString('data-bs-target="#score-help-'.$bout->id.'"', $html);
        $this->assertMatchesRegularExpression('#<div class="collapse" id="score-help-'.$bout->id.'">\s*<p class="text-muted mb-0">Jueces: bandera blanca <span class="flag flag-shiro#', $html);
        $this->assertStringContainsString('2 wazari = 1 ippon', $html);
        $this->assertDoesNotMatchRegularExpression('#<p class="text-muted mb-2">Jueces#', $html, 'la ayuda ya no se muestra siempre');
    }

    public function test_controls_are_stacked_w_then_ippon_then_f_and_two_wazari_check_ippon_in_the_form_and_on_the_server(): void
    {
        [$user, $event, $category, $bout] = $this->setUpBracket();

        $html = $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->getContent();
        $this->assertMatchesRegularExpression('#name="a_wazari".*?data-ippon-for=.*?name="a_warnings"#s', $html, 'orden W, I, F');
        $this->assertStringContainsString('data-score-save', $html);
        $this->assertStringContainsString('data-wazari-for="score-form-'.$bout->id.'-a"', $html);
        $this->assertStringContainsString('data-ippon-for="score-form-'.$bout->id.'-a"', $html);

        $this->actingAs($user)->put(route('panel.bouts.score', [$event, $category, $bout]), ['a_wazari' => 0, 'b_wazari' => 2, 'a_ippon' => 0, 'b_ippon' => 0])
            ->assertSessionHasNoErrors();
        $bout->refresh();
        $this->assertTrue($bout->b_ippon);
        $this->assertSame($bout->slot_b_id, $bout->winner_id);
    }

    public function test_when_one_participant_has_ippon_the_opponents_ippon_and_two_wazari_are_blocked(): void
    {
        [$user, $event, $category, $bout] = $this->setUpBracket();
        $show = fn () => $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->getContent();

        // Sin ippon: nada bloqueado en este combate.
        $html = $show();
        $this->assertDoesNotMatchRegularExpression('#data-ippon-for="score-form-'.$bout->id.'-[ab]"[^>]*disabled#', $html);

        $this->actingAs($user)->put(route('panel.bouts.score', [$event, $category, $bout]), ['a_ippon' => 1])->assertSessionHasNoErrors();
        $html = $show();
        $this->assertMatchesRegularExpression('#data-ippon-for="score-form-'.$bout->id.'-b"[^>]*disabled#', $html, 'ippon del rival bloqueado');
        $this->assertDoesNotMatchRegularExpression('#data-ippon-for="score-form-'.$bout->id.'-a"[^>]*disabled#', $html);
        $this->assertMatchesRegularExpression('#name="b_wazari" id="score-form-'.$bout->id.'-b-w2" value="2"[^>]*disabled#', $html, '2 wazari del rival bloqueado');

        // El servidor también lo impide aunque se manipule el formulario.
        $this->actingAs($user)->put(route('panel.bouts.score', [$event, $category, $bout]), ['a_ippon' => 1, 'b_wazari' => 2])->assertSessionHasErrors('error');
        $this->actingAs($user)->put(route('panel.bouts.score', [$event, $category, $bout]), ['a_wazari' => 2, 'b_ippon' => 1])->assertSessionHasErrors('error');
        $this->assertSame(0, $bout->fresh()->b_wazari);

        // Rectificando al primero se desbloquea al otro.
        $this->actingAs($user)->put(route('panel.bouts.score', [$event, $category, $bout]), ['a_ippon' => 0, 'judge_votes' => ['aka', 'aka', 'aka']])->assertSessionHasNoErrors();
        $this->assertDoesNotMatchRegularExpression('#data-ippon-for="score-form-'.$bout->id.'-[ab]"[^>]*disabled#', $show());
    }

    public function test_saving_requires_every_judge_flag_unless_ippon_or_abandonment_decide(): void
    {
        [$user, $event, $category, $bout] = $this->setUpBracket();
        $url = route('panel.bouts.score', [$event, $category, $bout]);

        $this->actingAs($user)->put($url, ['a_wazari' => 1, 'judge_votes' => ['aka', 'aka']])->assertSessionHasErrors('error');
        $this->assertSame(0, $bout->fresh()->a_wazari, 'no se guarda nada sin los votos');

        $this->actingAs($user)->put($url, ['judge_votes' => ['aka', 'aka', 'shiro']])->assertSessionHasNoErrors();
        $this->assertSame($bout->fresh()->slot_a_id, $bout->fresh()->winner_id);

        $html = $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->getContent();
        $this->assertStringContainsString('data-score-hint', $html);
        $this->assertStringContainsString('Marca la bandera de cada juez', $html);
    }

    public function test_scores_can_be_sent_as_json_for_the_offline_queue_and_errors_come_back_as_json(): void
    {
        [$user, $event, $category, $bout] = $this->setUpBracket();
        $url = route('panel.bouts.score', [$event, $category, $bout]);

        $this->actingAs($user)->putJson($url, ['a_ippon' => 1])
            ->assertOk()->assertJson(['ok' => true, 'winner' => $bout->slot_a_id]);

        $second = $category->bouts()->where('round', 1)->where('position', 2)->firstOrFail();
        $this->actingAs($user)->putJson(route('panel.bouts.score', [$event, $category, $second]), ['a_wazari' => 1])
            ->assertStatus(422)->assertJson(['ok' => false])->assertJsonPath('message', fn ($m) => str_contains($m, 'Faltan los votos'));
        $this->actingAs($user)->putJson($url, ['a_wazari' => 9])->assertStatus(422)->assertJsonValidationErrors('a_wazari');
        $this->actingAs(User::factory()->create())->putJson($url, ['a_ippon' => 1])->assertForbidden();
    }

    public function test_a_fresh_csrf_token_is_available_to_signed_in_users_for_resending_saved_scores(): void
    {
        [$user] = $this->setUpBracket();

        $this->getJson(route('panel.csrf'))->assertUnauthorized();
        $this->actingAs($user)->getJson(route('panel.csrf'))->assertOk()->assertJsonStructure(['token']);
    }

    public function test_pages_with_scoring_include_the_sync_indicator_and_the_per_bout_status(): void
    {
        [$user, $event, $category] = $this->setUpBracket();

        $html = $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->getContent();

        $this->assertStringContainsString('id="sync-indicator"', $html);
        $this->assertStringContainsString('data-sync-state="synced"', $html);
        $this->assertStringContainsString('#cloud-check', $html);
        $this->assertStringContainsString('data-score-status', $html);
        $this->assertStringContainsString('name="csrf-token"', $html);
    }

    public function test_the_sync_indicator_carries_the_csrf_url_so_the_queue_works_when_the_app_lives_under_a_path_prefix(): void
    {
        [$user, $event, $category] = $this->setUpBracket();

        $html = $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->getContent();

        $this->assertStringContainsString('data-csrf-url="'.route('panel.csrf').'"', $html);
    }
}
