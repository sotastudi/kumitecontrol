<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Competitor;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Organizador: evento + categoría, 5 competidores confirmados, cuadro con pases, final y bronce, publicación sin datos privados. */
class FirstMilestoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_organizer_flow(): void
    {
        $organizer = User::factory()->create();
        $this->actingAs($organizer);

        $this->post(route('panel.events.store'), [
            'name' => 'Open de Otoño', 'discipline' => 'Judo', 'starts_on' => '2026-11-20', 'venue' => 'Pabellón',
            'status' => 'in_progress', 'is_public' => '1', 'registration_form_enabled' => '0',
        ])->assertRedirect();
        $event = Event::firstOrFail();

        $this->post(route('panel.categories.store', $event), [
            'name' => 'Adultos -70 kg', 'bronze_rule' => 'bronze_match', 'show_fourth' => '1',
        ])->assertRedirect();
        $category = Category::firstOrFail();

        $names = ['Ana' => 'Ruiz', 'Berta' => 'Sanz', 'Carla' => 'Vela', 'Dana' => 'Mora', 'Eva' => 'Nieto'];
        $i = 0;
        $private = [];
        foreach ($names as $first => $last) {
            $this->post(route('panel.registrations.store', $event), [
                'first_name' => $first, 'last_name' => $last, 'club' => 'Club '.$first, 'sex' => 'male', 'level' => '3º kyu', 'birth_date' => '199'.$i.'-05-0'.($i + 1),
                'sex' => 'female', 'weight' => 60, 'email' => strtolower($first).'@privado.test', 'phone' => '60000000'.$i,
                'category_id' => $category->id, 'status' => 'confirmed',
            ])->assertSessionHasNoErrors();
            $private[$first] = [
                'iso' => '199'.$i.'-05-0'.($i + 1),
                'dmy' => '0'.($i + 1).'/05/199'.$i,
                'email' => strtolower($first).'@privado.test',
                'phone' => '60000000'.$i,
            ];
            $i++;
        }

        // El organizador sí ve los datos privados: así las comprobaciones públicas pueden fallar.
        $csv = $this->get(route('panel.exports.registrations', $event))->assertOk()->streamedContent();
        foreach ($private as $first => $values) {
            foreach (['email', 'phone', 'dmy'] as $key) {
                $this->assertStringContainsString($values[$key], $csv, "CSV del organizador sin $key de $first");
            }
            $competitor = Competitor::where('first_name', $first)->firstOrFail();
            $this->get(route('panel.competitors.edit', [$event, $competitor]))->assertOk()->assertSee($values['iso']);
        }

        $this->readyForBrackets($event);
        $this->post(route('panel.brackets.store', [$event, $category]))->assertSessionHasNoErrors();
        // Cinco competidores: un combate de ronda previa y tres exentos; todos colocados una sola vez.
        $placed = $category->bouts()->where('round', '<=', 2)->where('is_bronze', false)->get()->flatMap(fn ($b) => [$b->slot_a_id, $b->slot_b_id])->filter();
        $this->assertCount(5, $placed->unique());

        while ($bout = $category->bouts()->orderBy('number')->get()->first(fn ($b) => $b->isReady())) {
            $this->put(route('panel.bouts.result', [$event, $category, $bout]), ['winner_id' => $bout->slot_a_id])
                ->assertSessionHasNoErrors();
        }

        // Todos los combates reales se han resuelto: con 5 participantes hay 1 de ronda previa, 2 semifinales, la final y el bronce.
        $real = $category->bouts()->where('required_participants', 2)->get();
        $this->assertCount(5, $real, 'cinco competidores → cinco combates reales');
        $this->assertCount(0, $real->filter(fn ($b) => $b->winner_id === null), 'ningún combate real sin ganador');

        $final = $category->bouts()->get()->first(fn ($b) => $b->isFinal());
        $bronze = $category->bouts()->where('is_bronze', true)->firstOrFail();
        $name = fn (?int $registrationId) => Registration::findOrFail($registrationId)->competitor->displayName();
        $podium = [
            1 => $name($final->winner_id),
            2 => $name($final->loserId()),
            3 => $name($bronze->winner_id),
        ];
        $this->assertCount(3, array_unique($podium));

        $this->patch(route('panel.categories.publication', [$event, $category]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($category->fresh()->is_published);
        auth()->logout();

        $html = $this->seeResults($event)->get(route('public.categories.show', [$event, $category]))->assertOk()->getContent();

        // Solo el bloque del podio (columnas 1.º-3.º): el cuadro y los resultados de debajo listan a todos.
        $this->assertSame(1, preg_match('#<div class="podium d-flex.*?(?=<section)#s', $html, $block));
        $rows = [];
        foreach (preg_split('#<div class="podium-col podium-#', $block[0]) as $i => $piece) {
            if ($i > 0) {
                $rows[(int) $piece[0]] = explode('<ul class="list-group', $piece)[0];
            }
        }
        $this->assertCount(3, $rows, 'el podio tiene tres puestos (un único bronce: hay combate por el 3.er puesto)');
        foreach ($podium as $place => $expected) {
            $row = $rows[$place];
            $this->assertStringContainsString($place.'.º', $row);
            $this->assertStringContainsString($expected, $row);
            foreach (array_diff($podium, [$place => $expected]) as $other) {
                $this->assertStringNotContainsString($other, $row, "$other no debe estar en la fila del $place.º");
            }
        }
        foreach ($private as $values) {
            foreach ($values as $secret) {
                $this->assertStringNotContainsString($secret, $html);
            }
        }
    }
}
