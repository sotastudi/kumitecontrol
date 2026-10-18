<?php

namespace App\Http\Controllers;

use App\Models\Competitor;
use App\Models\Event;
use App\Models\EventClub;
use App\Support\ResultsAccess;
use App\Tournament\PodiumCalculator;
use App\Tournament\TatamiBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicEventController extends Controller
{
    public function show(Request $request, Event $event, PodiumCalculator $podium, TatamiBoard $board, ResultsAccess $access): View
    {
        abort_unless($event->isListed(), 404);

        // Sin el código de acceso (ni ser propietario o controlador) no se ven cuadros, resultados ni tatamis.
        $canSeeResults = $access->allows($request, $event);
        $isOwner = (bool) $request->user()?->can('manage', $event);
        $categories = collect();
        $champions = [];
        $tatamis = [];

        if ($canSeeResults) {
            // El propietario ve también las categorías sin publicar (con enlace al panel); el resto, solo las publicadas.
            $categories = $event->categories()
                ->when(! $isOwner, fn ($q) => $q->where('is_published', true))
                ->withCount([
                    'bouts as total_bouts' => fn ($q) => $q->where('required_participants', 2),
                    'bouts as played_bouts' => fn ($q) => $q->where('required_participants', 2)->whereNotNull('winner_id'),
                ])
                ->orderBy('name')->get();

            // Campeón solo cuando ya se han jugado todos los combates.
            foreach ($categories as $category) {
                if ($category->total_bouts > 0 && $category->played_bouts === $category->total_bouts) {
                    $first = collect($podium->for($category))->firstWhere('place', 1);
                    $champions[$category->id] = $first?->registration->competitor->displayName();
                }
            }

            // Solo el estado actual de cada tatami (categorías publicadas): combate en curso, sin siguientes ni controles.
            $tatamis = $board->for($event, publishedOnly: true);
        }

        return view('public.events.show', [
            'event' => $event,
            'categories' => $categories,
            'champions' => $champions,
            'isOwner' => $isOwner,
            'tatamis' => $tatamis,
            'canSeeResults' => $canSeeResults,
        ]);
    }

    /** El visitante introduce el código que le dio el organizador; queda guardado en su sesión. */
    public function access(Request $request, Event $event, ResultsAccess $access): RedirectResponse
    {
        abort_unless($event->isListed(), 404);
        $data = $request->validate(['code' => ['required', 'string', 'max:30']]);

        if (! $event->matchesAccessCode($data['code'])) {
            return back()->withErrors(['code' => 'Código no válido. Pídeselo al organizador del evento.']);
        }

        $access->grant($request, $event);

        return redirect()->route('public.events.show', $event)->with('status', 'Código correcto: ya puedes ver los resultados.');
    }

    public function poster(Event $event): StreamedResponse
    {
        abort_unless($event->isListed() && $event->hasPoster(), 404);

        return Storage::disk('local')->response($event->poster_path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function rules(Event $event): StreamedResponse
    {
        abort_unless($event->isListed() && $event->hasRulesPdf(), 404);

        return Storage::disk('local')->download($event->rules_pdf_path, $event->rules_pdf_name ?: 'normas.pdf', ['X-Content-Type-Options' => 'nosniff']);
    }

    /** Logo de un club aprobado en el evento: público si el evento está listado; si no, solo para su propietario y controladores de tatami. */
    public function clubLogo(Request $request, Event $event, EventClub $club): StreamedResponse
    {
        abort_unless($club->event_id === $event->id && $club->isApproved(), 404);

        $user = $request->user();
        $allowed = $event->isListed()
            || ($user && ((int) $event->user_id === (int) $user->id || $event->operators()->where('user_id', $user->id)->exists()));
        abort_unless($allowed && $club->user->hasLogo(), 404);

        return Storage::disk('local')->response($club->user->logo_path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => $event->isListed() ? 'public, max-age=3600' : 'private, max-age=600']);
    }

    /** Foto pública de un inscrito (la implementa la tarea 3 del plan). */
    public function competitorPhoto(Event $event, Competitor $competitor): StreamedResponse
    {
        abort(404);
    }
}
