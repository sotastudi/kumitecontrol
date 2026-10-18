<?php

namespace App\Tournament;

final class BracketSeeding
{
    /**
     * Semillas (1..$size) en orden de hueco del cuadro: las primeras quedan en extremos opuestos.
     *
     * @return list<int>
     */
    public static function order(int $size): array
    {
        $order = [1];
        for ($n = 1; $n < $size; $n *= 2) {
            $next = [];
            foreach ($order as $i => $seed) {
                $rival = 2 * $n + 1 - $seed;
                // Alterna el lado para que 1 quede arriba y 2 abajo (1,4,3,2…).
                array_push($next, ...($i % 2 === 0 ? [$seed, $rival] : [$rival, $seed]));
            }
            $order = $next;
        }

        return $order;
    }
}
