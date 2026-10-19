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

class TatamiOrderTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $o = []): array
    {
        return array_merge(['name' => 'Cat', 'bronze_rule' => 'bronze_match'], $o);
    }

    public function test_categories_get_the_next_position_in_their_tatami_and_can_be_moved_up_and_down(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['tatami_count' => 2]);

        foreach (['Uno' => 1, 'Dos' => 1, 'Tres' => 1, 'Otro' => 2, 'Sin' => null] as $name => $tatami) {
            $this->actingAs($user)->post(route('panel.categories.store', $event), $this->payload(['name' => $name, 'tatami' => $tatami]))->assertSessionHasNoErrors();
        }
        $by = fn (string $name) => $event->categories()->where('name', $name)->firstOrFail();
        $order = fn (int $tatami) => $event->categories()->where('tatami', $tatami)->orderBy('tatami_position')->pluck('name')->all();

        $this->assertSame(['Uno', 'Dos', 'Tres'], $order(1));
        $this->assertSame(1, $by('Otro')->tatami_position);
        $this->assertNull($by('Sin')->tatami_position);

        $this->actingAs($user)->post(route('panel.categories.move', [$event, $by('Tres')]), ['direction' => 'up'])->assertSessionHasNoErrors();
        $this->assertSame(['Uno', 'Tres', 'Dos'], $order(1));
        $this->actingAs($user)->post(route('panel.categories.move', [$event, $by('Uno')]), ['direction' => 'down']);
        $this->assertSame(['Tres', 'Uno', 'Dos'], $order(1));

        // En los extremos no hace nada ni rompe el orden.
        $this->actingAs($user)->post(route('panel.categories.move', [$event, $by('Tres')]), ['direction' => 'up']);
        $this->actingAs($user)->post(route('panel.categories.move', [$event, $by('Dos')]), ['direction' => 'down']);
        $this->assertSame(['Tres', 'Uno', 'Dos'], $order(1));
        $this->assertSame([1, 2, 3], $event->categories()->where('tatami', 1)->orderBy('tatami_position')->pluck('tatami_position')->all());

        $this->actingAs($user)->post(route('panel.categories.move', [$event, $by('Dos')]), ['direction' => 'sideways'])->assertSessionHasErrors('direction');
        $this->actingAs($user)->post(route('panel.categories.move', [$event, $by('Sin')]), ['direction' => 'up'])->assertNotFound();
        $this->actingAs(User::factory()->create())->post(route('panel.categories.move', [$event, $by('Uno')]), ['direction' => 'up'])->assertForbidden();
    }

    public function test_changing_a_categorys_tatami_places_it_last_in_the_new_one(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['tatami_count' => 2]);
        $a = Category::factory()->for($event)->create(['name' => 'A', 'tatami' => 1, 'tatami_position' => 1]);
        $b = Category::factory()->for($event)->create(['name' => 'B', 'tatami' => 2, 'tatami_position' => 1]);

        $this->actingAs($user)->put(route('panel.categories.update', [$event, $a]), $this->payload(['name' => 'A', 'tatami' => 2]))->assertSessionHasNoErrors();
        $this->assertSame(2, $a->fresh()->tatami_position);

        $this->actingAs($user)->put(route('panel.categories.update', [$event, $a]), $this->payload(['name' => 'A', 'tatami' => '']))->assertSessionHasNoErrors();
        $this->assertNull($a->fresh()->tatami_position);
    }

    public function test_the_current_bout_of_a_tatami_follows_the_chosen_category_order_and_the_panel_lists_it(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['tatami_count' => 1]);
        $first = Category::factory()->for($event)->create(['name' => 'Creada primero', 'tatami' => 1, 'tatami_position' => 1]);
        $second = Category::factory()->for($event)->create(['name' => 'Creada segunda', 'tatami' => 1, 'tatami_position' => 2]);
        foreach ([$first, $second] as $c) {
            Registration::factory()->count(2)->confirmed()->forCategory($c)->create();
            app(BracketGenerator::class)->generate($c, fn ($ids) => $ids->values());
        }

        $this->actingAs($user)->get(route('panel.events.show', $event))->assertSee('Creada primero · Combate 1');

        $this->actingAs($user)->post(route('panel.categories.move', [$event, $second]), ['direction' => 'up']);

        $html = $this->actingAs($user)->get(route('panel.events.show', $event))->getContent();
        $this->assertMatchesRegularExpression('#Ahora.*?Creada segunda · Combate 1#s', $html);
        $this->assertStringContainsString('aria-label="Subir Creada primero"', $html);
        $this->actingAs($user)->get(route('panel.tatamis.show', [$event, 1]))->assertSee('1. Creada segunda · 2. Creada primero');
    }

    public function test_drag_and_drop_saves_the_whole_order_of_a_tatami_and_validates_it(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['tatami_count' => 2]);
        $cats = collect(['A', 'B', 'C'])->map(fn ($n, $i) => Category::factory()->for($event)->create(['name' => $n, 'tatami' => 1, 'tatami_position' => $i + 1]));
        $other = Category::factory()->for($event)->create(['name' => 'Z', 'tatami' => 2, 'tatami_position' => 1]);
        $url = route('panel.tatamis.reorder', [$event, 1]);
        $names = fn () => $event->categories()->where('tatami', 1)->orderBy('tatami_position')->pluck('name')->all();

        $this->actingAs($user)->postJson($url, ['order' => [$cats[2]->id, $cats[0]->id, $cats[1]->id]])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(['C', 'A', 'B'], $names());

        // Debe ser exactamente el conjunto de categorías del tatami: ni de otro tatami, ni repetidas, ni incompletas.
        $this->actingAs($user)->postJson($url, ['order' => [$cats[0]->id, $other->id, $cats[1]->id]])->assertStatus(422);
        $this->actingAs($user)->postJson($url, ['order' => [$cats[0]->id, $cats[0]->id, $cats[1]->id]])->assertStatus(422);
        $this->actingAs($user)->postJson($url, ['order' => [$cats[0]->id]])->assertStatus(422);
        $this->assertSame(['C', 'A', 'B'], $names());

        $this->actingAs($user)->postJson(route('panel.tatamis.reorder', [$event, 5]), ['order' => [1]])->assertNotFound();
        $this->actingAs(User::factory()->create())->postJson($url, ['order' => [$cats[0]->id, $cats[1]->id, $cats[2]->id]])->assertForbidden();
    }

    public function test_the_event_panel_offers_a_reorder_button_per_tatami_with_draggable_items_ready_for_js(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['tatami_count' => 2]);
        Category::factory()->for($event)->create(['name' => 'A', 'tatami' => 1, 'tatami_position' => 1]);
        Category::factory()->for($event)->create(['name' => 'B', 'tatami' => 1, 'tatami_position' => 2]);
        Category::factory()->for($event)->create(['name' => 'Sola', 'tatami' => 2, 'tatami_position' => 1]);

        $html = $this->actingAs($user)->get(route('panel.events.show', $event))->getContent();

        $this->assertSame(1, substr_count($html, 'data-reorder-toggle'), 'solo hay botón en el tatami con 2 o más categorías');
        $this->assertStringContainsString('data-reorder-url="'.route('panel.tatamis.reorder', [$event, 1]).'"', $html);
        $this->assertSame(3, substr_count($html, 'data-category-id='), 'cada categoría asignada a un tatami es un elemento reordenable');
    }

    public function test_category_row_actions_have_their_icons_and_colours(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $category = Category::factory()->for($event)->create(['name' => 'Fila']);

        $html = $this->actingAs($user)->get(route('panel.events.show', $event))->getContent();

        $this->assertMatchesRegularExpression('#btn btn-blue btn-sm btn-icon"><svg[^>]*><use href="\#sitemap"/></svg> Cuadro#', $html);
        $this->assertMatchesRegularExpression('#btn btn-yellow btn-sm btn-icon"><svg[^>]*><use href="\#pencil"/></svg> Editar#', $html);
        $this->assertMatchesRegularExpression('#btn btn-outline-danger btn-sm btn-icon"><svg[^>]*><use href="\#trash"/></svg> Eliminar#', $html);
        $this->assertMatchesRegularExpression('#btn btn-outline-success btn-sm btn-icon"[^>]*><svg[^>]*><use href="\#share"/></svg> Publicar#', $html);
        foreach (['pencil', 'sitemap', 'trash', 'share'] as $icon) {
            $this->assertStringContainsString('<symbol id="'.$icon.'"', $html);
        }
    }

    public function test_public_page_buttons_are_an_eye_icon_with_the_text_ver(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['is_public' => true, 'status' => EventStatus::InProgress]);
        $category = Category::factory()->for($event)->create(['is_published' => true]);
        Registration::factory()->count(2)->confirmed()->forCategory($category)->create();
        app(BracketGenerator::class)->generate($category, fn ($ids) => $ids->values());

        foreach ([route('panel.events.show', $event), route('panel.brackets.show', [$event, $category])] as $url) {
            $html = $this->actingAs($user)->get($url)->getContent();
            $this->assertMatchesRegularExpression('#<a href="[^"]*"[^>]*title="Ver página pública" aria-label="Ver página pública"><svg[^>]*><use href="\#eye"/></svg> Ver</a>#', $html, $url);
            $this->assertStringNotContainsString('>Ver página pública<', $html);
        }
        $this->assertSame(2, substr_count($this->actingAs($user)->get(route('panel.events.show', $event))->getContent(), 'href="#eye"'), 'evento y categoría');
    }

    public function test_event_header_and_access_code_buttons_have_their_colours_and_icons(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $html = $this->actingAs($user)->get(route('panel.events.show', $event))->getContent();

        $this->assertMatchesRegularExpression('#class="btn btn-blue"[^>]*>Asignar números de competidor</button>#', $html);
        $this->assertMatchesRegularExpression('#class="btn btn-yellow btn-icon"><svg[^>]*><use href="\#pencil"/></svg> Editar</a>#', $html);
        $this->assertMatchesRegularExpression('#class="btn btn-outline-danger btn-icon"><svg[^>]*><use href="\#trash"/></svg> Eliminar</button>#', $html);
        $this->assertMatchesRegularExpression('#btn-icon"><svg[^>]*><use href="\#reset"/></svg> Regenerar código</button>#', $html);
        $this->assertStringContainsString('<symbol id="reset"', $html);
    }
}
