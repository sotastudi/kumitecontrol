<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Category;
use App\Models\Competitor;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseRegistrationsTest extends TestCase
{
    use RefreshDatabase;

    private function openEvent(string $status = 'confirmed'): array
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['status' => EventStatus::RegistrationOpen]);
        $category = Category::factory()->for($event)->create();
        $competitor = Competitor::factory()->create(['event_id' => $event->id]);
        Registration::factory()->create(['category_id' => $category->id, 'competitor_id' => $competitor->id, 'status' => $status]);

        return [$user, $event];
    }

    public function test_the_notice_offers_to_close_registrations_when_none_are_pending(): void
    {
        [$user, $event] = $this->openEvent();

        $this->actingAs($user)->get(route('panel.events.show', $event))
            ->assertSee('Cerrar inscripciones')->assertDontSee('Ver pendientes');

        $this->post(route('panel.events.close-registrations', $event))->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->assertSame(EventStatus::RegistrationClosed, $event->fresh()->status);
        $this->get(route('panel.events.show', $event))->assertDontSee('Cierra las inscripciones');
    }

    public function test_with_pending_registrations_it_links_to_them_and_refuses_to_close(): void
    {
        [$user, $event] = $this->openEvent('pending');

        $this->actingAs($user)->get(route('panel.events.show', $event))
            ->assertSee('Ver pendientes')->assertDontSee('Cerrar inscripciones');

        $this->post(route('panel.events.close-registrations', $event))->assertSessionHasErrors('error');
        $this->assertSame(EventStatus::RegistrationOpen, $event->fresh()->status);
    }

    public function test_only_events_with_open_registration_can_be_closed_and_only_by_the_owner(): void
    {
        [$user, $event] = $this->openEvent();
        $event->forceFill(['status' => EventStatus::InProgress])->save();

        $this->actingAs($user)->post(route('panel.events.close-registrations', $event))->assertSessionHasErrors('error');
        $this->assertSame(EventStatus::InProgress, $event->fresh()->status);

        $event->forceFill(['status' => EventStatus::RegistrationOpen])->save();
        $this->actingAs(User::factory()->create())->post(route('panel.events.close-registrations', $event))->assertForbidden();
    }
}
