<?php

namespace Tests\Feature\Console;

use App\Enums\RegistrationStatus;
use App\Models\Category;
use App\Models\Competitor;
use App\Models\Event;
use App\Models\Registration;
use App\Support\MartialLevels;
use App\Tournament\BracketGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoRegistrationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Storage::fake('local');
    }

    private function category(array $o = []): Category
    {
        return Category::factory()->for(Event::factory()->create(['starts_on' => '2026-11-20']))->create($o);
    }

    public function test_creates_demo_registrations_with_only_the_mandatory_data_and_the_bracket_can_be_drawn(): void
    {
        $category = $this->category(['min_age' => 15, 'max_age' => 17]);

        $this->artisan('kumitecontrol:demo-inscritos', ['category' => $category->id, '--count' => 9, '--pending' => 2])->assertSuccessful();

        $this->assertSame(9, $category->registrations()->count());
        $this->assertSame(7, $category->registrations()->where('status', RegistrationStatus::Confirmed->value)->count());
        foreach ($category->registrations()->with('competitor')->get() as $registration) {
            $c = $registration->competitor;
            $this->assertNotEmpty($c->first_name.$c->last_name);
            $this->assertNotNull($c->sex);
            $this->assertContains($c->level, MartialLevels::values());
            $this->assertNotEmpty($c->club, 'el club es obligatorio');
            $this->assertNull($c->weight);
            $this->assertGreaterThanOrEqual(15, $c->ageOn($category->event->starts_on));
            $this->assertLessThanOrEqual(17, $c->ageOn($category->event->starts_on));
        }

        app(BracketGenerator::class)->generate($category);
        $this->assertGreaterThan(0, $category->bouts()->count());
    }

    public function test_extras_add_weight_clubs_are_always_set_and_rerun_replaces_only_demo_data(): void
    {
        $category = $this->category();
        $real = Registration::factory()->forCategory($category)->create();

        $this->artisan('kumitecontrol:demo-inscritos', ['category' => $category->id, '--count' => 6, '--extras' => true, '--clubs' => 2])->assertSuccessful();
        $demo = $category->registrations()->whereHas('competitor', fn ($q) => $q->where('notes', 'DEMO'))->with('competitor')->get();
        $this->assertCount(6, $demo);
        $this->assertSame(2, $demo->pluck('competitor.club')->unique()->count());
        $this->assertNotNull($demo->first()->competitor->weight);

        $this->artisan('kumitecontrol:demo-inscritos', ['category' => $category->id, '--count' => 4])->assertSuccessful();
        $this->assertSame(5, $category->registrations()->count());
        $this->assertTrue(Registration::whereKey($real->id)->exists());

        $this->artisan('kumitecontrol:demo-inscritos', ['category' => $category->id, '--clean' => true])->assertSuccessful();
        $this->assertSame([$real->id], $category->registrations()->pluck('id')->all());
        $this->assertSame(1, Competitor::where('event_id', $category->event_id)->count());
    }

    public function test_refuses_locked_categories_unknown_ids_and_production(): void
    {
        $this->artisan('kumitecontrol:demo-inscritos', ['category' => 999])->assertFailed();

        $category = $this->category();
        $this->artisan('kumitecontrol:demo-inscritos', ['category' => $category->id, '--count' => 4])->assertSuccessful();
        $registration = $category->registrations()->first();
        $registration->forceFill(['withdrawal_type' => 'withdrawn', 'withdrawn_at' => now()])->save();
        $this->artisan('kumitecontrol:demo-inscritos', ['category' => $category->id])->assertFailed();

        $this->app['env'] = 'production';
        $other = $this->category();
        $this->artisan('kumitecontrol:demo-inscritos', ['category' => $other->id])->assertFailed();
        $this->assertSame(0, $other->registrations()->count());
    }
}
