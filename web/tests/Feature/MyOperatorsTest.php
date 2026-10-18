<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyOperatorsTest extends TestCase
{
    use RefreshDatabase;

    private function account(User $creator, string $username, array $extra = []): User
    {
        $user = User::factory()->create(['username' => $username, 'email' => null] + $extra);
        $user->forceFill(['role' => 'tatami', 'created_by_user_id' => $creator->id])->save();

        return $user;
    }

    public function test_organizer_sees_own_accounts_and_those_operating_in_their_events_but_not_others(): void
    {
        $org = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->account($org, 'mia.uno');
        $foreign = $this->account($other, 'ajena.dos');
        $operating = $this->account($other, 'opera.tres');
        $event = Event::factory()->for($org)->create(['tatami_count' => 2]);
        $event->operators()->create(['user_id' => $operating->id, 'tatami' => 2]);

        $this->actingAs($org)->get(route('panel.my-operators.index'))->assertOk()
            ->assertSee('@mia.uno')->assertSee('@opera.tres')->assertDontSee('@ajena.dos');
    }

    public function test_club_tatami_and_superadmin_get_403(): void
    {
        $org = User::factory()->create();
        foreach (['club', 'tatami', 'superadmin'] as $role) {
            $u = User::factory()->create();
            $u->forceFill(['role' => $role])->save();
            $this->actingAs($u)->get(route('panel.my-operators.index'))->assertForbidden();
            $this->actingAs($u)->post(route('panel.my-operators.store'), ['username' => 'abc', 'password' => 'secreto1'])->assertForbidden();
        }
    }

    public function test_creates_account_normalizing_username_without_assigning_events(): void
    {
        $org = User::factory()->create();
        Event::factory()->for($org)->create();

        $this->actingAs($org)->post(route('panel.my-operators.store'), ['username' => ' Juez.UNO ', 'password' => 'clave-123'])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $user = User::where('username', 'juez.uno')->firstOrFail();
        $this->assertTrue($user->isTatamiAccount());
        $this->assertSame($org->id, $user->created_by_user_id);
        $this->assertSame('juez.uno', $user->name);
        $this->assertTrue(\Hash::check('clave-123', $user->password));
        $this->assertSame(0, $user->tatamiOperations()->count());
    }

    public function test_creation_validates_regex_password_and_duplicates(): void
    {
        $org = User::factory()->create();
        $this->account($org, 'repetido');

        $this->actingAs($org)->post(route('panel.my-operators.store'), ['username' => 'mal usuario!', 'password' => 'clave-123'])->assertSessionHasErrors('username');
        $this->actingAs($org)->post(route('panel.my-operators.store'), ['username' => 'valido', 'password' => '123'])->assertSessionHasErrors('password');
        $this->actingAs($org)->post(route('panel.my-operators.store'), ['username' => 'valido'])->assertSessionHasErrors('password');
        $this->actingAs($org)->post(route('panel.my-operators.store'), ['username' => 'REPETIDO', 'password' => 'clave-123'])
            ->assertSessionHasErrors(['username' => 'Ese usuario ya existe']);
        $this->assertSame(1, User::where('role', 'tatami')->count());
    }

    public function test_update_keeps_password_when_blank_and_rejects_duplicate_email(): void
    {
        $org = User::factory()->create();
        $acc = $this->account($org, 'edita', ['password' => 'vieja-123']);
        $taken = User::factory()->create(['email' => 'ocupado@example.com']);
        $hash = $acc->fresh()->password;

        $this->actingAs($org)->put(route('panel.my-operators.update', $acc), ['username' => 'Editado', 'name' => 'Nombre', 'email' => 'nuevo@example.com', 'password' => ''])
            ->assertSessionHasNoErrors();
        $acc->refresh();
        $this->assertSame('editado', $acc->username);
        $this->assertSame('nuevo@example.com', $acc->email);
        $this->assertSame($hash, $acc->password);

        $this->actingAs($org)->put(route('panel.my-operators.update', $acc), ['username' => 'editado', 'email' => $taken->email])->assertSessionHasErrors('email');
        // Conservar su propio usuario/correo no es duplicado; cambiar contraseña sí la cambia.
        $this->actingAs($org)->put(route('panel.my-operators.update', $acc), ['username' => 'editado', 'email' => 'nuevo@example.com', 'password' => 'otra-clave'])->assertSessionHasNoErrors();
        $this->assertTrue(\Hash::check('otra-clave', $acc->fresh()->password));
    }

    public function test_cannot_update_or_delete_an_account_created_by_someone_else(): void
    {
        $org = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $this->account($other, 'ajena');
        $event = Event::factory()->for($org)->create();
        $event->operators()->create(['user_id' => $foreign->id, 'tatami' => 1]);

        $this->actingAs($org)->put(route('panel.my-operators.update', $foreign), ['username' => 'robada'])->assertForbidden();
        $this->actingAs($org)->delete(route('panel.my-operators.destroy', $foreign))->assertForbidden();
        $this->assertSame('ajena', $foreign->fresh()->username);
    }

    public function test_deleting_own_account_removes_its_rows_but_is_refused_if_assigned_in_another_organizers_event(): void
    {
        $org = User::factory()->create();
        $other = User::factory()->create();
        $free = $this->account($org, 'libre');
        $busy = $this->account($org, 'ocupada');
        $mine = Event::factory()->for($org)->create();
        $theirs = Event::factory()->for($other)->create();
        $mine->operators()->create(['user_id' => $free->id, 'tatami' => 1]);
        $mine->operators()->create(['user_id' => $busy->id, 'tatami' => 1]);
        $theirs->operators()->create(['user_id' => $busy->id, 'tatami' => 1]);

        $this->actingAs($org)->delete(route('panel.my-operators.destroy', $busy))->assertSessionHasErrors('operator');
        $this->assertNotNull($busy->fresh());
        $this->assertSame(2, $busy->tatamiOperations()->count());

        $this->actingAs($org)->delete(route('panel.my-operators.destroy', $free))->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->assertNull($free->fresh());
        $this->assertSame(0, $mine->operators()->where('user_id', $free->id)->count());
    }

    public function test_role_forbidden_callers_and_non_tatami_targets_get_403_on_update_and_destroy(): void
    {
        $org = User::factory()->create();
        $acc = $this->account($org, 'objetivo');
        foreach (['club', 'tatami', 'superadmin'] as $role) {
            $u = User::factory()->create();
            $u->forceFill(['role' => $role])->save();
            $this->actingAs($u)->put(route('panel.my-operators.update', $acc), ['username' => 'cambiado'])->assertForbidden();
            $this->actingAs($u)->delete(route('panel.my-operators.destroy', $acc))->assertForbidden();
        }
        $this->assertNotNull($acc->fresh());
        $this->assertSame('objetivo', $acc->fresh()->username);

        // Un usuario que no es de tatami, aunque lo hubiera creado el organizador.
        $club = User::factory()->create();
        $club->forceFill(['role' => 'club', 'created_by_user_id' => $org->id])->save();
        $this->actingAs($org)->put(route('panel.my-operators.update', $club), ['username' => 'robado'])->assertForbidden();
        $this->actingAs($org)->delete(route('panel.my-operators.destroy', $club))->assertForbidden();
        $this->assertNotNull($club->fresh());
    }

    public function test_update_rejects_username_taken_by_another_user_and_reopens_edit_form(): void
    {
        $org = User::factory()->create();
        $acc = $this->account($org, 'primera');
        $this->account($org, 'segunda');

        $this->actingAs($org)->from(route('panel.my-operators.index'))
            ->put(route('panel.my-operators.update', $acc), ['_form' => 'edit-'.$acc->id, 'username' => 'SEGUNDA', 'name' => 'Nombre nuevo'])
            ->assertSessionHasErrors(['username' => 'Ese usuario ya existe']);
        $this->assertSame('primera', $acc->fresh()->username);

        $res = $this->actingAs($org)->followingRedirects()->from(route('panel.my-operators.index'))
            ->put(route('panel.my-operators.update', $acc), ['_form' => 'edit-'.$acc->id, 'username' => 'segunda', 'name' => 'Nombre nuevo']);
        $res->assertSee('id="edit-operator-'.$acc->id.'"', false);
        $res->assertSee('collapse show mt-3', false);
        $res->assertSee('value="Nombre nuevo"', false);
    }

    private function tatamiAccountAndEvents(): array
    {
        $org = User::factory()->create();
        $acc = $this->account($org, 'juez.multi');
        $e1 = Event::factory()->for($org)->create(['tatami_count' => 3, 'starts_on' => '2026-11-01']);
        $e2 = Event::factory()->for($org)->create(['tatami_count' => 2, 'starts_on' => '2026-12-01']);

        return [$org, $acc, $e1, $e2];
    }

    public function test_assigns_to_two_events_with_different_tatamis(): void
    {
        [$org, $acc, $e1, $e2] = $this->tatamiAccountAndEvents();

        $this->actingAs($org)->put(route('panel.my-operators.assign', [$acc, $e1]), ['tatamis' => [1, 3]])->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->actingAs($org)->put(route('panel.my-operators.assign', [$acc, $e2]), ['tatamis' => [2]])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([1, 3], $e1->operators()->where('user_id', $acc->id)->pluck('tatami')->all());
        $this->assertEqualsCanonicalizing([2], $e2->operators()->where('user_id', $acc->id)->pluck('tatami')->all());
    }

    public function test_reassigning_removes_unchecked_and_does_not_duplicate(): void
    {
        [$org, $acc, $e1] = $this->tatamiAccountAndEvents();
        $e1->operators()->create(['user_id' => $acc->id, 'tatami' => 1]);
        $e1->operators()->create(['user_id' => $acc->id, 'tatami' => 2]);

        $this->actingAs($org)->put(route('panel.my-operators.assign', [$acc, $e1]), ['tatamis' => [2, 3, 3]])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([2, 3], $e1->operators()->where('user_id', $acc->id)->pluck('tatami')->all());
    }

    public function test_assign_without_tatamis_removes_the_operator_from_that_event_only(): void
    {
        [$org, $acc, $e1, $e2] = $this->tatamiAccountAndEvents();
        $e1->operators()->create(['user_id' => $acc->id, 'tatami' => 1]);
        $e2->operators()->create(['user_id' => $acc->id, 'tatami' => 1]);

        $this->actingAs($org)->put(route('panel.my-operators.assign', [$acc, $e1]))->assertSessionHasNoErrors();

        $this->assertSame(0, $e1->operators()->where('user_id', $acc->id)->count());
        $this->assertSame(1, $e2->operators()->where('user_id', $acc->id)->count());
    }

    public function test_assign_to_foreign_event_or_invisible_account_is_forbidden(): void
    {
        [$org, $acc] = $this->tatamiAccountAndEvents();
        $other = User::factory()->create();
        $foreignEvent = Event::factory()->for($other)->create(['tatami_count' => 2]);
        $foreignAcc = $this->account($other, 'de.otro');
        $mine = Event::factory()->for($org)->create(['tatami_count' => 2]);

        $this->actingAs($org)->put(route('panel.my-operators.assign', [$acc, $foreignEvent]), ['tatamis' => [1]])->assertForbidden();
        $this->actingAs($org)->put(route('panel.my-operators.assign', [$foreignAcc, $mine]), ['tatamis' => [1]])->assertForbidden();
        $this->assertSame(0, \App\Models\TatamiOperator::count());

        foreach (['club', 'tatami', 'superadmin'] as $role) {
            $u = User::factory()->create();
            $u->forceFill(['role' => $role])->save();
            $this->actingAs($u)->put(route('panel.my-operators.assign', [$acc, $mine]), ['tatamis' => [1]])->assertForbidden();
        }
    }

    public function test_assign_out_of_range_tatami_is_422(): void
    {
        [$org, $acc, , $e2] = $this->tatamiAccountAndEvents();

        $this->actingAs($org)->putJson(route('panel.my-operators.assign', [$acc, $e2]), ['tatamis' => [3]])->assertStatus(422);
        $this->actingAs($org)->putJson(route('panel.my-operators.assign', [$acc, $e2]), ['tatamis' => [0]])->assertStatus(422);
        $this->assertSame(0, $e2->operators()->count());
    }

    public function test_view_lists_events_with_tatamis_only_and_marks_current_ones(): void
    {
        [$org, $acc, $e1, $e2] = $this->tatamiAccountAndEvents();
        $none = Event::factory()->for($org)->create(['tatami_count' => 0, 'name' => 'Evento sin tatamis']);
        $e1->operators()->create(['user_id' => $acc->id, 'tatami' => 3]);

        $res = $this->actingAs($org)->get(route('panel.my-operators.index'))->assertOk();
        $res->assertSee(route('panel.my-operators.assign', [$acc, $e1]), false)
            ->assertSee(route('panel.my-operators.assign', [$acc, $e2]), false)
            ->assertDontSee(route('panel.my-operators.assign', [$acc, $none]), false);
        $this->assertMatchesRegularExpression('/value="3"[^>]*checked/', $res->getContent());
        $this->assertDoesNotMatchRegularExpression('/value="1"[^>]*checked/', $res->getContent());
    }

    public function test_assigned_account_sees_event_opens_its_tatami_and_event_page_lists_it(): void
    {
        [$org, $acc, $e1] = $this->tatamiAccountAndEvents();
        $this->actingAs($org)->put(route('panel.my-operators.assign', [$acc, $e1]), ['tatamis' => [2]]);
        // Con un único tatami el panel redirige directo a él.
        $this->actingAs($acc)->get(route('panel.events.index'))->assertRedirect(route('panel.tatamis.show', [$e1, 2]));
        $this->actingAs($org)->put(route('panel.my-operators.assign', [$acc, $e1]), ['tatamis' => [2, 3]]);
        $this->actingAs($acc)->get(route('panel.events.index'))->assertOk()->assertSee('Tatami 2')->assertSee('Tatami 3');
        $this->actingAs($acc)->get(route('panel.tatamis.show', [$e1, 2]))->assertOk();
        $this->actingAs($acc)->get(route('panel.tatamis.show', [$e1, 1]))->assertForbidden();
        $this->actingAs($org)->get(route('panel.events.show', $e1))->assertOk()->assertSee('juez.multi');
    }

    public function test_assign_to_event_without_tatamis_is_rejected_and_writes_nothing(): void
    {
        [$org, $acc] = $this->tatamiAccountAndEvents();
        $none = Event::factory()->for($org)->create(['tatami_count' => 0]);

        $this->actingAs($org)->put(route('panel.my-operators.assign', [$acc, $none]), ['tatamis' => [1]])->assertNotFound();
        $this->assertSame(0, \App\Models\TatamiOperator::count());
    }

    public function test_event_page_links_to_my_operators_and_help_mentions_it(): void
    {
        $org = User::factory()->create();
        $event = Event::factory()->for($org)->create(['tatami_count' => 2]);

        $this->actingAs($org)->get(route('panel.events.show', $event))->assertOk()
            ->assertSee('Gestionar todos tus controladores')
            ->assertSee('href="'.route('panel.my-operators.index').'"', false);
        $this->get(route('help'))->assertOk()->assertSee('Mis controladores');
    }
}
