<?php

namespace App\Models;

use App\Enums\BronzeRule;
use App\Enums\DrawCriteria;
use App\Enums\Modality;
use App\Enums\Sex;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'modality', 'sex', 'min_age', 'max_age', 'min_weight', 'max_weight', 'level', 'bronze_rule', 'show_fourth', 'draw_criteria', 'tatami', 'referees'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sex' => Sex::class,
            'bronze_rule' => BronzeRule::class,
            'draw_criteria' => DrawCriteria::class,
            'draw_warnings' => 'array',
            'min_weight' => 'float',
            'max_weight' => 'float',
            'show_fourth' => 'boolean',
            'is_published' => 'boolean',
            'bracket_generated_at' => 'datetime',
        ];
    }

    public function modalityEnum(): Modality
    {
        return Modality::tryFrom((string) $this->modality) ?? Modality::Kumite;
    }

    /** Criterio propio de la categoría o, si no tiene, el del evento. */
    public function effectiveDrawCriteria(): DrawCriteria
    {
        return $this->draw_criteria ?? $this->event->drawCriteria();
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** Coloca la categoría la última de su tatami (o sin posición si no tiene tatami). Llamar tras cambiar `tatami`. */
    public function placeInTatami(): void
    {
        if ($this->tatami === null) {
            $this->forceFill(['tatami_position' => null])->save();

            return;
        }

        $last = static::where('event_id', $this->event_id)->where('tatami', $this->tatami)->whereKeyNot($this->id)->max('tatami_position');
        $this->forceFill(['tatami_position' => (int) $last + 1])->save();
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    /**
     * Avisos (no bloqueos): el organizador asigna o valida la categoría.
     *
     * @return list<string>
     */
    public function mismatchesFor(Competitor $competitor): array
    {
        $problems = [];
        $age = $competitor->ageOn($this->event->starts_on);

        if ($age < 0) {
            $problems[] = 'Fecha de nacimiento posterior a la fecha del evento';

            return $problems;
        }
        if ($this->min_age !== null && $age < $this->min_age) {
            $problems[] = "Edad {$age}: inferior al mínimo ({$this->min_age})";
        }
        if ($this->max_age !== null && $age > $this->max_age) {
            $problems[] = "Edad {$age}: superior al máximo ({$this->max_age})";
        }
        if ($this->sex !== null && $competitor->sex !== null && $competitor->sex !== $this->sex) {
            $problems[] = 'Sexo distinto al de la categoría';
        }
        if ($competitor->weight !== null) {
            if (($this->min_weight !== null && $competitor->weight < $this->min_weight)
                || ($this->max_weight !== null && $competitor->weight > $this->max_weight)) {
                $problems[] = "Peso {$competitor->weight} kg fuera del rango";
            }
        }

        return $problems;
    }

    /** Criterios que el formulario de inscripción comprueba en el navegador (mismos que mismatchesFor). */
    public function fitRules(): array
    {
        return [
            'min_age' => $this->min_age, 'max_age' => $this->max_age, 'sex' => $this->sex?->value,
            'min_weight' => $this->min_weight, 'max_weight' => $this->max_weight,
        ];
    }

    public function bouts(): HasMany
    {
        return $this->hasMany(Bout::class);
    }

    /** Resultados reales (los pases automáticos no cuentan). */
    public function hasResults(): bool
    {
        return $this->bouts()->where('required_participants', 2)->whereNotNull('winner_id')->exists();
    }

    /** El cuadro no se puede regenerar ni ajustar: hay resultados o bajas registradas (una baja sin resultado, p. ej. de un pase, ya lo bloquea). */
    public function isRegenerationLocked(): bool
    {
        return $this->hasResults() || $this->registrations()->whereNotNull('withdrawal_type')->exists();
    }

    /** El cuadro ya no refleja las inscripciones confirmadas (o la regla del bronce): hay que regenerarlo. */
    public function bracketIsStale(): bool
    {
        $bouts = $this->bouts()->get();
        if ($bouts->isEmpty()) {
            return false;
        }

        // Rondas 1 y 2: con ronda previa, los exentos están directamente en la ronda 2.
        $inBracket = $bouts->where('round', '<=', 2)->where('is_bronze', false)->flatMap(fn (Bout $b) => [$b->slot_a_id, $b->slot_b_id])
            ->filter()->unique()->sort()->values()->all();
        $confirmed = $this->registrations()->where('status', 'confirmed')->orderBy('id')->pluck('id')->all();
        $expectsBronze = $this->bronze_rule === BronzeRule::BronzeMatch && $bouts->max('round') >= 2;

        return $inBracket !== $confirmed || $bouts->contains('is_bronze', true) !== $expectsBronze;
    }
}
