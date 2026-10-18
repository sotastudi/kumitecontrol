<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\TatamiOperator;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** «Mis controladores»: cuentas de controlador de tatami del organizador, independientes de cada evento. */
class MyOperatorsController extends Controller
{
    private const USERNAME_MESSAGE = ['username.regex' => 'El usuario solo puede llevar letras minúsculas, números, punto, guion y guion bajo.'];

    public function index(Request $request): View
    {
        $organizer = $this->organizer($request);
        $eventIds = $organizer->events()->pluck('id');

        // Las que creó él y las que operan en sus eventos aunque las creara otro.
        $operators = User::where('role', 'tatami')
            ->where(fn ($q) => $q->where('created_by_user_id', $organizer->id)
                ->orWhereHas('tatamiOperations', fn ($o) => $o->whereIn('event_id', $eventIds)))
            ->with(['tatamiOperations' => fn ($o) => $o->whereIn('event_id', $eventIds)->with('event')])
            ->orderBy('name')
            ->get();

        return view('panel.operators.index', [
            'operators' => $operators,
            'organizerId' => $organizer->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organizer = $this->organizer($request);
        $this->normalize($request);
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:6', 'max:100'],
        ], self::USERNAME_MESSAGE + ['username.unique' => 'Ese usuario ya existe']);

        $user = new User([
            'name' => ($data['name'] ?? null) ?: $data['username'],
            'username' => $data['username'],
            'email' => ($data['email'] ?? null) ?: null,
            'password' => $data['password'],
        ]);
        $user->forceFill(['role' => 'tatami', 'created_by_user_id' => $organizer->id])->save();

        return back()->with('status', "Cuenta «{$data['username']}» creada. Asígnala a tus eventos para que pueda puntuar.");
    }

    public function update(Request $request, User $operator): RedirectResponse
    {
        $this->authorizeOwn($request, $operator);
        $this->normalize($request);
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($operator->id)],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($operator->id)],
            'password' => ['nullable', 'string', 'min:6', 'max:100'],
        ], self::USERNAME_MESSAGE + ['username.unique' => 'Ese usuario ya existe']);

        $operator->forceFill([
            'username' => $data['username'],
            'name' => ($data['name'] ?? null) ?: $data['username'],
            'email' => ($data['email'] ?? null) ?: null,
        ]);
        // La contraseña solo cambia si se rellena.
        if (filled($data['password'] ?? null)) {
            $operator->forceFill(['password' => $data['password']]);
        }
        $operator->save();

        return back()->with('status', 'Controlador actualizado.');
    }

    public function destroy(Request $request, User $operator): RedirectResponse
    {
        $organizer = $this->authorizeOwn($request, $operator);

        // No dejar sin controlador, en silencio, a eventos de otro organizador.
        $elsewhere = TatamiOperator::where('user_id', $operator->id)
            ->whereHas('event', fn ($e) => $e->where('user_id', '!=', $organizer->id))
            ->exists();
        if ($elsewhere) {
            return back()->withErrors(['operator' => "«{$operator->username}» tiene asignaciones en eventos de otro organizador y no se puede borrar."]);
        }

        $operator->delete();

        return back()->with('status', "Cuenta «{$operator->username}» eliminada.");
    }

    /** Solo organizadores: ni cuentas de club, ni de tatami, ni superadmin. */
    private function organizer(Request $request): User
    {
        $user = $request->user();
        abort_if($user->isClubAccount() || $user->isTatamiAccount() || $user->isSuperAdmin(), 403);

        return $user;
    }

    /** Además, la cuenta tiene que haberla creado él. */
    private function authorizeOwn(Request $request, User $operator): User
    {
        $organizer = $this->organizer($request);
        abort_unless($operator->isTatamiAccount() && (int) $operator->created_by_user_id === (int) $organizer->id, 403);

        return $organizer;
    }

    private function normalize(Request $request): void
    {
        $request->merge(['username' => mb_strtolower(trim((string) $request->input('username')))]);
    }
}
