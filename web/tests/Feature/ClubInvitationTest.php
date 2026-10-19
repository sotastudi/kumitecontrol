<?php

namespace Tests\Feature;

use App\Enums\ClubStatus;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventClub;
use App\Models\User;
use App\Notifications\ClubInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ClubInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_adds_a_known_club_to_another_event_without_duplicating_the_account(): void
    {
        $owner = User::factory()->create();
        $first = Event::factory()->for($owner)->registrationOpen()->create();
        $second = Event::factory()->for($owner)->registrationOpen()->create();
        $club = $this->clubFor($first, name: 'Dojo Norte');

        $this->actingAs($owner)->get(route('panel.clubs.index', $second))->assertSee('Dojo Norte');
        $this->post(route('panel.clubs.add', $second), ['user_id' => $club->id])->assertSessionHasNoErrors();

        $this->assertSame(ClubStatus::Approved, $club->clubRegistrationFor($second)->status);
        $this->assertSame(1, User::where('role', 'club')->count());
        // Ya no se ofrece de nuevo en ese evento.
        $this->get(route('panel.clubs.index', $second))->assertDontSee('<option value="'.$club->id.'">', false);
    }

    public function test_organizer_can_delete_a_rejected_club_from_the_event_list(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->registrationOpen()->create();
        $rejected = $this->clubFor($event, ClubStatus::Rejected, 'Dojo Rechazado');
        $approved = $this->clubFor($event, ClubStatus::Approved, 'Dojo Aprobado');
        $registration = $rejected->clubRegistrationFor($event);

        // El botón «Eliminar» solo sale en la fila del club rechazado.
        $html = $this->actingAs($owner)->get(route('panel.clubs.index', $event))->getContent();
        $this->assertSame(1, substr_count($html, 'Eliminar este club de la lista del evento'));

        $this->delete(route('panel.clubs.destroy', [$event, $registration]))->assertRedirect()->assertSessionHas('status');

        $this->assertNull($rejected->clubRegistrationFor($event));
        $this->assertDatabaseHas('users', ['id' => $rejected->id]);
        $this->assertNotNull($approved->clubRegistrationFor($event));

        // Puede volver a ser invitado.
        Notification::fake();
        $this->post(route('panel.clubs.invite', $event), ['email' => $rejected->email])->assertSessionHasNoErrors();
        $this->assertSame(ClubStatus::Invited, $rejected->clubRegistrationFor($event)->status);
    }

    public function test_only_rejected_clubs_can_be_deleted(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->registrationOpen()->create();

        foreach ([ClubStatus::Approved, ClubStatus::Pending, ClubStatus::Invited] as $i => $status) {
            $registration = $this->clubFor($event, $status, "Club {$i}")->clubRegistrationFor($event);
            $this->actingAs($owner)->delete(route('panel.clubs.destroy', [$event, $registration]))->assertNotFound();
            $this->assertDatabaseHas('event_clubs', ['id' => $registration->id]);
        }
    }

    public function test_cannot_delete_a_rejected_club_through_another_event_or_organizer(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->registrationOpen()->create();
        $other = Event::factory()->for($owner)->registrationOpen()->create();
        $registration = $this->clubFor($other, ClubStatus::Rejected)->clubRegistrationFor($other);

        $this->actingAs($owner)->delete(route('panel.clubs.destroy', [$event, $registration]))->assertNotFound();
        $this->actingAs(User::factory()->create())->delete(route('panel.clubs.destroy', [$other, $registration]))->assertForbidden();
        $this->assertDatabaseHas('event_clubs', ['id' => $registration->id]);
    }

    public function test_organizer_cannot_add_a_club_that_is_not_theirs(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->registrationOpen()->create();
        $stranger = $this->clubFor(Event::factory()->registrationOpen()->create());

        $this->actingAs($owner)->post(route('panel.clubs.add', $event), ['user_id' => $stranger->id])->assertNotFound();
        $this->assertNull($stranger->clubRegistrationFor($event));
    }

    public function test_invitation_is_emailed_and_the_club_must_accept_it_before_registering(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['name' => 'Federación Norte']);
        $event = Event::factory()->for($owner)->registrationOpen()->create(['name' => 'Copa Otoño', 'starts_on' => '2026-11-20']);
        $category = Category::factory()->for($event)->create();
        $club = $this->clubFor(Event::factory()->registrationOpen()->create(), name: 'Dojo Sur');

        $this->actingAs($owner)->post(route('panel.clubs.invite', $event), ['email' => $club->email])->assertSessionHasNoErrors();
        Notification::assertSentTo($club, ClubInvitation::class);
        $this->assertSame(ClubStatus::Invited, $club->clubRegistrationFor($event)->status);

        // Sin aceptar no puede inscribir y ve un aviso con el evento.
        $this->actingAs($club);
        $payload = ['first_name' => 'Ana', 'last_name' => 'Gil', 'birth_date' => '2000-01-01', 'sex' => 'female', 'level' => '2º kyu', 'category_id' => $category->id];
        $this->post(route('public.registration.store', $event), $payload)->assertForbidden();
        $this->get('/')->assertSee('1 invitación pendiente');
        $this->get(route('panel.invitations.index'))->assertSee('Copa Otoño')->assertSee('Federación Norte')->assertSee('Confirmar invitación');

        $invitation = $club->clubRegistrationFor($event);
        $this->post(route('panel.invitations.accept', $invitation))->assertRedirect(route('panel.invitations.index'));
        $this->assertSame(ClubStatus::Approved, $invitation->fresh()->status);
        $this->post(route('public.registration.store', $event), $payload)->assertSessionHasNoErrors();
    }

    public function test_club_can_decline_and_cannot_touch_other_clubs_invitations(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->registrationOpen()->create();
        $club = $this->clubFor(Event::factory()->registrationOpen()->create());
        $other = $this->clubFor(Event::factory()->registrationOpen()->create(), name: 'Otro Club');
        EventClub::create(['event_id' => $event->id, 'user_id' => $club->id, 'status' => ClubStatus::Invited]);
        $invitation = $club->clubRegistrationFor($event);

        $this->actingAs($other)->post(route('panel.invitations.accept', $invitation))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('panel.invitations.index'))->assertForbidden();

        $this->actingAs($club)->post(route('panel.invitations.decline', $invitation))->assertRedirect();
        $this->assertSame(ClubStatus::Rejected, $invitation->fresh()->status);
        $this->post(route('panel.invitations.accept', $invitation))->assertForbidden();
    }

    public function test_invite_validations_and_resend(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->registrationOpen()->create();
        $club = $this->clubFor(Event::factory()->registrationOpen()->create());
        $member = $this->clubFor($event, name: 'Ya dentro');

        $this->actingAs($owner);
        $this->post(route('panel.clubs.invite', $event), ['email' => 'nadie@example.com'])->assertSessionHasErrors('invite_email');
        $this->post(route('panel.clubs.invite', $event), ['email' => User::factory()->create()->email])->assertSessionHasErrors('invite_email');
        $this->post(route('panel.clubs.invite', $event), ['email' => $member->email])->assertSessionHasErrors('invite_email');

        $this->post(route('panel.clubs.invite', $event), ['email' => $club->email])->assertSessionHasNoErrors();
        $this->post(route('panel.clubs.invite', $event), ['email' => $club->email])->assertSessionHasErrors('invite_email');
        $this->post(route('panel.clubs.resend', [$event, $club->clubRegistrationFor($event)]))->assertSessionHasNoErrors();
        Notification::assertSentToTimes($club, ClubInvitation::class, 2);

        // El organizador no puede aprobar por su cuenta una invitación pendiente.
        $this->put(route('panel.clubs.update', [$event, $club->clubRegistrationFor($event)]), ['status' => 'invited'])->assertSessionHasErrors('status');
    }

    public function test_club_account_lists_the_events_it_is_linked_to_with_their_status(): void
    {
        $owner = User::factory()->create(['name' => 'Federación Norte']);
        $approved = Event::factory()->for($owner)->registrationOpen()->create(['name' => 'Copa Confirmada']);
        $pending = Event::factory()->for($owner)->registrationOpen()->create(['name' => 'Copa Pendiente']);
        $invited = Event::factory()->for($owner)->registrationOpen()->create(['name' => 'Copa Invitada']);
        $other = Event::factory()->registrationOpen()->create(['name' => 'Copa Ajena']);
        $club = $this->clubFor($approved);
        EventClub::create(['event_id' => $pending->id, 'user_id' => $club->id, 'status' => ClubStatus::Pending]);
        EventClub::create(['event_id' => $invited->id, 'user_id' => $club->id, 'status' => ClubStatus::Invited]);

        $this->actingAs($club)->get('/')->assertSee('Mis eventos');
        $this->get(route('panel.events.index'))->assertOk()
            ->assertSee('Copa Confirmada')->assertSee('Confirmado')->assertSee('Inscribir competidores')
            ->assertSee('Copa Pendiente')->assertSee('Pendiente de confirmar por el organizador')
            ->assertSee('Copa Invitada')->assertSee('Invitación pendiente de tu confirmación')
            ->assertDontSee('Copa Ajena');
    }
}
