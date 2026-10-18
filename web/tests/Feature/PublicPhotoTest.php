<?php

namespace Tests\Feature;

use App\Enums\ClubStatus;
use App\Models\Category;
use App\Models\Competitor;
use App\Models\Event;
use App\Models\EventClub;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPhotoTest extends TestCase
{
    use RefreshDatabase;

    private function event(bool $show = true): Event
    {
        return Event::factory()->create(['starts_on' => '2026-06-20', 'show_photos_publicly' => $show]);
    }

    /** @param array<string,mixed> $attrs */
    private function competitor(Event $event, array $attrs = []): Competitor
    {
        return Competitor::factory()->create(array_merge([
            'event_id' => $event->id,
            'birth_date' => '1990-01-01',
            'photo_path' => 'competitor-photos/x.jpg',
            'photo_public' => true,
        ], $attrs));
    }

    public function test_defaults(): void
    {
        $event = Event::factory()->create()->fresh();
        $competitor = Competitor::factory()->create(['event_id' => $event->id])->fresh();

        $this->assertFalse($event->show_photos_publicly);
        $this->assertTrue($competitor->photo_public);
    }

    public function test_url_when_everything_allows_it(): void
    {
        $event = $this->event();
        $competitor = $this->competitor($event);

        $url = $competitor->publicPhotoUrl($event);

        $this->assertStringStartsWith(route('public.competitors.photo', [$event, $competitor]).'?v=', $url);
    }

    public function test_null_when_event_flag_is_off(): void
    {
        $event = $this->event(false);

        $this->assertNull($this->competitor($event)->publicPhotoUrl($event));
    }

    public function test_null_when_competitor_flag_is_off(): void
    {
        $event = $this->event();

        $this->assertNull($this->competitor($event, ['photo_public' => false])->publicPhotoUrl($event));
    }

    public function test_null_without_photo(): void
    {
        $event = $this->event();

        $this->assertNull($this->competitor($event, ['photo_path' => null])->publicPhotoUrl($event));
    }

    public function test_minor_needs_guardian_consent(): void
    {
        $event = $this->event();
        $minor = $this->competitor($event, ['birth_date' => '2015-01-01']);

        $this->assertNull($minor->publicPhotoUrl($event));

        $minor->guardian_consent_at = now();
        $this->assertNotNull($minor->publicPhotoUrl($event));
    }

    public function test_adult_does_not_need_consent(): void
    {
        $event = $this->event();

        $this->assertNotNull($this->competitor($event, ['guardian_consent_at' => null])->publicPhotoUrl($event));
    }

    public function test_null_without_birth_date_and_no_consent(): void
    {
        $event = $this->event();
        $competitor = $this->competitor($event);
        $competitor->birth_date = null;

        $this->assertNull($competitor->publicPhotoUrl($event));
    }

    private function eventPayload(array $o = []): array
    {
        return array_merge([
            'name' => 'Open', 'discipline' => 'Judo', 'starts_on' => '2026-11-20', 'venue' => 'Pabellón', 'status' => 'draft',
            'is_public' => '0', 'registration_form_enabled' => '0',
        ], $o);
    }

    public function test_organizer_sets_the_event_checkbox_on_create_and_edit(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('panel.events.store'), $this->eventPayload(['show_photos_publicly' => '1']))->assertSessionHasNoErrors();
        $event = Event::firstOrFail();
        $this->assertTrue($event->show_photos_publicly);

        $this->actingAs($user)->put(route('panel.events.update', $event), $this->eventPayload(['show_photos_publicly' => '0']))->assertSessionHasNoErrors();
        $this->assertFalse($event->fresh()->show_photos_publicly);

        $this->actingAs($user)->post(route('panel.events.store'), $this->eventPayload(['name' => 'Otro']))->assertSessionHasNoErrors();
        $this->assertFalse(Event::firstWhere('name', 'Otro')->show_photos_publicly);

        $this->actingAs($user)->get(route('panel.events.edit', $event))
            ->assertSee('Mostrar públicamente las fotos de los inscritos');
    }

    public function test_ficha_saves_photo_public_and_shows_the_checkbox(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['starts_on' => '2026-11-20']);
        $competitor = Competitor::factory()->create(['event_id' => $event->id, 'birth_date' => '1995-03-04', 'photo_public' => true]);
        $payload = ['first_name' => 'Ana', 'last_name' => 'Ruiz Sala', 'birth_date' => '1995-03-04', 'sex' => 'female', 'level' => '1º dan', 'club' => 'Dojo Test'];

        $this->actingAs($user)->put(route('panel.competitors.update', [$event, $competitor]), $payload + ['photo_public' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($competitor->fresh()->photo_public);

        $this->actingAs($user)->put(route('panel.competitors.update', [$event, $competitor]), $payload + ['photo_public' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($competitor->fresh()->photo_public);

        $this->actingAs($user)->get(route('panel.competitors.edit', [$event, $competitor]))
            ->assertSee('Mostrar mi foto públicamente (si el organizador lo permite)')
            ->assertDontSee('no se publica');
    }

    public function test_club_registration_stores_photo_public(): void
    {
        $event = Event::factory()->registrationOpen()->create(['starts_on' => '2026-11-20']);
        $category = Category::factory()->for($event)->create();
        $club = User::factory()->create(['role' => 'club', 'name' => 'Dojo Norte']);
        EventClub::create(['event_id' => $event->id, 'user_id' => $club->id, 'status' => ClubStatus::Approved]);
        $base = [
            'first_name' => 'Lucía', 'last_name' => 'Martín Gil', 'birth_date' => '2000-03-10', 'sex' => 'female',
            'level' => '2º kyu', 'category_id' => $category->id,
        ];

        $this->actingAs($club)->get(route('public.registration.create', $event))
            ->assertSee('Mostrar mi foto públicamente (si el organizador lo permite)')
            ->assertDontSee('la foto no se publica');

        $this->actingAs($club)->post(route('public.registration.store', $event), $base + ['photo_public' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse(Competitor::firstWhere('first_name', 'Lucía')->photo_public);

        $this->actingAs($club)->post(route('public.registration.store', $event), array_merge($base, ['first_name' => 'Eva']))->assertSessionHasNoErrors();
        $this->assertTrue(Competitor::firstWhere('first_name', 'Eva')->photo_public);
    }

    public function test_empty_photo_public_keeps_the_default_instead_of_null(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['starts_on' => '2026-11-20']);
        $competitor = Competitor::factory()->create(['event_id' => $event->id, 'birth_date' => '1995-03-04', 'photo_public' => false]);
        $payload = ['first_name' => 'Ana', 'last_name' => 'Ruiz Sala', 'birth_date' => '1995-03-04', 'sex' => 'female', 'level' => '1º dan', 'club' => 'Dojo Test'];

        $this->actingAs($user)->put(route('panel.competitors.update', [$event, $competitor]), $payload + ['photo_public' => ''])->assertSessionHasNoErrors();
        $this->assertFalse($competitor->fresh()->photo_public);
    }
}
