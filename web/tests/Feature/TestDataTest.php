<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Competitor;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Registrations\DemoCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TestDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Storage::fake('local');
    }

    private function testEvent(array $o = []): array
    {
        $user = User::factory()->create();

        return [$user, Event::factory()->for($user)->create(['is_test' => true, 'tatami_count' => 2] + $o)];
    }

    public function test_event_can_be_marked_as_test_and_shows_the_panel_only_then(): void
    {
        [$user, $event] = $this->testEvent();
        $plain = Event::factory()->for($user)->create();

        $this->actingAs($user)->get(route('panel.events.show', $event))->assertSee('Datos de prueba (evento Test)');
        $this->get(route('panel.events.show', $plain))->assertDontSee('Datos de prueba (evento Test)');
        $this->post(route('panel.test.categories', $plain), ['count' => 5])->assertNotFound();
    }

    public function test_default_categories_are_childrens_mixed_and_junior_and_senior_by_sex(): void
    {
        [$user, $event] = $this->testEvent();

        $this->actingAs($user)->post(route('panel.test.categories', $event), ['count' => 5])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            ['Infantil mixta', 'Junior masculina', 'Junior femenina', 'Senior masculina', 'Senior femenina'],
            $event->categories()->pluck('name')->all(),
        );
        $this->assertNull($event->categories()->firstWhere('name', 'Infantil mixta')->sex);
        $this->assertSame([1, 2], $event->categories()->pluck('tatami')->unique()->sort()->values()->all());

        // Repetir no duplica; con más categorías se añaden las que faltan.
        $this->post(route('panel.test.categories', $event), ['count' => 7]);
        $this->assertSame(7, $event->categories()->count());
    }

    public function test_registrations_are_created_in_a_category_with_the_requested_clubs(): void
    {
        [$user, $event] = $this->testEvent();
        $category = Category::factory()->for($event)->create(['min_age' => 18, 'max_age' => 30]);

        $this->actingAs($user)->post(route('panel.test.registrations', $event), [
            'category_id' => $category->id, 'participants' => 12, 'clubs' => 3, 'confirmed' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(12, Registration::where('category_id', $category->id)->where('status', 'confirmed')->count());
        $this->assertSame(3, Competitor::where('event_id', $event->id)->distinct()->count('club'));
        $this->assertSame(12, Competitor::where('notes', 'DEMO')->count());
    }

    public function test_clean_removes_only_demo_registrations(): void
    {
        [$user, $event] = $this->testEvent();
        $category = Category::factory()->for($event)->create();
        $real = Registration::factory()->forCategory($category)->create();

        $this->actingAs($user)->post(route('panel.test.registrations', $event), ['category_id' => $category->id, 'participants' => 4, 'clubs' => 2]);
        $this->delete(route('panel.test.clean', $event))->assertSessionHasNoErrors();

        $this->assertSame([$real->id], Registration::pluck('id')->all());
    }

    public function test_other_organizers_cannot_use_the_test_tools(): void
    {
        [, $event] = $this->testEvent();
        $category = Category::factory()->for($event)->create();

        $this->actingAs(User::factory()->create());
        $this->post(route('panel.test.categories', $event), ['count' => 5])->assertForbidden();
        $this->post(route('panel.test.registrations', $event), ['category_id' => $category->id, 'participants' => 4, 'clubs' => 2])->assertForbidden();
        $this->delete(route('panel.test.clean', $event))->assertForbidden();
    }

    public function test_default_clubs_are_the_eight_manga_clubs_with_logo_and_extra_clubs_have_none(): void
    {
        Storage::fake('local');
        [$user, $event] = $this->testEvent();
        $category = Category::factory()->for($event)->create(['min_age' => 18, 'max_age' => 30]);

        $this->actingAs($user)->post(route('panel.test.registrations', $event), ['category_id' => $category->id, 'participants' => 20, 'clubs' => 10]);

        $clubs = $event->clubs()->with('user')->get();
        $this->assertCount(10, $clubs);
        $names = $clubs->pluck('user.name')->all();
        $this->assertEqualsCanonicalizing([...(new DemoCatalog)->clubs(), 'Club 9', 'Club 10'], $names);
        foreach ($clubs as $club) {
            $this->assertSame(! preg_match('/^Club \d+$/', $club->user->name), $club->user->hasLogo(), $club->user->name);
        }
    }

    public function test_generating_categories_sets_the_poster_without_overwriting_an_existing_one(): void
    {
        Storage::fake('local');
        [$user, $event] = $this->testEvent();

        $this->actingAs($user)->post(route('panel.test.categories', $event), ['count' => 5]);
        $this->assertTrue($event->fresh()->hasPoster());

        Storage::disk('local')->put('posters/mio.jpg', 'x');
        $event->forceFill(['poster_path' => 'posters/mio.jpg'])->save();
        $this->post(route('panel.test.categories', $event), ['count' => 6]);
        $this->assertSame('posters/mio.jpg', $event->fresh()->poster_path);
    }

    public function test_clean_deletes_club_logos_of_the_demo_clubs(): void
    {
        Storage::fake('local');
        [$user, $event] = $this->testEvent();
        $category = Category::factory()->for($event)->create();

        $this->actingAs($user)->post(route('panel.test.registrations', $event), ['category_id' => $category->id, 'participants' => 8, 'clubs' => 8]);
        $this->assertNotEmpty(Storage::disk('local')->allFiles('club-logos'));

        $this->delete(route('panel.test.clean', $event));
        $this->assertSame([], Storage::disk('local')->allFiles('club-logos'));
        $this->assertSame(0, $event->clubs()->count());
    }

    private function names(Category $category): array
    {
        return Registration::where('category_id', $category->id)->with('competitor')->orderBy('id')->get()
            ->map(fn ($r) => $r->competitor->first_name.' '.$r->competitor->last_name)->all();
    }

    private function catalogNames(string $key): array
    {
        return array_map(fn ($p) => $p['first_name'].' '.$p['last_name'], (new DemoCatalog)->competitors($key));
    }

    public function test_default_run_creates_twenty_named_registrations_with_photo_per_category(): void
    {
        [$user, $event] = $this->testEvent();
        $category = Category::factory()->for($event)->create(['name' => 'Senior femenina', 'sex' => 'female', 'min_age' => 18, 'max_age' => null]);

        $this->actingAs($user)->post(route('panel.test.registrations', $event), ['category_id' => $category->id, 'participants' => 20, 'clubs' => 8]);

        $this->assertSame($this->catalogNames('senior-femenina'), $this->names($category));
        $competitors = Competitor::where('event_id', $event->id)->get();
        $this->assertSame(8, $competitors->pluck('club')->unique()->count());
        $this->assertTrue($competitors->every(fn ($c) => $c->hasPhoto()));
        $this->assertTrue($competitors->every(fn ($c) => $c->sex->value === 'female'));
    }

    public function test_second_run_continues_the_list_without_repeating_names(): void
    {
        [$user, $event] = $this->testEvent();
        $category = Category::factory()->for($event)->create(['sex' => 'male', 'min_age' => 14, 'max_age' => 17]);

        foreach ([12, 12] as $n) {
            $this->actingAs($user)->post(route('panel.test.registrations', $event), ['category_id' => $category->id, 'participants' => $n, 'clubs' => 8]);
        }

        $names = $this->names($category);
        $this->assertCount(24, $names);
        $this->assertSame($this->catalogNames('junior-masculina'), array_slice($names, 0, 20));
    }

    public function test_more_than_the_catalog_falls_back_to_random_names_and_cycles_photos(): void
    {
        [$user, $event] = $this->testEvent();
        $category = Category::factory()->for($event)->create(['name' => 'Infantil mixta', 'sex' => null, 'min_age' => 8, 'max_age' => 13]);

        $this->actingAs($user)->post(route('panel.test.registrations', $event), ['category_id' => $category->id, 'participants' => 50, 'clubs' => 8])->assertSessionHasNoErrors();

        $this->assertCount(50, $this->names($category));
        $this->assertSame($this->catalogNames('infantil'), array_slice($this->names($category), 0, 20));
        $this->assertTrue(Competitor::where('event_id', $event->id)->get()->every(fn ($c) => $c->hasPhoto()));
    }

    public function test_fewer_clubs_reassigns_competitors_of_dropped_clubs(): void
    {
        [$user, $event] = $this->testEvent();
        $category = Category::factory()->for($event)->create(['sex' => 'female', 'min_age' => 14, 'max_age' => 17]);

        $this->actingAs($user)->post(route('panel.test.registrations', $event), ['category_id' => $category->id, 'participants' => 20, 'clubs' => 3]);

        $clubs = Competitor::where('event_id', $event->id)->pluck('club')->unique();
        $this->assertCount(3, $clubs);
        $this->assertEqualsCanonicalizing(array_slice((new DemoCatalog)->clubs(), 0, 3), $clubs->all());
        $this->assertSame(3, $event->clubs()->count());
    }

    public function test_clean_deletes_competitor_photo_files(): void
    {
        [$user, $event] = $this->testEvent();
        $category = Category::factory()->for($event)->create();

        $this->actingAs($user)->post(route('panel.test.registrations', $event), ['category_id' => $category->id, 'participants' => 5, 'clubs' => 2]);
        $this->assertCount(5, Storage::disk('local')->allFiles('competitor-photos'));

        $this->delete(route('panel.test.clean', $event));
        $this->assertSame([], Storage::disk('local')->allFiles('competitor-photos'));
    }
}
