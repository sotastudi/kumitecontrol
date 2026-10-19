<?php

namespace Tests\Feature;

use App\Enums\ClubStatus;
use App\Models\Event;
use App\Models\EventClub;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClubColorTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_sets_and_resets_the_club_color_per_event(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->registrationOpen()->create();
        $other = Event::factory()->for($owner)->registrationOpen()->create();
        $club = $this->clubFor($event);
        $otherClub = EventClub::create(['event_id' => $other->id, 'user_id' => $club->id, 'status' => ClubStatus::Approved]);
        $registration = $event->clubs()->first();

        $this->actingAs($owner)->put(route('panel.clubs.color', [$event, $registration]), ['color' => '#AA3300'])->assertSessionHasNoErrors();
        $this->assertSame('#aa3300', $registration->fresh()->color);
        $this->assertNull($otherClub->fresh()->color);
        $this->get(route('panel.clubs.index', $event))->assertSee('value="#aa3300"', false);

        $this->put(route('panel.clubs.color', [$event, $registration]), ['color' => 'rojo'])->assertSessionHasErrors('color');

        $this->put(route('panel.clubs.color', [$event, $registration]), ['color' => '#aa3300', 'auto_color' => 1]);
        $this->assertNull($registration->fresh()->color);

        $this->actingAs($club)->put(route('panel.clubs.color', [$event, $registration]), ['color' => '#112233'])->assertForbidden();
    }

    public function test_clubs_get_distinct_automatic_colors_and_explicit_colors_are_respected(): void
    {
        $event = Event::factory()->registrationOpen()->create();
        $a = $this->clubFor($event, name: 'Club A');
        $b = $this->clubFor($event, name: 'Club B');
        $c = $this->clubFor($event, name: 'Club C');
        $pending = $this->clubFor($event, ClubStatus::Pending, 'Club D');
        $event->clubs()->where('user_id', $b->id)->update(['color' => EventClub::PALETTE[0]]);

        $colors = array_map(fn (User $u) => $event->fresh()->clubColor($u->name), [$a, $b, $c]);

        $this->assertSame(EventClub::PALETTE[0], $colors[1]);
        $this->assertCount(3, array_unique($colors));
        $this->assertNull($event->fresh()->clubColor($pending->name));
        $this->assertNull($event->fresh()->clubColor('Club desconocido'));
    }

    public function test_demo_clubs_are_listed_with_color_and_removed_on_clean(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['is_test' => true]);
        $category = \App\Models\Category::factory()->for($event)->create();
        $generator = new \App\Registrations\TestDataGenerator;

        $generator->registrations($category, 6, 3);

        $this->assertSame(3, $event->clubs()->where('status', 'approved')->count());
        $this->actingAs($owner)->get(route('panel.clubs.index', $event))->assertSee('Dojo Kame Senryu')->assertSee('Club Dojo Konoha Budo')->assertSee('Gimnasio Piratas del Sol');
        $this->assertNotNull($event->fresh()->clubColor('Dojo Kame Senryu'));

        $generator->clean($event);

        $this->assertSame(0, $event->clubs()->count());
        $this->assertSame(0, User::where('email', 'like', '%@demo.kumitecontrol.invalid')->count());
    }
}
