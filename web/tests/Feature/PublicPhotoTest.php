<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicPhotoTest extends TestCase
{
    use RefreshDatabase;

    private function setUpPhoto(array $eventAttrs = [], array $competitorAttrs = [], bool $withFile = true): array
    {
        Storage::fake('local');
        $event = Event::factory()->create(array_merge(['show_photos_publicly' => true, 'starts_on' => now()->addMonth()], $eventAttrs));
        $competitor = Competitor::factory()->for($event)->create(array_merge([
            'birth_date' => now()->subYears(30)->format('Y-m-d'),
            'photo_path' => 'photos/x.jpg',
            'photo_public' => true,
        ], $competitorAttrs));
        if ($withFile) {
            Storage::disk('local')->put('photos/x.jpg', 'fake-image');
        }

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
        $this->assertStringContainsString('extra', $html);

        $event->update(['show_photos_publicly' => false]);
        $this->assertSame('', trim(Blade::render($tpl, ['c' => $c, 'event' => $event->fresh()])));
    }
}
