<?php

namespace App\Http\Controllers\Panel;

use App\Enums\ClubStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventClub;
use App\Models\User;
use App\Notifications\ClubInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Clubes registrados en el evento: el organizador los aprueba o rechaza antes de que puedan inscribir competidores. */
class ClubController extends Controller
{
    public function index(Event $event): View
    {
        return view('panel.clubs.index', [
            'event' => $event,
            'clubs' => $event->clubs()->with('user')->get()->sortBy(fn ($c) => mb_strtolower($c->user->name))->values(),
            'knownClubs' => $this->knownClubs($event, request()->user()),
        ]);
    }

    /**
     * El organizador crea la cuenta de un club y lo deja ya aprobado en este evento. Si el correo ya es de una cuenta de club,
     * solo se registra y aprueba en el evento (no se toca su contraseña ni sus datos).
     */
    public function store(Request $request, Event $event): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', Password::min(8)],
            'generate_password' => ['boolean'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user && ! $user->isClubAccount()) {
            return back()->withInput()->withErrors(['email' => 'Ese correo pertenece a una cuenta que no es de club.']);
        }

        $created = false;
        if (! $user) {
            $generated = null;
            if ($request->boolean('generate_password')) {
                $data['password'] = $generated = $this->newPassword();
            } elseif (blank($data['password'] ?? null)) {
                return back()->withInput()->withErrors(['password' => 'Indica una contraseña (mínimo 8 caracteres) o marca «Generar contraseña automática».']);
            }
            if (User::where('role', 'club')->where('name', $data['name'])->exists()) {
                return back()->withInput()->withErrors(['name' => 'Ya existe una cuenta de club con ese nombre.']);
            }
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->forceFill(['role' => 'club', 'created_by_user_id' => $request->user()->id])->save();
            $created = true;
        }

        $event->clubs()->updateOrCreate(['user_id' => $user->id], ['status' => ClubStatus::Approved]);

        return back()->with($created && $generated ? ['generated_password' => ['club' => $user->name, 'email' => $user->email, 'password' => $generated]] : [])->with('status', ($created ? "Cuenta del club «{$user->name}» creada" : "El club «{$user->name}» ya tenía cuenta (no se cambia su contraseña)").' y aprobada en este evento.');
    }

    /**
     * Edita la cuenta de un club que creó este organizador: nombre, correo, contraseña (a mano o generada) y logo.
     * Las cuentas que crearon los propios clubes las gestionan ellos.
     */
    public function account(Request $request, Event $event, EventClub $club): RedirectResponse
    {
        $user = $club->user;
        abort_unless($user->isClubAccount() && (int) $user->created_by_user_id === (int) $request->user()->id, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('users', 'name')->where('role', 'club')->ignore($user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', Password::min(8)],
            'generate_password' => ['boolean'],
            'logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo' => ['boolean'],
        ]);

        $user->forceFill(['name' => $data['name'], 'email' => $data['email']]);
        $generated = null;
        if ($request->boolean('generate_password')) {
            $user->forceFill(['password' => $generated = $this->newPassword()]);
        } elseif (filled($data['password'] ?? null)) {
            $user->forceFill(['password' => $data['password']]);
        }
        $user->save();

        if ($request->hasFile('logo')) {
            $user->setLogo($request->file('logo'));
        } elseif ($request->boolean('remove_logo')) {
            $user->deleteLogo();
        }

        return back()
            ->with($generated ? ['generated_password' => ['club' => $user->name, 'email' => $user->email, 'password' => $generated]] : [])
            ->with('status', "Club «{$user->name}» actualizado.");
    }

    /** El organizador asigna el color del club en este evento (o vuelve al automático). */
    public function color(Request $request, Event $event, EventClub $club): RedirectResponse
    {
        $data = $request->validate(['color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'auto_color' => ['boolean']]);
        $club->update(['color' => $request->boolean('auto_color') ? null : (strtolower($data['color'] ?? '') ?: null)]);

        return back()->with('status', "Color de «{$club->user->name}» actualizado.");
    }

    private function newPassword(): string
    {
        return Str::password(12, symbols: false);
    }

    /** Añade al evento un club que ya conoces (creado por ti o registrado en otro evento tuyo); queda aprobado. */
    public function add(Request $request, Event $event): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $club = $this->knownClubs($event, $request->user())->firstWhere('id', (int) $data['user_id']);
        abort_unless($club, 404);

        $event->clubs()->updateOrCreate(['user_id' => $club->id], ['status' => ClubStatus::Approved]);

        return back()->with('status', "El club «{$club->name}» se ha añadido y aprobado en este evento.");
    }

    /** Invita por correo a un club que ya tiene cuenta: no participa hasta que acepte la invitación. */
    public function invite(Request $request, Event $event): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $club = User::where('role', 'club')->where('email', $data['email'])->first();

        if (! $club) {
            return back()->withInput()->withErrors(['invite_email' => 'No hay ninguna cuenta de club con ese correo. Puedes crearla con «Crear cuenta de club».']);
        }

        $existing = $event->clubs()->where('user_id', $club->id)->first();
        if ($existing && $existing->status !== ClubStatus::Rejected) {
            $message = match ($existing->status) {
                ClubStatus::Invited => 'Ese club ya tiene una invitación pendiente (puedes reenviarla).',
                ClubStatus::Approved => 'Ese club ya participa en este evento.',
                default => 'Ese club ya pidió participar: apruébalo en la lista.',
            };

            return back()->withInput()->withErrors(['invite_email' => $message]);
        }

        $event->clubs()->updateOrCreate(['user_id' => $club->id], ['status' => ClubStatus::Invited]);
        $club->notify(new ClubInvitation($event));

        return back()->with('status', "Invitación enviada a «{$club->name}».");
    }

    public function resend(Event $event, EventClub $club): RedirectResponse
    {
        abort_unless($club->status === ClubStatus::Invited, 404);
        $club->user->notify(new ClubInvitation($event));

        return back()->with('status', "Invitación reenviada a «{$club->user->name}».");
    }

    /**
     * Clubes que el organizador ya conoce y aún no están en este evento: cuentas que creó él o registradas en otros eventos suyos.
     *
     * @return \Illuminate\Support\Collection<int,User>
     */
    private function knownClubs(Event $event, User $organizer)
    {
        return User::where('role', 'club')
            ->where(fn ($q) => $q->where('created_by_user_id', $organizer->id)
                ->orWhereHas('clubRegistrations', fn ($r) => $r->whereHas('event', fn ($e) => $e->where('user_id', $organizer->id))->where('status', ClubStatus::Approved->value)))
            ->whereDoesntHave('clubRegistrations', fn ($r) => $r->where('event_id', $event->id)->where('status', '!=', ClubStatus::Rejected->value))
            ->orderBy('name')->get();
    }

    public function update(Request $request, Event $event, EventClub $club): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([ClubStatus::Approved->value, ClubStatus::Rejected->value])]]);

        $club->update($data);

        return back()->with('status', 'Club actualizado.');
    }

    /** Quita de la lista del evento a un club rechazado; su cuenta y sus datos no se tocan. */
    public function destroy(Event $event, EventClub $club): RedirectResponse
    {
        abort_unless($club->event_id === $event->id && $club->status === ClubStatus::Rejected, 404);

        $club->delete();

        return back()->with('status', "Club «{$club->user->name}» eliminado de la lista.");
    }
}
