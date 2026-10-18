<?php

namespace App\Tournament;

use App\Enums\BronzeRule;
use App\Enums\EventStatus;
use App\Enums\RegistrationStatus;
use App\Models\Bout;
use App\Models\Category;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BracketGenerator
{
    public function __construct(private readonly BracketAdvancer $advancer, private readonly DrawOrderer $orderer) {}

    /**
     * @param  Closure(Collection<int,int>): Collection<int,int>|null  $shuffler  sustituye el sorteo por nivel > peso > club (tests)
     *
     * @throws TournamentException
     */
    /**
     * Motivo por el que aún no se puede generar el cuadro desde el panel, o null si está listo: hay que cerrar las
     * inscripciones y asignar los números de competidor a todos los confirmados de la categoría. Con menos de dos
     * confirmados no se comprueba (generate() explica que no hay cuadro que hacer).
     */
    public function readinessProblem(Category $category): ?string
    {
        $confirmed = $category->registrations()->where('status', RegistrationStatus::Confirmed->value)->with('competitor')->get();
        if ($confirmed->count() < 2) {
            return null;
        }

        $event = $category->event;
        if (! in_array($event->status, [EventStatus::RegistrationClosed, EventStatus::InProgress, EventStatus::Finished], true)) {
            return 'Cierra las inscripciones del evento (estado «Inscripciones cerradas» o «En curso») antes de generar el cuadro.';
        }

        $without = $confirmed->filter(fn ($registration) => $registration->competitor->bib_number === null)->count();
        if ($without > 0) {
            return $without === $confirmed->count()
                ? 'Asigna los números de competidor del evento antes de generar el cuadro.'
                : "Hay {$without} inscritos confirmados sin número de competidor: vuelve a asignar los números del evento antes de generar el cuadro.";
        }

        return null;
    }

    public function generate(Category $category, ?Closure $shuffler = null): void
    {
        if (! $category->modalityEnum()->hasBracket()) {
            throw new TournamentException('La modalidad «'.$category->modalityEnum()->label().'» no tiene cuadro: se gestiona solo con las inscripciones.');
        }

        if ($category->isRegenerationLocked()) {
            throw new TournamentException('Hay resultados o bajas registradas: el cuadro ya no se puede regenerar.');
        }

        $registrations = $category->registrations()->with('competitor')
            ->where('status', RegistrationStatus::Confirmed->value)->orderBy('id')->get();
        $ids = $registrations->pluck('id');

        if ($ids->count() === 1) {
            throw new TournamentException('Solo hay un inscrito confirmado: no se genera cuadro. Resuelve la clasificación manualmente según el reglamento.');
        }
        if ($ids->isEmpty()) {
            throw new TournamentException('No hay inscripciones confirmadas para generar el cuadro.');
        }

        if ($shuffler) {
            // Orden dado (tests): con un número impar el primero queda con el pase y el resto se empareja en orden.
            $ordered = $shuffler($ids)->values()->all();
            $pairs = [];
            if (count($ordered) % 2 === 1) {
                $pairs[] = [array_shift($ordered), null];
            }
            foreach (array_chunk($ordered, 2) as $chunk) {
                $pairs[] = [$chunk[0], $chunk[1]];
            }
            $warnings = [];
        } else {
            ['slots' => $slots, 'warnings' => $warnings] = $this->orderer->order($registrations, $category->effectiveDrawCriteria());
            // Provisional: cada exento entra como un pase de 1.ª ronda.
            $pairs = array_map(fn (array $slot) => [$slot[0], $slot[1] ?? null], $slots);
        }

        DB::transaction(function () use ($category, $pairs, $warnings) {
            $category->bouts()->update(['next_bout_id' => null, 'loser_next_bout_id' => null]);
            $category->bouts()->delete();

            $this->build($category, $pairs);

            $category->forceFill(['bracket_generated_at' => now(), 'draw_warnings' => $warnings ?: null])->save();
            $this->advancer->settle($category);
        });
    }

    /**
     * Cuadro de eliminación directa donde en cada ronda todos combaten salvo, como mucho, uno (el último combate de la
     * ronda recibe un pase si el número de participantes es impar). Ronda 1 = las parejas del sorteo; cada ronda siguiente
     * empareja los ganadores de dos combates consecutivos.
     *
     * @param  list<array{0:int,1:?int}>  $pairs
     */
    private function build(Category $category, array $pairs): void
    {
        $counts = [count($pairs)];
        while (end($counts) > 1) {
            $counts[] = intdiv(end($counts) + 1, 2);
        }
        $rounds = count($counts);

        $number = 0;
        /** @var array<int,array<int,Bout>> $grid */
        $grid = [];
        $bronze = null;

        for ($round = 1; $round <= $rounds; $round++) {
            // El combate por el bronce se disputa antes que la final.
            if ($round === $rounds && $rounds >= 2 && $category->bronze_rule === BronzeRule::BronzeMatch) {
                $semiByes = count(array_filter($grid[$round - 1], fn (Bout $b) => $b->isBye()));
                $bronze = $this->create($category, ['round' => $round, 'position' => 1, 'is_bronze' => true, 'required_participants' => 2 - $semiByes], $number);
            }

            for ($p = 1; $p <= $counts[$round - 1]; $p++) {
                if ($round === 1) {
                    [$slotA, $slotB] = $pairs[$p - 1];
                    $attributes = ['round' => 1, 'position' => $p, 'slot_a_id' => $slotA, 'slot_b_id' => $slotB, 'required_participants' => $slotB ? 2 : 1];
                } else {
                    $feeders = min(2, $counts[$round - 2] - 2 * ($p - 1));
                    $attributes = ['round' => $round, 'position' => $p, 'required_participants' => $feeders];
                }

                $grid[$round][$p] = $this->create($category, $attributes, $number);
            }
        }

        foreach ($grid as $round => $bouts) {
            if ($round === $rounds) {
                continue;
            }
            foreach ($bouts as $p => $bout) {
                $bout->next_bout_id = $grid[$round + 1][intdiv($p + 1, 2)]->id;
                $bout->next_slot = $p % 2 === 1 ? 'a' : 'b';
                if ($bronze && $round === $rounds - 1) {
                    $bout->loser_next_bout_id = $bronze->id;
                    $bout->loser_next_slot = $p === 1 ? 'a' : 'b';
                }
                $bout->save();
            }
        }
    }

    private function create(Category $category, array $attributes, int &$number): Bout
    {
        $required = $attributes['required_participants'];

        return $category->bouts()->create($attributes + ['number' => $required === 2 ? ++$number : null]);
    }
}
