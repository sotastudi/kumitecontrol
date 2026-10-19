<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\WithdrawalType;
use App\Models\Category;
use App\Models\Competitor;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Tournament\ResultRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBrackets;
use Tests\TestCase;

class PublicResultsTest extends TestCase
{
    use BuildsBrackets, RefreshDatabase;

    private function playedCategory(): Category
    {
        $category = $this->generated(4);
        $category->update(['show_fourth' => true]);
        $category->event->update(['is_public' => true, 'status' => EventStatus::InProgress]);
        $recorder = app(ResultRecorder::class);
        while ($bout = $category->bouts()->orderBy('number')->get()->first(fn ($b) => $b->isReady())) {
            $recorder->record($bout, $bout->slot_a_id);
        }

        return $category->refresh();
    }

    public function test_owner_publishes_only_a_generated_category(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $empty = Category::factory()->for($event)->create();

        $this->actingAs($user)->patch(route('panel.categories.publication', [$event, $empty]))->assertSessionHasErrors('error');
        $this->assertFalse($empty->fresh()->is_published);

        $category = $this->generated(4);
        $category->event->forceFill(['user_id' => $user->id])->save();
        $this->actingAs($user)->patch(route('panel.categories.publication', [$category->event, $category]));
        $this->assertTrue($category->fresh()->is_published);
        $this->actingAs($user)->patch(route('panel.categories.publication', [$category->event, $category]));
        $this->assertFalse($category->fresh()->is_published);
    }

    public function test_event_page_disables_publish_until_the_category_has_a_bracket(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $empty = Category::factory()->for($event)->create();
        $publishUrl = route('panel.categories.publication', [$event, $empty]);

        $this->actingAs($user)->get(route('panel.events.show', $event))
            ->assertSee('title="Genera el cuadro antes de publicar"', false)
            ->assertDontSee($publishUrl, false);

        // Una categoría ya publicada que se queda sin cuadro conserva «Ocultar».
        $empty->forceFill(['is_published' => true])->save();
        $this->get(route('panel.events.show', $event))
            ->assertSee($publishUrl, false)
            ->assertDontSee('Genera el cuadro antes de publicar');

        $category = $this->generated(4);
        $category->event->forceFill(['user_id' => $user->id])->save();
        $this->get(route('panel.events.show', $category->event))
            ->assertSee(route('panel.categories.publication', [$category->event, $category]), false)
            ->assertDontSee('Genera el cuadro antes de publicar');
    }

    public function test_public_pages_show_bracket_and_podium_but_never_private_data(): void
    {
        $category = $this->playedCategory();
        $this->seeResults($category->event);
        $category->forceFill(['is_published' => true])->save();
        $event = $category->event;

        // Datos privados con valores reconocibles
        Competitor::query()->where('event_id', $event->id)->get()->each(fn ($c, $i) => $c->update([
            'email' => "privado{$i}@secreto.test", 'phone' => "699000{$i}00", 'notes' => "nota-interna-{$i}", 'birth_date' => '1999-0'.($i + 1).'-17',
        ]));

        $this->assertSame(4, Competitor::query()->where('event_id', $event->id)->where('email', 'like', '%@secreto.test')->count());

        $pages = [route('home'), route('public.events.show', $event), route('public.categories.show', [$event, $category])];
        foreach ($pages as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            foreach (['@secreto.test', '699000', 'nota-interna', '1999-0', '17/0', 'birth'] as $secret) {
                $this->assertStringNotContainsString($secret, $html, "{$url} expone {$secret}");
            }
        }

        $page = $this->get(route('public.categories.show', [$event, $category]));
        $winner = $category->bouts()->where('round', 2)->where('is_bronze', false)->first()->winner->competitor;
        $page->assertSee($winner->displayName())->assertSee('1.º');
    }

    public function test_unpublished_private_or_draft_content_is_not_public(): void
    {
        $category = $this->playedCategory();
        $this->seeResults($category->event);
        $event = $category->event;

        $this->get(route('public.categories.show', [$event, $category]))->assertNotFound(); // sin publicar

        $category->forceFill(['is_published' => true])->save();
        $this->get(route('public.categories.show', [$event, $category]))->assertOk();

        $event->update(['is_public' => false]);
        $this->get(route('public.categories.show', [$event, $category]))->assertNotFound();

        $event->update(['is_public' => true, 'status' => EventStatus::Draft]);
        $this->get(route('public.categories.show', [$event, $category]))->assertNotFound();
    }

    public function test_category_from_another_event_is_not_reachable(): void
    {
        $category = $this->playedCategory();
        $this->seeResults($category->event);
        $category->forceFill(['is_published' => true])->save();
        $other = Event::factory()->registrationOpen()->create();

        $this->get(route('public.categories.show', [$other, $category]))->assertNotFound();
    }

    public function test_event_page_lists_only_published_categories(): void
    {
        $category = $this->playedCategory();
        $this->seeResults($category->event);
        $hidden = Category::factory()->for($category->event)->create(['name' => 'Categoría oculta']);
        $category->forceFill(['is_published' => true])->save();

        $this->get(route('public.events.show', $category->event))
            ->assertSee($category->name)->assertDontSee('Categoría oculta');
    }

    public function test_standings_csv_for_owner(): void
    {
        $category = $this->playedCategory();
        $this->seeResults($category->event);
        $owner = $category->event->user;

        $csv = $this->actingAs($owner)->get(route('panel.exports.standings', $category->event))->assertOk()->streamedContent();

        $rows = array_map(fn ($l) => str_getcsv($l, ';', '"', ''), array_filter(preg_split('/\r?\n/', ltrim($csv, "\xEF\xBB\xBF"))));
        $rows = array_values($rows);
        $this->assertSame(['Categoría', 'Puesto', 'Apellidos', 'Nombre', 'Club'], $rows[0]);
        $places = collect($rows)->slice(1)->where(0, $category->name)->pluck(1)->all();
        $this->assertContains('1', $places);
        $this->assertContains('4', $places); // show_fourth activado
        $this->actingAs(User::factory()->create())->get(route('panel.exports.standings', $category->event))->assertForbidden();
    }

    public function test_standings_csv_excludes_a_disqualified_competitor(): void
    {
        $category = $this->playedCategory();
        $this->seeResults($category->event);
        $final = $category->bouts()->where('is_bronze', false)->orderByDesc('round')->first();
        $loser = Registration::with('competitor')->find($final->loserId());
        $loser->forceFill(['withdrawal_type' => WithdrawalType::Disqualified, 'withdrawn_at' => now()])->save();

        $csv = $this->actingAs($category->event->user)->get(route('panel.exports.standings', $category->event))->streamedContent();

        $rows = array_values(array_map(fn ($l) => str_getcsv($l, ';', '"', ''), array_filter(preg_split('/\r?\n/', ltrim($csv, "\xEF\xBB\xBF")))));
        $this->assertSame(['Categoría', 'Puesto', 'Apellidos', 'Nombre', 'Club'], $rows[0]);
        $data = array_slice($rows, 1);

        foreach ($data as $row) {
            $this->assertFalse(
                $row[2] === $loser->competitor->last_name && $row[3] === $loser->competitor->first_name,
                'El descalificado sigue en el CSV de clasificación.'
            );
        }
        // Sin renumerar: desaparece el 2.º puesto y quedan 1.º, 3.º y 4.º.
        $this->assertSame(['1', '3', '4'], collect($data)->where(0, $category->name)->pluck(1)->sort()->values()->all());
    }

    public function test_a_withdrawn_finalist_keeps_the_podium_place_without_revealing_the_withdrawal(): void
    {
        $category = $this->playedCategory();
        $this->seeResults($category->event);
        $category->forceFill(['is_published' => true])->save();
        $final = $category->bouts()->where('is_bronze', false)->orderByDesc('round')->first();
        $loser = Registration::with('competitor')->find($final->loserId());
        $loser->forceFill(['withdrawal_type' => WithdrawalType::Withdrawn, 'withdrawn_at' => now()])->save();

        $html = $this->get(route('public.categories.show', [$category->event, $category]))->assertOk()
            ->assertSee($loser->competitor->displayName())->getContent();

        foreach (['(retirado)', 'retirad', 'descalific'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $html);
        }
    }

    public function test_guardian_data_never_appears_on_public_pages(): void
    {
        $category = $this->playedCategory();
        $this->seeResults($category->event);
        $category->forceFill(['is_published' => true])->save();
        Competitor::query()->where('event_id', $category->event_id)->get()->each(fn ($c, $i) => $c->forceFill([
            'guardian_name' => "Tutor Reconocible{$i}", 'guardian_contact' => "tutor{$i}@familia.test 677000{$i}11",
            'guardian_consent_at' => now(),
        ])->save());

        foreach ([route('home'), route('public.events.show', $category->event), route('public.categories.show', [$category->event, $category])] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            foreach (['Tutor Reconocible', '@familia.test', '677000', 'guardian', 'representante'] as $secret) {
                $this->assertStringNotContainsStringIgnoringCase($secret, $html, "{$url} expone {$secret}");
            }
        }
    }
}
