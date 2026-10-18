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
}
