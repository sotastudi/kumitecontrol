<?php

namespace App\Tournament;

use App\Enums\DrawCriteria;
use App\Models\Registration;
use App\Support\MartialLevels;
use Illuminate\Support\Collection;

/**
 * Sorteo del cuadro según los criterios elegidos (nivel: mayor primero; peso: menor primero), con orden aleatorio
 * entre iguales; el club no ordena, solo se usa para separar a los del mismo club al emparejar.
 *
 * Con n inscritos, m = mayor potencia de 2 ≤ n y P = n − m: los 2P peores disputan P combates de ronda previa
 * (emparejados con su vecino de la lista: nivel y peso parecidos) y los n − 2P mejores quedan exentos hasta la
 * ronda siguiente. Los m huecos de esa ronda siguen el orden de un cuadro sembrado: los exentos ocupan las mejores
 * semillas y cada ganador de previa una de las peores. Si n es potencia de 2 no hay previa: todos se emparejan.
 * Se intercambian participantes para evitar el mismo club en el primer combate; si no se puede, se mantiene y se
 * devuelve un aviso para que el organizador lo revise.
 */
final class DrawOrderer
{
    /**
     * @param  Collection<int,Registration>  $registrations  con `competitor` cargado
     * @return array{slots: list<list<int>>, prelim: bool, warnings: list<string>} huecos en orden de cuadro (ver layout())
     */
    public function order(Collection $registrations, DrawCriteria $criteria = DrawCriteria::LevelWeightClub): array
    {
        $ranks = array_flip(MartialLevels::values());

        $comparators = [];
        foreach ($criteria->sortKeys() as $key) {
            $comparators[] = $key === 'level'
                ? fn ($a, $b) => ($ranks[$b->competitor->level] ?? -1) <=> ($ranks[$a->competitor->level] ?? -1)
                : fn ($a, $b) => ($a->competitor->weight ?? INF) <=> ($b->competitor->weight ?? INF);
        }
        // El club no ordena (decidiría los exentos por su nombre): solo separa al emparejar. Entre iguales manda el
        // barajado previo (la ordenación es estable).
        $shuffled = $registrations->shuffle();
        $sorted = ($comparators ? $shuffled->sort(function ($a, $b) use ($comparators) {
            foreach ($comparators as $cmp) {
                if (($r = $cmp($a, $b)) !== 0) {
                    return $r;
                }
            }

            return 0;
        }) : $shuffled)->values();

        $ids = $sorted->pluck('id')->all();
        if ($criteria === DrawCriteria::Random) {
            // Sorteo puro: los primeros extraídos del bombo son los que disputan la previa (el final de la lista).
            $ids = array_reverse($ids);
        }

        $byId = $criteria->avoidsSameClub() ? $sorted->keyBy('id')->all() : [];
        $layout = $this->layout($ids, $byId);

        return $layout + ['warnings' => $this->warnings($layout, $byId)];
    }

    /**
     * Coloca en los huecos del cuadro una lista ya ordenada de mejor a peor. Sin previa: n/2 parejas `[a, b]`.
     * Con previa: m huecos; uno de 1 id es un exento y uno de 2 ids, la pareja de previa cuyo ganador ocupa el hueco.
     * Con `$registrationsById` (competidor cargado) se separan los del mismo club cuando es posible.
     *
     * @param  list<int>  $sortedIds
     * @param  array<int,Registration>  $registrationsById
     * @return array{slots: list<list<int>>, prelim: bool}
     */
    public function layout(array $sortedIds, array $registrationsById = []): array
    {
        $n = count($sortedIds);
        if ($n < 2) {
            return ['slots' => $n ? [[$sortedIds[0]]] : [], 'prelim' => false];
        }

        $m = 1;
        while ($m * 2 <= $n) {
            $m *= 2;
        }
        $p = $n - $m;

        if ($p === 0) {
            $fighters = $sortedIds;
            $this->separateClubs($fighters, $registrationsById);
            $pairs = array_chunk($fighters, 2);

            return ['slots' => array_map(fn ($seed) => $pairs[$seed - 1], BracketSeeding::order(intdiv($m, 2))), 'prelim' => false];
        }

        $exempt = array_slice($sortedIds, 0, $n - 2 * $p);
        $fighters = array_slice($sortedIds, $n - 2 * $p);
        $this->separateClubs($fighters, $registrationsById);
        $pairs = array_chunk($fighters, 2);

        $seeds = BracketSeeding::order($m);
        $slots = array_map(fn ($seed) => $seed <= $m - $p ? [$exempt[$seed - 1]] : $pairs[$seed - ($m - $p) - 1], $seeds);
        $this->separateExemptClubs($slots, $seeds, $registrationsById);

        return ['slots' => $slots, 'prelim' => true];
    }

    /**
     * Avisos de primeros combates del mismo club: parejas (de previa o de 1.ª ronda) y cruces entre dos exentos.
     *
     * @param  array{slots: list<list<int>>, prelim: bool}  $layout
     * @param  array<int,Registration>  $byId
     * @return list<string>
     */
    private function warnings(array $layout, array $byId): array
    {
        $meetings = [];
        foreach ($layout['slots'] as $slot) {
            if (count($slot) === 2) {
                $meetings[] = $slot;
            }
        }
        if ($layout['prelim']) {
            foreach (array_chunk($layout['slots'], 2) as [$a, $b]) {
                if (count($a) === 1 && count($b) === 1) {
                    $meetings[] = [$a[0], $b[0]];
                }
            }
        }

        $warnings = [];
        foreach ($meetings as [$x, $y]) {
            if ($this->sameClub($byId, $x, $y)) {
                $warnings[] = 'Primer combate entre participantes del mismo club ('.trim((string) $byId[$x]->competitor->club).'): '
                    .$byId[$x]->competitor->displayName().' y '.$byId[$y]->competitor->displayName().'.';
            }
        }

        return $warnings;
    }

    private function club(Registration $r): string
    {
        return mb_strtolower(trim((string) $r->competitor->club));
    }

    /** @param  array<int,Registration>  $byId  sin datos (vacío) nunca hay coincidencia */
    private function sameClub(array $byId, int $x, int $y): bool
    {
        if (! isset($byId[$x], $byId[$y])) {
            return false;
        }

        return $this->club($byId[$x]) !== '' && $this->club($byId[$x]) === $this->club($byId[$y]);
    }

    /**
     * @param  list<int>  $f  parejas consecutivas (0-1, 2-3…); se modifica en sitio
     * @param  array<int,Registration>  $byId
     */
    private function separateClubs(array &$f, array $byId): void
    {
        $count = count($f);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            if (! $this->sameClub($byId, $f[$i], $f[$i + 1])) {
                continue;
            }
            // Cambiar al segundo de la pareja por otro participante (de cualquier pareja, el más cercano primero) si ambas parejas quedan sin mismo club.
            $candidates = array_values(array_filter(range(0, $count - 1), fn ($j) => $j !== $i && $j !== $i + 1));
            usort($candidates, fn ($a, $b) => abs($a - $i) <=> abs($b - $i));
            foreach ($candidates as $j) {
                $partner = $j % 2 === 0 ? $j + 1 : $j - 1;
                if (! $this->sameClub($byId, $f[$i], $f[$j]) && ! $this->sameClub($byId, $f[$i + 1], $f[$partner])) {
                    [$f[$i + 1], $f[$j]] = [$f[$j], $f[$i + 1]];
                    break;
                }
            }
        }
    }

    /**
     * Dos exentos del mismo club que se cruzarían en la ronda siguiente (huecos 2j, 2j+1): se cambia el segundo por el
     * exento más cercano en semilla que deje ambos cruces sin mismo club (un ganador de previa aún no tiene club).
     *
     * @param  list<list<int>>  $slots  se modifica en sitio
     * @param  list<int>  $seeds  semilla de cada hueco
     * @param  array<int,Registration>  $byId
     */
    private function separateExemptClubs(array &$slots, array $seeds, array $byId): void
    {
        $count = count($slots);
        $clash = fn (array $x, array $y) => count($x) === 1 && count($y) === 1 && $this->sameClub($byId, $x[0], $y[0]);

        for ($i = 0; $i + 1 < $count; $i += 2) {
            if (! $clash($slots[$i], $slots[$i + 1])) {
                continue;
            }
            $candidates = array_values(array_filter(range(0, $count - 1), fn ($k) => $k !== $i && $k !== $i + 1 && count($slots[$k]) === 1));
            usort($candidates, fn ($a, $b) => abs($seeds[$a] - $seeds[$i + 1]) <=> abs($seeds[$b] - $seeds[$i + 1]));
            foreach ($candidates as $k) {
                $partner = $k % 2 === 0 ? $k + 1 : $k - 1;
                if (! $clash($slots[$i], $slots[$k]) && ! $clash($slots[$i + 1], $slots[$partner])) {
                    [$slots[$i + 1], $slots[$k]] = [$slots[$k], $slots[$i + 1]];
                    break;
                }
            }
        }
    }
}
