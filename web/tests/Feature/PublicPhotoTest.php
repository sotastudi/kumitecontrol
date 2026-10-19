<?php

namespace Tests\Feature;

use App\Enums\ClubStatus;
use App\Enums\EventStatus;
use App\Models\Category;
use App\Models\Competitor;
use App\Models\Event;
use App\Models\EventClub;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
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

    public function test_ficha_flag_wins_for_a_minor_with_consent(): void
    {
        $event = $this->event();
        $minor = $this->competitor($event, ['birth_date' => '2015-01-01', 'guardian_consent_at' => now(), 'photo_public' => false]);

        $this->assertNull($minor->publicPhotoUrl($event));
    }

    public function test_no_birth_date_with_consent_shows_photo(): void
    {
        $event = $this->event();
        $competitor = $this->competitor($event, ['guardian_consent_at' => now()]);
        $competitor->birth_date = null;

        $this->assertNotNull($competitor->publicPhotoUrl($event));
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
            ->assertSee('Mostrar la foto públicamente (si el organizador lo permite)')
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
            ->assertSee('Mostrar la foto públicamente (si el organizador lo permite)')
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

    private function setUpPhoto(array $eventAttrs = [], array $competitorAttrs = [], bool $withFile = true): array
    {
        Storage::fake('local');
        $event = Event::factory()->create(array_merge(['show_photos_publicly' => true, 'starts_on' => now()->addMonth(), 'is_public' => true, 'status' => EventStatus::RegistrationOpen], $eventAttrs));
        $competitor = Competitor::factory()->for($event)->create(array_merge([
            'birth_date' => now()->subYears(30)->format('Y-m-d'),
            'photo_path' => 'photos/x.jpg',
            'photo_public' => true,
        ], $competitorAttrs));
        if ($withFile) {
            Storage::disk('local')->put('photos/x.jpg', 'fake-image');
        }
        // Por defecto, inscripción confirmada en una categoría publicada (lo único que ve un visitante).
        Registration::factory()->confirmed()->create([
            'category_id' => Category::factory()->for($event)->create(['is_published' => true])->id,
            'competitor_id' => $competitor->id,
        ]);

        return [$event, $competitor];
    }

    private function withCode(Event $event): static
    {
        return $this->withSession(['event_access' => [$event->id => $event->accessToken()]]);
    }

    public function test_serves_photo_with_access_code(): void
    {
        [$event, $c] = $this->setUpPhoto();

        $response = $this->withCode($event)->get(route('public.competitors.photo', [$event, $c]));

        $response->assertOk();
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
    }

    public function test_redirects_without_code(): void
    {
        [$event, $c] = $this->setUpPhoto();

        $this->get(route('public.competitors.photo', [$event, $c]))->assertRedirect(route('public.events.show', $event));
    }

    public function test_event_flag_off_is_404(): void
    {
        [$event, $c] = $this->setUpPhoto(['show_photos_publicly' => false]);

        $this->withCode($event)->get(route('public.competitors.photo', [$event, $c]))->assertNotFound();
    }

    public function test_competitor_flag_off_is_404(): void
    {
        [$event, $c] = $this->setUpPhoto([], ['photo_public' => false]);

        $this->withCode($event)->get(route('public.competitors.photo', [$event, $c]))->assertNotFound();
    }

    public function test_minor_without_consent_is_404(): void
    {
        [$event, $c] = $this->setUpPhoto([], ['birth_date' => now()->subYears(10)->format('Y-m-d')]);

        $this->withCode($event)->get(route('public.competitors.photo', [$event, $c]))->assertNotFound();
    }

    public function test_competitor_of_another_event_is_404(): void
    {
        [$event] = $this->setUpPhoto();
        $other = Competitor::factory()->for(Event::factory()->create())->create();

        $this->withCode($event)->get(route('public.competitors.photo', [$event, $other]))->assertNotFound();
    }

    public function test_missing_file_is_404(): void
    {
        [$event, $c] = $this->setUpPhoto([], [], false);

        $this->withCode($event)->get(route('public.competitors.photo', [$event, $c]))->assertNotFound();
    }

    public function test_owner_and_operator_pass_without_code(): void
    {
        [$event, $c] = $this->setUpPhoto();
        $operator = User::factory()->create();
        $event->operators()->create(['user_id' => $operator->id, 'tatami' => 1]);

        $this->actingAs($event->user)->get(route('public.competitors.photo', [$event, $c]))->assertOk();
        $this->actingAs($operator)->get(route('public.competitors.photo', [$event, $c]))->assertOk();
    }

    public function test_component_renders_only_when_rule_passes(): void
    {
        [$event, $c] = $this->setUpPhoto();
        $tpl = '<x-competitor-photo :competitor="$c" :event="$event" class="extra" />';

        $html = Blade::render($tpl, ['c' => $c, 'event' => $event]);
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString(route('public.competitors.photo', [$event, $c]), $html);
        $this->assertStringContainsString('competitor-photo-sm', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('onerror="this.remove()"', $html);
        $this->assertStringContainsString('alt="Foto de '.e($c->displayName()).'"', $html);
        $this->assertStringContainsString('extra', $html);
        $this->assertStringContainsString('competitor-photo-lg', Blade::render('<x-competitor-photo :competitor="$c" :event="$event" size="lg" />', ['c' => $c, 'event' => $event]));

        $c->update(['first_name' => '<b>Mal</b>']);
        $escaped = Blade::render($tpl, ['c' => $c->fresh(), 'event' => $event]);
        $this->assertStringNotContainsString('<b>Mal', $escaped);

        $event->update(['show_photos_publicly' => false]);
        $this->assertSame('', trim(Blade::render($tpl, ['c' => $c, 'event' => $event->fresh()])));
    }

    /** Datos de un competidor con foto pública cuya única inscripción es la indicada. */
    private function photoWithRegistration(string $status, bool $published, array $eventAttrs = []): array
    {
        [$event, $c] = $this->setUpPhoto($eventAttrs);
        $c->registrations()->delete();
        $registration = Registration::factory()->create([
            'category_id' => Category::factory()->for($event)->create(['is_published' => $published])->id,
            'competitor_id' => $c->id,
            'status' => $status,
        ]);

        return [$event, $c, $registration];
    }

    public function test_visitor_sees_photo_only_when_some_category_is_published(): void
    {
        [$event, $c] = $this->setUpPhoto();
        Registration::factory()->confirmed()->create([
            'category_id' => Category::factory()->for($event)->create(['is_published' => false])->id,
            'competitor_id' => $c->id,
        ]);

        $this->withCode($event)->get(route('public.competitors.photo', [$event, $c]))->assertOk();
    }

    private function operatorOf(Event $event): User
    {
        $operator = User::factory()->create();
        $event->operators()->create(['user_id' => $operator->id, 'tatami' => 1]);

        return $operator;
    }

    public function test_unlisted_event_with_old_session_code_is_404_for_visitor_but_ok_for_owner_and_operator(): void
    {
        [$event, $c] = $this->setUpPhoto(['status' => EventStatus::Draft, 'is_public' => false]);
        $url = route('public.competitors.photo', [$event, $c]);

        $this->withCode($event)->get($url)->assertNotFound();
        $this->actingAs($event->user)->get($url)->assertOk();
        $this->actingAs($this->operatorOf($event))->get($url)->assertOk();
    }

    public function test_pending_and_cancelled_registrations_are_404_for_visitor(): void
    {
        foreach (['pending', 'cancelled'] as $status) {
            [$event, $c] = $this->photoWithRegistration($status, true);

            $this->withCode($event)->get(route('public.competitors.photo', [$event, $c]))->assertNotFound();
        }
    }

    public function test_competitor_without_registration_is_404_for_visitor(): void
    {
        [$event, $c] = $this->setUpPhoto();
        $c->registrations()->delete();

        $this->withCode($event)->get(route('public.competitors.photo', [$event, $c]))->assertNotFound();
    }

    public function test_unpublished_category_is_404_for_visitor_but_ok_for_owner_and_operator(): void
    {
        [$event, $c] = $this->photoWithRegistration('confirmed', false);
        $url = route('public.competitors.photo', [$event, $c]);

        $this->withCode($event)->get($url)->assertNotFound();
        $this->actingAs($event->user)->get($url)->assertOk();
        $this->actingAs($this->operatorOf($event))->get($url)->assertOk();
    }

    public function test_confirmed_in_published_category_is_ok_for_visitor(): void
    {
        [$event, $c] = $this->photoWithRegistration('confirmed', true);

        $this->withCode($event)->get(route('public.competitors.photo', [$event, $c]))->assertOk();
    }
}
