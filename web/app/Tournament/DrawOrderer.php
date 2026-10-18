<?php

namespace App\Tournament;

use App\Enums\DrawCriteria;
use App\Models\Registration;
use App\Support\MartialLevels;
use Illuminate\Support\Collection;

/**
 * Sorteo de la primera ronda según los criterios elegidos (nivel: mayor primero; peso: menor primero; después club),
 * con orden aleatorio entre iguales.
 *
 * Regla que manda: en la primera ronda todos combaten salvo, como mucho, UN participante suelto (con un número impar
 * de inscritos; recibe el pase el de mayor nivel). Los demás se emparejan con su vecino de la lista (nivel y peso
 * parecidos) y se intercambian rivales para evitar el mismo club. Si aun así quedan parejas del mismo club,
 * se mantienen (para no dejar a nadie suelto) y se devuelven como avisos: el organizador debe revisarlas.
 */
final class DrawOrderer
{
    /**
     * @param  Collection<int,Registration>  $registrations  con `competitor` cargado
     * @return array{pairs: list<array{0:int,1:?int}>, warnings: list<string>} parejas en el orden del cuadro; la segunda plaza es null en el pase
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
        if ($criteria->avoidsSameClub()) {
            $comparators[] = fn ($a, $b) => $this->club($a) <=> $this->club($b);
        }

        $shuffled = $registrations->shuffle();
        $sorted = ($comparators ? $shuffled->sort(function ($a, $b) use ($comparators) {
            foreach ($comparators as $cmp) {
                if (($r = $cmp($a, $b)) !== 0) {
                    return $r;
                }
            }

            return 0;
        }) : $shuffled)->values()->all();

        $lone = count($sorted) % 2 === 1 ? array_shift($sorted) : null;
        $fighters = $sorted;
        if ($criteria->avoidsSameClub()) {
            $this->separateClubs($fighters);
        }

        $pairs = [];
        $warnings = [];
        if ($lone) {
            $pairs[] = [$lone->id, null];
        }
        for ($i = 0; $i + 1 < count($fighters); $i += 2) {
            $pairs[] = [$fighters[$i]->id, $fighters[$i + 1]->id];
            if ($criteria->avoidsSameClub() && $this->sameClub($fighters[$i], $fighters[$i + 1])) {
                $warnings[] = 'Primer combate entre participantes del mismo club ('.trim((string) $fighters[$i]->competitor->club).'): '
                    .$fighters[$i]->competitor->displayName().' y '.$fighters[$i + 1]->competitor->displayName().'.';
            }
        }

        return ['pairs' => $this->spread($pairs), 'warnings' => $warnings];
    }

    /** Reparte las parejas (ordenadas de más a menos fuerte) como un cuadro sembrado: las primeras quedan en extremos opuestos. */
    private function spread(array $pairs): array
    {
        $count = count($pairs);
        $size = 1;
        while ($size < $count) {
            $size *= 2;
        }

        $spread = [];
        foreach (BracketSeeding::order($size) as $seed) {
            if ($seed <= $count) {
                $spread[] = $pairs[$seed - 1];
            }
        }

        return $spread;
    }

    private function club(Registration $r): string
    {
        return mb_strtolower(trim((string) $r->competitor->club));
    }

    private function sameClub(Registration $x, Registration $y): bool
    {
        return $this->club($x) !== '' && $this->club($x) === $this->club($y);
    }

    /** @param  list<Registration>  $f  parejas consecutivas (0-1, 2-3…); se modifica en sitio */
    private function separateClubs(array &$f): void
    {
        $count = count($f);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            if (! $this->sameClub($f[$i], $f[$i + 1])) {
                continue;
            }
            // Cambiar al segundo de la pareja por otro participante (de cualquier pareja, el más cercano primero) si ambas parejas quedan sin mismo club.
            $candidates = array_values(array_filter(range(0, $count - 1), fn ($j) => $j !== $i && $j !== $i + 1));
            usort($candidates, fn ($a, $b) => abs($a - $i) <=> abs($b - $i));
            foreach ($candidates as $j) {
                $partner = $j % 2 === 0 ? $j + 1 : $j - 1;
                if (! $this->sameClub($f[$i], $f[$j]) && ! $this->sameClub($f[$i + 1], $f[$partner])) {
                    [$f[$i + 1], $f[$j]] = [$f[$j], $f[$i + 1]];
                    break;
                }
            }
        }
    }
}
