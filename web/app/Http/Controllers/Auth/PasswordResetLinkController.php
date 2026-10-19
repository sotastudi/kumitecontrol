<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // El envío es síncrono: si el correo falla (SMTP caído, esquema inválido...) se registra y se responde igual,
        // porque un 500 solo para cuentas existentes revelaría qué correos están registrados.
        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            Log::error('No se pudo enviar el enlace de recuperación de contraseña', ['exception' => $e]);
        }

        // Mensaje idéntico exista o no la cuenta: no se revela qué correos están registrados.
        return back()->with('status', __('passwords.sent'));
    }
}
