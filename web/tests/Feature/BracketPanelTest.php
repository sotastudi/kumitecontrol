<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Category;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Registrations\BibNumberAssigner;
use App\Tournament\PaperBracket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBrackets;
use Tests\TestCase;

class BracketPanelTest extends TestCase
{
    use BuildsBrackets, RefreshDatabase;

    private function owned(int $n): array
    {
        $user = User::factory()->create();
        $category = Category::factory()->for(Event::factory()->for($user))->create();
        Registration::factory()->count($n)->confirmed()->forCategory($category)->create();
        $this->readyForBrackets($category->event);

        return [$user, $category->event, $category];
    }

    public function test_owner_generates_and_regenerates_the_bracket(): void
    {
        [$user, $event, $category] = $this->owned(5);

        $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]))->assertRedirect();
        $this->assertSame(5, $category->bouts()->count()); // previa, 2 semifinales, final y bronce

        $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]))->assertRedirect();
        $this->assertSame(5, $category->bouts()->count());
        $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->assertOk()->assertSee('Semifinales');
    }

    public function test_single_confirmed_participant_shows_manual_resolution_message(): void
    {
        [$user, $event, $category] = $this->owned(1);

        $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]))
            ->assertSessionHasErrors('error');
        $this->assertSame(0, $category->bouts()->count());
        $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))
            ->assertOk()->assertSee('manualmente');
    }

    public function test_owner_records_a_result_and_the_winner_advances(): void
    {
        [$user, $event, $category] = $this->owned(4);
        $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]));
        $semi = $category->bouts()->where('round', 1)->orderBy('position')->first();

        $this->actingAs($user)->put(route('panel.bouts.result', [$event, $category, $semi]), ['winner_id' => $semi->slot_a_id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($semi->slot_a_id, $category->bouts()->where('round', 2)->where('is_bronze', false)->first()->slot_a_id);
    }

    public function test_tampered_winner_ids_are_rejected_without_changes(): void
    {
        [$user, $event, $category] = $this->owned(4);
        $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]));
        $semi = $category->bouts()->where('round', 1)->orderBy('position')->first();
        $foreign = $this->generated(2)->registrations()->first();

        foreach ([$foreign->id, 0, 'abc', ''] as $bad) {
            $this->actingAs($user)->put(route('panel.bouts.result', [$event, $category, $semi]), ['winner_id' => $bad])
                ->assertSessionHasErrors();
        }
        $this->assertNull($semi->fresh()->winner_id);
    }

    public function test_bout_of_another_category_is_not_reachable_through_my_category(): void
    {
        [$user, $event, $category] = $this->owned(4);
        $other = $this->generated(4);
        $foreignBout = $other->bouts()->first();

        $this->actingAs($user)->put(route('panel.bouts.result', [$event, $category, $foreignBout]), ['winner_id' => $foreignBout->slot_a_id])
            ->assertNotFound();
    }

    public function test_intruder_cannot_generate_swap_or_record(): void
    {
        [$user, $event, $category] = $this->owned(4);
        $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]));
        $bout = $category->bouts()->first();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post(route('panel.brackets.store', [$event, $category]))->assertForbidden();
        $this->actingAs($intruder)->post(route('panel.brackets.swap', [$event, $category]), ['registration_a' => 1, 'registration_b' => 2])->assertForbidden();
        $this->actingAs($intruder)->put(route('panel.bouts.result', [$event, $category, $bout]), ['winner_id' => $bout->slot_a_id])->assertForbidden();
    }

    public function test_swap_via_http_and_refusal_after_results(): void
    {
        [$user, $event, $category] = $this->owned(4);
        $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]));
        $s1 = $category->bouts()->where('round', 1)->orderBy('position')->get();
        [$a, $b] = [$s1[0]->slot_a_id, $s1[1]->slot_a_id];

        $this->actingAs($user)->post(route('panel.brackets.swap', [$event, $category]), ['registration_a' => $a, 'registration_b' => $b])
            ->assertSessionHasNoErrors();
        $this->assertSame($b, $s1[0]->fresh()->slot_a_id);

        $this->actingAs($user)->put(route('panel.bouts.result', [$event, $category, $s1[0]]), ['winner_id' => $s1[0]->fresh()->slot_a_id]);
        $this->actingAs($user)->post(route('panel.brackets.swap', [$event, $category]), ['registration_a' => $a, 'registration_b' => $b])
            ->assertSessionHasErrors('error');
    }

    public function test_adjust_dropdown_lists_all_participants_including_exempt(): void
    {
        [$user, $event, $category] = $this->owned(20);
        $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]));

        $html = $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->assertOk()->getContent();
        preg_match('/<select[^>]*id="registration_a".*?<\/select>/s', $html, $m);

        $this->assertSame(20, substr_count($m[0], '<option'));
    }

    public function test_stale_bracket_warning_is_shown(): void
    {
        [$user, $event, $category] = $this->owned(4);
        $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]));
        Registration::factory()->confirmed()->forCategory($category)->create();

        $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->assertSee('desactualizado');
    }

    public function test_stale_after_results_shows_manual_notice_without_regenerate(): void
    {
        [$user, $event, $category] = $this->owned(4);
        $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]));
        $semi = $category->bouts()->where('round', 1)->orderBy('position')->first();
        $this->actingAs($user)->put(route('panel.bouts.result', [$event, $category, $semi]), ['winner_id' => $semi->slot_a_id]);
        Registration::factory()->confirmed()->forCategory($category)->create();

        $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))
            ->assertSee('resuélvelos manualmente')->assertDontSee('desactualizado')->assertDontSee('Regenerar cuadro');
    }

    public function test_stale_without_results_rejects_results_and_hides_gana_buttons(): void
    {
        [$user, $event, $category] = $this->owned(4);
        $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]));
        $semi = $category->bouts()->where('round', 1)->orderBy('position')->first();
        Registration::find($semi->slot_b_id)->update(['status' => 'cancelled']);

        $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))
            ->assertOk()->assertSee('desactualizado')->assertDontSee('Gana');

        $this->actingAs($user)->put(route('panel.bouts.result', [$event, $category, $semi]), ['winner_id' => $semi->slot_a_id])
            ->assertSessionHasErrors('error');
        $this->assertNull($semi->fresh()->winner_id);
        $this->assertFalse($category->hasResults());
    }

    public function test_validation_error_on_winner_is_visible_after_redirect(): void
    {
        [$user, $event, $category] = $this->owned(4);
        $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]));
        $bout = $category->bouts()->where('round', 1)->firstOrFail();
        $show = route('panel.brackets.show', [$event, $category]);

        $this->actingAs($user)->from($show)
            ->put(route('panel.bouts.result', [$event, $category, $bout]), ['winner_id' => 'abc'])
            ->assertRedirect($show)->assertSessionHasErrors('winner_id');

        $this->followingRedirects()->actingAs($user)->from($show)
            ->put(route('panel.bouts.result', [$event, $category, $bout]), ['winner_id' => 'abc'])
            ->assertSee('alert-danger', false);
    }

    public function test_bracket_is_not_generated_until_registrations_are_closed_and_numbers_assigned(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for(Event::factory()->for($user)->registrationOpen())->create();
        Registration::factory()->count(4)->confirmed()->forCategory($category)->create();
        $event = $category->event;

        // Inscripciones abiertas.
        $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->assertOk()->assertSee('Cierra las inscripciones del evento');
        $this->post(route('panel.brackets.store', [$event, $category]))->assertSessionHasErrors('error');
        $this->assertSame(0, $category->bouts()->count());

        // Cerradas pero sin números.
        $event->update(['status' => EventStatus::RegistrationClosed]);
        $this->get(route('panel.brackets.show', [$event, $category]))->assertSee('Asigna los números de competidor');
        $this->post(route('panel.brackets.store', [$event, $category]))->assertSessionHasErrors('error');
        $this->assertSame(0, $category->bouts()->count());

        // Con números asignados ya se genera; un confirmado nuevo sin número vuelve a bloquear la regeneración.
        app(BibNumberAssigner::class)->assign($event);
        $this->post(route('panel.brackets.store', [$event, $category]))->assertSessionHasNoErrors();
        $this->assertGreaterThan(0, $category->bouts()->count());

        Registration::factory()->confirmed()->forCategory($category)->create();
        $this->post(route('panel.brackets.store', [$event, $category]))->assertSessionHasErrors('error');
        $this->get(route('panel.brackets.show', [$event, $category]))->assertSee('1 inscritos confirmados sin número');
    }

    public function test_prelim_round_shows_rest_notice_labels_and_exempt_slots(): void
    {
        [$user, $event, $category] = $this->owned(20);
        $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]));

        $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->assertOk()
            ->assertSee('Ronda previa')->assertSee('Octavos de final')
            ->assertSee('4 combates (8 competidores) · 12 exentos')->assertSee('20–30 minutos')
            ->assertSee('Exento');
    }

    public function test_power_of_two_bracket_has_no_prelim_notice(): void
    {
        [$user, $event, $category] = $this->owned(16);
        $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]));

        $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->assertOk()
            ->assertSee('Octavos de final')->assertDontSee('Ronda previa')->assertDontSee('20–30 minutos')->assertDontSee('Exento');
    }

    public function test_prelim_bouts_sit_on_the_row_of_the_bout_they_feed(): void
    {
        [$user, $event, $category] = $this->owned(20);
        $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]));
        $html = $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->getContent();

        preg_match_all('/class="bracket-cell ([^"]*)"\s+data-bout="(\d+)"\s+style="grid-column: (\d+); grid-row: (\d+) \/ (\d+)"/', $html, $m, PREG_SET_ORDER);
        $cells = collect($m)->keyBy(fn ($c) => (int) $c[2]);
        $bouts = $category->bouts()->where('is_bronze', false)->get();
        $this->assertCount($bouts->count(), $cells);

        // Octavos: 8 combates en 8 filas propias; la previa, en la fila de su combate de octavos y con línea recta hacia él.
        $octavos = $bouts->where('round', 2);
        $this->assertSame(range(2, 9), $octavos->map(fn ($b) => (int) $cells[$b->id][4])->sort()->values()->all());
        foreach ($bouts->where('round', 1) as $prelim) {
            $this->assertSame('1', $cells[$prelim->id][3]);
            $this->assertSame([$cells[$prelim->next_bout_id][4], $cells[$prelim->next_bout_id][5]], [$cells[$prelim->id][4], $cells[$prelim->id][5]]);
            $this->assertStringContainsString('link-single', $cells[$prelim->id][1]);
        }
        // Cuartos abarcan dos filas de octavos.
        foreach ($bouts->where('round', 3) as $bout) {
            $this->assertSame(2, (int) $cells[$bout->id][5] - (int) $cells[$bout->id][4]);
            $this->assertStringContainsString('has-in', $cells[$bout->id][1]);
        }
    }

    public function test_bracket_pages_render_for_prelim_and_power_of_two_sizes(): void
    {
        foreach ([3, 5, 6, 20, 31, 33, 8] as $n) {
            [$user, $event, $category] = $this->owned($n);
            $category->forceFill(['tatami' => 1, 'tatami_position' => 1, 'is_published' => true])->save();
            $event->forceFill(['is_public' => true, 'status' => EventStatus::InProgress])->save();
            $this->actingAs($user)->post(route('panel.brackets.store', [$event, $category]))->assertSessionHasNoErrors();

            $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->assertOk();
            $this->actingAs($user)->get(route('panel.brackets.pdf', [$event, $category]))->assertOk();
            $this->actingAs($user)->get(route('panel.tatamis.show', [$event, 1]))->assertOk();
            auth()->logout();
            $this->seeResults($event)->get(route('public.categories.show', [$event, $category]))->assertOk();
        }
    }

    public function test_old_bracket_with_bye_bouts_still_renders_without_prelim_notice(): void
    {
        // Cuadro guardado con el modelo antiguo: 3 inscritos, ronda 1 completa con un pase y final.
        [$user, $event, $category] = $this->owned(3);
        [$r1, $r2, $r3] = $category->registrations()->orderBy('id')->pluck('id')->all();
        $final = $category->bouts()->create(['round' => 2, 'position' => 1, 'number' => 2, 'required_participants' => 2, 'slot_b_id' => $r3]);
        $bout = $category->bouts()->create(['round' => 1, 'position' => 1, 'number' => 1, 'required_participants' => 2, 'slot_a_id' => $r1, 'slot_b_id' => $r2, 'next_bout_id' => $final->id, 'next_slot' => 'a']);
        $bye = $category->bouts()->create(['round' => 1, 'position' => 2, 'required_participants' => 1, 'slot_a_id' => $r3, 'winner_id' => $r3, 'next_bout_id' => $final->id, 'next_slot' => 'b']);
        $category->forceFill(['bracket_generated_at' => now()])->save();

        $html = $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->assertOk()
            ->assertDontSee('20–30 minutos')->assertDontSee('Exento')->getContent();
        foreach ([$bout, $bye] as $source) {
            $this->assertMatchesRegularExpression('/data-bout="'.$source->id.'"\s+style="grid-column: 1; grid-row: '.($source->position + 1).' \//', $html);
        }
        $this->assertMatchesRegularExpression('/has-in"\s+data-bout="'.$final->id.'"\s+style="grid-column: 2; grid-row: 2 \/ 4"/', $html);

        $this->actingAs($user)->get(route('panel.brackets.pdf', [$event, $category]))->assertOk();
        $cards = collect(app(PaperBracket::class)->pages($category)[0]['cards']);
        $this->assertSame(['Pase'], $cards->flatMap(fn ($c) => $c['rows'])->pluck('label')->filter()->values()->all());
    }

    public function test_category_navigation_lists_bracket_categories_with_previous_and_next(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        [$a, $b, $c] = collect(['Aaa', 'Bbb', 'Ccc'])->map(fn ($name) => Category::factory()->for($event)->create(['name' => $name, 'modality' => 'kumite']))->all();
        Category::factory()->for($event)->create(['name' => 'Exhibición Z', 'modality' => 'exhibition']);
        Category::factory()->for(Event::factory()->for($user))->create(['name' => 'De otro evento', 'modality' => 'kumite']);

        $html = $this->actingAs($user)->get(route('panel.brackets.show', [$event, $b]))->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Cambiar de categoría"', $html);
        foreach ([$a, $b, $c] as $category) {
            $this->assertStringContainsString('value="'.route('panel.brackets.show', [$event, $category]).'"', $html);
        }
        $this->assertMatchesRegularExpression('/value="[^"]*categorias\/'.$b->id.'\/cuadro"\s+selected/', $html);
        $this->assertStringNotContainsString('Exhibición Z', $html);
        $this->assertStringNotContainsString('De otro evento', $html);
        $this->assertStringContainsString('href="'.route('panel.brackets.show', [$event, $a]).'" class="btn btn-outline-secondary btn-sm"', $html);
        $this->assertStringContainsString('href="'.route('panel.brackets.show', [$event, $c]).'" class="btn btn-outline-secondary btn-sm"', $html);

        // En los extremos, el botón correspondiente está deshabilitado.
        $first = $this->get(route('panel.brackets.show', [$event, $a]))->getContent();
        $this->assertStringContainsString('<span class="btn btn-outline-secondary btn-sm disabled" aria-disabled="true">&lsaquo; Anterior</span>', $first);
        $last = $this->get(route('panel.brackets.show', [$event, $c]))->getContent();
        $this->assertStringContainsString('<span class="btn btn-outline-secondary btn-sm disabled" aria-disabled="true">Siguiente &rsaquo;</span>', $last);
    }

    public function test_category_navigation_is_hidden_with_a_single_bracket_category(): void
    {
        [$user, $event, $category] = $this->owned(4);

        $this->actingAs($user)->get(route('panel.brackets.show', [$event, $category]))->assertOk()->assertDontSee('Cambiar de categoría', false);
    }
}
