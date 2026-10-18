<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Category;
use App\Models\Competitor;
use App\Models\Event;
use App\Models\User;
use App\Tournament\ResultRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsBrackets;
use Tests\TestCase;

class PublicPhotoViewsTest extends TestCase
{
    use BuildsBrackets, RefreshDatabase;

    /** Categoría publicada en el tatami 1 con cuadro de 4 y un combate jugado; todos con foto. */
    private function scenario(bool $show = true): Category
    {
        Storage::fake('local');
        $category = $this->generated(4);
        $category->forceFill(['tatami' => 1, 'is_published' => true, 'show_fourth' => true])->save();
        $event = $category->event;
        $event->forceFill(['is_public' => true, 'status' => EventStatus::InProgress, 'tatami_count' => 1, 'show_photos_publicly' => $show, 'starts_on' => now()->addMonth()])->save();
        Competitor::where('event_id', $event->id)->get()->each(function ($c) {
            $c->forceFill(['birth_date' => now()->subYears(30)->format('Y-m-d'), 'photo_path' => 'photos/'.$c->id.'.jpg', 'photo_public' => true])->save();
            Storage::disk('local')->put('photos/'.$c->id.'.jpg', 'fake-image');
        });

        return $category->refresh();
    }

    private function photoPath(Event $event, Competitor $c): string
    {
        return route('public.competitors.photo', [$event, $c], false);
    }

    private function play(Category $category): void
    {
        $recorder = app(ResultRecorder::class);
        while ($bout = $category->bouts()->orderBy('number')->get()->first(fn ($b) => $b->isReady())) {
            $recorder->record($bout, $bout->slot_a_id);
        }
    }

    public function test_tatami_view_shows_photos_of_both_contenders_under_the_rule(): void
    {
        $category = $this->scenario();
        $event = $category->event;
        $bout = $category->bouts()->orderBy('number')->get()->first(fn ($b) => $b->isReady());
        $a = $bout->slotA->competitor;
        $b = $bout->slotB->competitor;

        $html = $this->actingAs($event->user)->get(route('panel.tatamis.show', [$event, 1]))->assertOk()->getContent();
        $this->assertStringContainsString($this->photoPath($event, $a), $html);
        $this->assertStringContainsString($this->photoPath($event, $b), $html);
        $this->assertStringContainsString('competitor-photo-lg', $html);

        $b->update(['photo_public' => false]);
        $html = $this->actingAs($event->user)->get(route('panel.tatamis.show', [$event, 1]))->getContent();
        $this->assertStringContainsString($this->photoPath($event, $a), $html);
        $this->assertStringNotContainsString($this->photoPath($event, $b), $html);
    }

    public function test_tatami_view_upcoming_bouts_show_photos(): void
    {
        $category = $this->scenario();
        $event = $category->event;
        $second = $category->bouts()->where('round', 1)->orderBy('number')->get()->filter(fn ($b) => $b->slot_a_id && $b->slot_b_id)->skip(1)->first();
        $this->assertNotNull($second);

        $html = $this->actingAs($event->user)->get(route('panel.tatamis.show', [$event, 1]))->getContent();
        $this->assertStringContainsString($this->photoPath($event, $second->slotA->competitor), $html);
    }

    public function test_tatami_and_pages_show_no_photo_with_event_flag_off(): void
    {
        $category = $this->scenario(false);
        $event = $category->event;
        $this->play($category);

        $urls = [
            route('panel.tatamis.show', [$event, 1]),
            route('panel.brackets.show', [$event, $category]),
            route('public.events.show', [$event]),
            route('public.categories.show', [$event, $category]),
        ];
        foreach ($urls as $url) {
            $html = $this->actingAs($event->user)->seeResults($event)->get($url)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('#/competidores/\d+/foto#', $html, $url);
        }
    }

    public function test_public_category_page_shows_photos_in_bracket_results_and_podium(): void
    {
        $category = $this->scenario();
        $event = $category->event;
        $this->play($category);
        $champion = $category->refresh()->bouts()->where('is_bronze', false)->orderByDesc('round')->first()->winner->competitor;

        $html = $this->seeResults($event)->get(route('public.categories.show', [$event, $category]))->assertOk()->getContent();
        foreach (Competitor::where('event_id', $event->id)->get() as $c) {
            $this->assertStringContainsString($this->photoPath($event, $c), $html);
        }
        // El campeón sale en el podio, el cuadro y la tabla de resultados.
        $this->assertGreaterThanOrEqual(3, substr_count($html, $this->photoPath($event, $champion)));

        $champion->update(['photo_public' => false]);
        $html = $this->seeResults($event)->get(route('public.categories.show', [$event, $category]))->getContent();
        $this->assertStringNotContainsString($this->photoPath($event, $champion), $html);
    }

    public function test_public_event_page_now_block_shows_photos_under_the_rule(): void
    {
        $category = $this->scenario();
        $event = $category->event;
        $bout = $category->bouts()->orderBy('number')->get()->first(fn ($b) => $b->isReady());
        $a = $bout->slotA->competitor;
        $b = $bout->slotB->competitor;

        $html = $this->seeResults($event)->get(route('public.events.show', $event))->assertOk()->getContent();
        $this->assertStringContainsString($this->photoPath($event, $a), $html);
        $this->assertStringContainsString($this->photoPath($event, $b), $html);

        $a->update(['photo_public' => false]);
        $html = $this->seeResults($event)->get(route('public.events.show', $event))->getContent();
        $this->assertStringNotContainsString($this->photoPath($event, $a), $html);
        $this->assertStringContainsString($this->photoPath($event, $b), $html);
    }

    public function test_minor_without_consent_is_not_shown_in_any_view(): void
    {
        $category = $this->scenario();
        $event = $category->event;
        $bout = $category->bouts()->orderBy('number')->get()->first(fn ($b) => $b->isReady());
        $minor = $bout->slotA->competitor;
        $minor->forceFill(['birth_date' => now()->subYears(10)->format('Y-m-d')])->save();

        foreach ([route('panel.tatamis.show', [$event, 1]), route('public.events.show', $event), route('public.categories.show', [$event, $category])] as $url) {
            $html = $this->actingAs($event->user)->seeResults($event)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($this->photoPath($event, $minor), $html, $url);
        }
    }

    public function test_organizer_ficha_still_shows_photo_with_everything_off(): void
    {
        $category = $this->scenario(false);
        $event = $category->event;
        $competitor = Competitor::where('event_id', $event->id)->first();
        $competitor->update(['photo_public' => false]);

        $html = $this->actingAs($event->user)->get(route('panel.competitors.edit', [$event, $competitor]))->assertOk()->getContent();
        $this->assertStringContainsString(route('panel.competitors.photo', [$event, $competitor], false), $html);
    }
}
