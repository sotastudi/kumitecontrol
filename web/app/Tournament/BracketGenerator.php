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
            // Orden dado (tests) = mérito de mejor a peor: se colocan en los huecos igual que el sorteo.
            ['slots' => $slots, 'prelim' => $prelim] = $this->orderer->layout($shuffler($ids)->values()->all());
            $warnings = [];
        } else {
            ['slots' => $slots, 'prelim' => $prelim, 'warnings' => $warnings] = $this->orderer->order($registrations, $category->effectiveDrawCriteria());
        }

        DB::transaction(function () use ($category, $slots, $prelim, $warnings) {
            $category->bouts()->update(['next_bout_id' => null, 'loser_next_bout_id' => null]);
            $category->bouts()->delete();

            $this->build($category, $slots, $prelim);

            $category->forceFill(['bracket_generated_at' => now(), 'draw_warnings' => $warnings ?: null])->save();
            $this->advancer->settle($category);
        });
    }

    /**
     * Cuadro de eliminación directa. Sin previa, la ronda 1 son las parejas del sorteo. Con previa, la ronda 1 son sus
     * combates (`is_prelim`, numerados primero) y la ronda 2 tiene m/2 combates cuyos huecos ocupan directamente los
     * exentos o esperan al ganador de una previa. Cada ronda siguiente empareja los ganadores de dos combates consecutivos.
     *
     * @param  list<list<int>>  $slots  ver DrawOrderer::layout()
     */
    private function build(Category $category, array $slots, bool $prelim): void
    {
        $number = 0;
        /** @var array<int,array<int,Bout>> $grid */
        $grid = [];
        $bronze = null;

        // Primera ronda: la previa (las parejas en orden de hueco) o, sin previa, todas las parejas.
        $first = $prelim ? array_values(array_filter($slots, fn (array $slot) => count($slot) === 2)) : $slots;
        foreach ($first as $i => [$slotA, $slotB]) {
            $grid[1][$i + 1] = $this->create($category, [
                'round' => 1, 'position' => $i + 1, 'is_prelim' => $prelim,
                'slot_a_id' => $slotA, 'slot_b_id' => $slotB, 'required_participants' => 2,
            ], $number);
        }

        $count = $prelim ? intdiv(count($slots), 2) : intdiv(count($first), 2);
        $rounds = 1;
        $counts = [];
        while ($count >= 1) {
            $rounds++;
            $counts[$rounds] = $count;
            $count = intdiv($count, 2);
        }

        for ($round = 2; $round <= $rounds; $round++) {
            // El combate por el bronce se disputa antes que la final; sus participantes son los perdedores de la ronda anterior.
            if ($round === $rounds && $category->bronze_rule === BronzeRule::BronzeMatch) {
                $bronze = $this->create($category, ['round' => $round, 'position' => 1, 'is_bronze' => true, 'required_participants' => min(2, count($grid[$round - 1]))], $number);
            }

            for ($p = 1; $p <= $counts[$round]; $p++) {
                $attributes = ['round' => $round, 'position' => $p, 'required_participants' => 2];
                if ($prelim && $round === 2) {
                    $attributes['slot_a_id'] = count($slots[2 * $p - 2]) === 1 ? $slots[2 * $p - 2][0] : null;
                    $attributes['slot_b_id'] = count($slots[2 * $p - 1]) === 1 ? $slots[2 * $p - 1][0] : null;
                }
                $grid[$round][$p] = $this->create($category, $attributes, $number);
            }
        }

        // Enlaces: cada previa con el hueco que alimenta (impar ⇒ a, par ⇒ b); el resto, por posición.
        $feeds = [];
        if ($prelim) {
            $k = 0;
            foreach ($slots as $i => $slot) {
                if (count($slot) === 2) {
                    $feeds[++$k] = [$grid[2][intdiv($i, 2) + 1], $i % 2 === 0 ? 'a' : 'b'];
                }
            }
        }

        foreach ($grid as $round => $bouts) {
            if ($round === $rounds) {
                continue;
            }
            foreach ($bouts as $p => $bout) {
                [$target, $slot] = $prelim && $round === 1 ? $feeds[$p] : [$grid[$round + 1][intdiv($p + 1, 2)], $p % 2 === 1 ? 'a' : 'b'];
                $bout->next_bout_id = $target->id;
                $bout->next_slot = $slot;
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
