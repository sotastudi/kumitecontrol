<?php

namespace App\Registrations;

use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use App\Models\Competitor;
use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Números de competidor: una vez cerradas las inscripciones, se agrupa por club (orden alfabético; sin club al final),
 * dentro del club por apellidos y nombre, y se numera correlativamente desde 1. Un competidor tiene un único número
 * aunque esté en varias categorías; solo reciben número los que tienen alguna inscripción confirmada.
 * Volver a asignar renumera todo desde cero.
 */
final class BibNumberAssigner
{
    /** Motivo por el que aún no se pueden asignar los números (inscripciones sin cerrar o con pendientes de aprobar), o null. */
    public function blockingProblem(Event $event): ?string
    {
        if (! in_array($event->status, [EventStatus::RegistrationClosed, EventStatus::InProgress], true)) {
            return 'Cierra las inscripciones (estado «Inscripciones cerradas» o «En curso») antes de asignar los números de competidor.';
        }

        $pending = $event->pendingRegistrationsCount();
        if ($pending > 0) {
            return 'Quedan '.$pending.($pending === 1 ? ' inscripción pendiente' : ' inscripciones pendientes').': confírmalas o cancélalas antes de asignar los números de competidor.';
        }

        return null;
    }

    /** @throws RegistrationException */
    public function assign(Event $event): int
    {
        if ($problem = $this->blockingProblem($event)) {
            throw new RegistrationException($problem);
        }

        $competitors = Competitor::where('event_id', $event->id)
            ->whereHas('registrations', fn ($q) => $q->where('status', RegistrationStatus::Confirmed->value))
            ->get();

        $key = fn (?string $text) => Str::ascii(mb_strtolower(trim((string) $text)));
        $sorted = $competitors->sort(function (Competitor $a, Competitor $b) use ($key) {
            $clubA = $key($a->club);
            $clubB = $key($b->club);
            // Sin club al final.
            if (($clubA === '') !== ($clubB === '')) {
                return $clubA === '' ? 1 : -1;
            }

            return [$clubA, $key($a->last_name), $key($a->first_name), $a->id] <=> [$clubB, $key($b->last_name), $key($b->first_name), $b->id];
        })->values();

        DB::transaction(function () use ($event, $sorted) {
            Competitor::where('event_id', $event->id)->update(['bib_number' => null]);
            foreach ($sorted as $i => $competitor) {
                Competitor::whereKey($competitor->id)->update(['bib_number' => $i + 1]);
            }
        });

        return $sorted->count();
    }
}
