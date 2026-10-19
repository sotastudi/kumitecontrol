<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_can_register_and_is_logged_in(): void
    {
        $this->post('/registro', [
            'name' => 'Ana', 'email' => 'ana@example.com',
            'password' => 'clave-segura-1', 'password_confirmation' => 'clave-segura-1',
        ])->assertRedirect(route('panel.events.index'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'ana@example.com']);
        $this->assertNotSame('clave-segura-1', User::first()->password);
    }

    public function test_registration_validates_input(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);

        $this->post('/registro', [
            'name' => '', 'email' => 'ana@example.com', 'password' => 'corta', 'password_confirmation' => 'otra',
        ])->assertSessionHasErrors(['name', 'email', 'password']);
        $this->assertGuest();
    }

    public function test_login_logout_and_bad_credentials(): void
    {
        $user = User::factory()->create(['password' => 'clave-segura-1']);

        $this->post('/acceso', ['email' => $user->email, 'password' => 'mala'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/acceso', ['email' => $user->email, 'password' => 'clave-segura-1'])
            ->assertRedirect(route('panel.events.index'));
        $this->assertAuthenticatedAs($user);

        $this->post('/salir')->assertRedirect(route('home'));
        $this->assertGuest();
    }

    public function test_guests_are_sent_to_login_from_the_panel(): void
    {
        $this->get('/panel/eventos')->assertRedirect(route('login'));
    }

    public function test_password_reset_flow(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/recuperar-contrasena', ['email' => $user->email])->assertSessionHas('status');
        $this->post('/recuperar-contrasena', ['email' => 'nadie@example.com'])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post('/restablecer-contrasena', [
                'token' => $notification->token, 'email' => $user->email,
                'password' => 'nueva-clave-123', 'password_confirmation' => 'nueva-clave-123',
            ])->assertRedirect(route('login'));

            return true;
        });

        $this->post('/acceso', ['email' => $user->email, 'password' => 'nueva-clave-123']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_reset_form_shows_the_email_from_the_link_as_read_only(): void
    {
        $this->get(route('password.reset', ['token' => 'abc', 'email' => 'ana@example.com']))
            ->assertOk()->assertSee('value="ana@example.com"', false)->assertSee('readonly', false);

        // Sin correo en el enlace el campo sigue editable para poder completarlo.
        $this->get(route('password.reset', ['token' => 'abc']))->assertOk()->assertDontSee('readonly', false);
    }

    public function test_reset_password_email_is_in_spanish(): void
    {
        $user = User::factory()->create();
        $mail = (new ResetPassword('token-123'))->toMail($user);
        $html = (string) $mail->render();

        $this->assertSame('Restablece tu contraseña', $mail->subject);
        $this->assertStringContainsString('Restablecer contraseña', $html);
        $this->assertStringContainsString('¡Hola!', $html);
        $this->assertStringContainsString('Saludos,', $html);
        $this->assertStringContainsString('copia y pega la siguiente URL', $html);
        $this->assertStringNotContainsString('Reset Password', $html);
        $this->assertStringNotContainsString('Regards', $html);
    }

    public function test_reset_link_survives_mail_failure(): void
    {
        // Esquema SMTP inválido, como en producción: el envío síncrono lanza al construir el transporte.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.scheme' => 'tls', 'mail.mailers.smtp.url' => null]);
        Log::spy();
        $user = User::factory()->create();

        $existing = $this->post('/recuperar-contrasena', ['email' => $user->email]);
        $unknown = $this->post('/recuperar-contrasena', ['email' => 'nadie@example.com']);

        $existing->assertRedirect()->assertSessionHas('status', __('passwords.sent'));
        $unknown->assertRedirect()->assertSessionHas('status', __('passwords.sent'));
        Log::shouldHaveReceived('error')->once();
    }

    public function test_reset_does_not_reveal_whether_the_email_exists(): void
    {
        User::factory()->create(['email' => 'real@example.com']);
        $payload = ['token' => 'token-falso', 'password' => 'nueva-clave-123', 'password_confirmation' => 'nueva-clave-123'];

        $unknown = $this->post(route('password.update'), $payload + ['email' => 'nadie@example.com']);
        $known = $this->post(route('password.update'), $payload + ['email' => 'real@example.com']);

        $unknown->assertSessionHasErrors('email');
        $this->assertSame(
            $unknown->getSession()->get('errors')->first('email'),
            $known->getSession()->get('errors')->first('email'),
        );
    }

    public function test_password_update_is_throttled(): void
    {
        $payload = ['token' => 't', 'email' => 'x@example.com', 'password' => 'nueva-clave-123', 'password_confirmation' => 'nueva-clave-123'];
        for ($i = 0; $i < 6; $i++) {
            $this->post(route('password.update'), $payload);
        }

        $this->post(route('password.update'), $payload)->assertStatus(429);
    }
}
