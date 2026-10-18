<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Event;
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
}
