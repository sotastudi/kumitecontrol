<?php

namespace App\Http\Controllers\Panel;

use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EventRequest;
use App\Models\Event;
use App\Registrations\BibNumberAssigner;
use App\Registrations\RegistrationException;
use App\Tournament\TatamiBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        // Una cuenta de controlador con un único tatami va directa a él.
        $user = $request->user();
        if ($user->isSuperAdmin()) {
            return redirect()->route('panel.admin.index');
        }
        // Una cuenta de club no gestiona eventos: ve aquellos a los que está vinculado (confirmados, pendientes o invitaciones).
        if ($user->isClubAccount()) {
            return view('panel.events.club-index', [
                'links' => $user->clubRegistrations()->with('event.user')->get()->sortByDesc(fn ($link) => $link->event->starts_on)->values(),
            ]);
        }
        if ($user->isTatamiAccount() && ($operations = $user->tatamiOperations()->get())->count() === 1) {
            return redirect()->route('panel.tatamis.show', [$operations[0]->event_id, $operations[0]->tatami]);
        }

        return view('panel.events.index', [
            'events' => $request->user()->events()->orderByDesc('starts_on')->get(),
            'operations' => $request->user()->tatamiOperations()->with('event')->get()->sortBy(fn ($o) => [$o->event->starts_on, $o->tatami])->values(),
        ]);
    }

    public function create(Request $request): View
    {
        abort_if($request->user()->isTatamiAccount() || $request->user()->isClubAccount() || $request->user()->isSuperAdmin(), 403);

        return view('panel.events.create');
    }

    public function store(EventRequest $request): RedirectResponse
    {
        abort_if($request->user()->isTatamiAccount() || $request->user()->isClubAccount() || $request->user()->isSuperAdmin(), 403);

        $event = $request->user()->events()->create($request->safe()->except(['poster', 'remove_poster', 'rules_pdf', 'remove_rules_pdf']));
        $this->savePoster($request, $event);

        return redirect()->route('panel.events.show', $event)->with('status', 'Evento creado.');
    }

    public function show(Event $event, TatamiBoard $board, BibNumberAssigner $bibs): View
    {
        return view('panel.events.show', [
            'event' => $event->load(['operators.user', 'categories' => fn ($q) => $q->orderBy('name')->withCount([
                'registrations as confirmed_count' => fn ($r) => $r->where('status', RegistrationStatus::Confirmed->value),
            ])]),
            'tatamis' => $board->for($event),
            'bibsProblem' => $bibs->blockingProblem($event),
            'pendingCount' => $event->pendingRegistrationsCount(),
        ]);
    }

    public function edit(Event $event): View
    {
        return view('panel.events.edit', ['event' => $event]);
    }

    public function update(EventRequest $request, Event $event): RedirectResponse
    {
        $event->update($request->safe()->except(['poster', 'remove_poster', 'rules_pdf', 'remove_rules_pdf']));
        $this->savePoster($request, $event);

        return redirect()->route('panel.events.show', $event)->with('status', 'Evento actualizado.');
    }

    public function destroy(Event $event): RedirectResponse
    {
        $this->deletePoster($event);
        $this->deleteRulesPdf($event);
        $event->competitors()->whereNotNull('photo_path')->pluck('photo_path')->each(fn ($path) => Storage::disk('local')->delete($path));
        $event->delete();

        return redirect()->route('panel.events.index')->with('status', 'Evento eliminado.');
    }

    /** El póster se guarda en el disco privado y se sirve por una ruta propia (solo si el evento es público). */
    public function poster(Event $event): StreamedResponse
    {
        abort_unless($event->hasPoster(), 404);

        return Storage::disk('local')->response($event->poster_path, null, ['X-Content-Type-Options' => 'nosniff']);
    }

    private function savePoster(EventRequest $request, Event $event): void
    {
        if ($request->hasFile('poster')) {
            $this->deletePoster($event);
            $event->forceFill(['poster_path' => $request->file('poster')->store('posters', 'local')])->save();
        } elseif ($request->boolean('remove_poster')) {
            $this->deletePoster($event);
            $event->forceFill(['poster_path' => null])->save();
        }

        if ($request->hasFile('rules_pdf')) {
            $this->deleteRulesPdf($event);
            $file = $request->file('rules_pdf');
            $event->forceFill([
                'rules_pdf_path' => $file->store('rules', 'local'),
                'rules_pdf_name' => Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 100, '').'.pdf',
            ])->save();
        } elseif ($request->boolean('remove_rules_pdf')) {
            $this->deleteRulesPdf($event);
            $event->forceFill(['rules_pdf_path' => null, 'rules_pdf_name' => null])->save();
        }
    }

    /** Genera un código nuevo: el anterior deja de valer y las sesiones que lo introdujeron dejan de ver los resultados. */
    public function regenerateAccessCode(Event $event): RedirectResponse
    {
        $event->forceFill(['access_code' => Event::newAccessCode()])->save();

        return back()->with('status', 'Código de acceso regenerado: el anterior ya no funciona.');
    }

    public function closeRegistrations(Event $event): RedirectResponse
    {
        if ($event->status !== EventStatus::RegistrationOpen) {
            return back()->withErrors(['error' => 'Solo se pueden cerrar las inscripciones de un evento con inscripciones abiertas.']);
        }

        $pending = $event->pendingRegistrationsCount();
        if ($pending > 0) {
            return back()->withErrors(['error' => 'Quedan '.$pending.($pending === 1 ? ' inscripción pendiente' : ' inscripciones pendientes').': confírmalas o cancélalas antes de cerrar las inscripciones.']);
        }

        $event->update(['status' => EventStatus::RegistrationClosed]);

        return back()->with('status', 'Inscripciones cerradas.');
    }

    public function assignBibs(Event $event, BibNumberAssigner $bibs): RedirectResponse
    {
        try {
            $count = $bibs->assign($event);
        } catch (RegistrationException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return back()->with('status', "Números de competidor asignados a {$count} participantes (agrupados por club).");
    }

    public function rules(Event $event): StreamedResponse
    {
        abort_unless($event->hasRulesPdf(), 404);

        return Storage::disk('local')->download($event->rules_pdf_path, $event->rules_pdf_name ?: 'normas.pdf', ['X-Content-Type-Options' => 'nosniff']);
    }

    private function deleteRulesPdf(Event $event): void
    {
        if (filled($event->rules_pdf_path)) {
            Storage::disk('local')->delete($event->rules_pdf_path);
        }
    }

    private function deletePoster(Event $event): void
    {
        if (filled($event->poster_path)) {
            Storage::disk('local')->delete($event->poster_path);
        }
    }
}
