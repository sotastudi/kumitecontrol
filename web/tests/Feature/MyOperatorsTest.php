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
}
