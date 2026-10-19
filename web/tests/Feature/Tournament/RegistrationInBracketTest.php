<?php

namespace Tests\Feature\Tournament;

use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Registrations\RegistrationException;
use App\Registrations\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBrackets;
use Tests\TestCase;

class RegistrationInBracketTest extends TestCase
{
    use BuildsBrackets, RefreshDatabase;

    public function test_cancelling_before_results_marks_the_bracket_stale(): void
    {
        $category = $this->generated(4);
        $this->assertFalse($category->bracketIsStale());

        $registration = $category->registrations()->first();
        app(RegistrationService::class)->changeStatus($registration, RegistrationStatus::Cancelled);

        $this->assertTrue($category->refresh()->bracketIsStale());
    }

    public function test_confirming_a_new_registration_marks_the_bracket_stale(): void
    {
        $category = $this->generated(4);
        Registration::factory()->confirmed()->forCategory($category)->create();

        $this->assertTrue($category->refresh()->bracketIsStale());
    }

    public function test_bracket_with_exempt_in_round_two_is_not_stale_until_a_new_registration(): void
    {
        $category = $this->generated(20);
        $this->assertFalse($category->bracketIsStale());

        Registration::factory()->confirmed()->forCategory($category)->create();

        $this->assertTrue($category->refresh()->bracketIsStale());
    }

    public function test_cancelling_a_participant_is_blocked_once_results_exist(): void
    {
        $category = $this->generated(4);
        $semi = $this->bout($category, 1, 1);
        $semi->update(['winner_id' => $semi->slot_a_id]);

        $this->expectException(RegistrationException::class);
        app(RegistrationService::class)->changeStatus(Registration::find($semi->slot_b_id), RegistrationStatus::Cancelled);
    }

    public function test_http_cancellation_shows_the_error_once_results_exist(): void
    {
        $category = $this->generated(4);
        $semi = $this->bout($category, 1, 1);
        $semi->update(['winner_id' => $semi->slot_a_id]);
        $event = $category->event;

        $this->actingAs($event->user)
            ->from('/volver')
            ->patch(route('panel.registrations.update', [$event, $semi->slot_b_id]), ['status' => 'cancelled'])
            ->assertRedirect('/volver')
            ->assertSessionHasErrors('error');

        $this->assertSame(RegistrationStatus::Confirmed, Registration::find($semi->slot_b_id)->status);
    }
}
