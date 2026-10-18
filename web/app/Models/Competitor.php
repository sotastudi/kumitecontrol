<?php

namespace App\Models;

use App\Enums\Sex;
use Carbon\CarbonInterface;
use Database\Factories\CompetitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['first_name', 'last_name', 'club', 'birth_date', 'sex', 'weight', 'level', 'email', 'phone', 'notes', 'guardian_name', 'guardian_contact', 'photo_public'])]
class Competitor extends Model
{
    /** @use HasFactory<CompetitorFactory> */
    use HasFactory;

    /** Campos de datos personales que se copian de un formulario. */
    public const FIELDS = ['first_name', 'last_name', 'club', 'birth_date', 'sex', 'weight', 'level', 'email', 'phone', 'notes', 'guardian_name', 'guardian_contact', 'photo_public'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'sex' => Sex::class,
            'weight' => 'float',
            'guardian_consent_at' => 'datetime',
            'photo_public' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function displayName(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    /** Clave de comparación de nombres: sin acentos, minúsculas y espacios colapsados. */
    public static function normalizeName(string $value): string
    {
        return Str::of($value)->squish()->ascii()->lower()->toString();
    }

    /** Años cumplidos en $date (el día del cumpleaños ya cuenta como cumplido). */
    public function ageOn(CarbonInterface $date): int
    {
        $age = $date->year - $this->birth_date->year;

        if ($date->format('md') < $this->birth_date->format('md')) {
            $age--;
        }

        return $age;
    }

    /** Menor de edad en $date (según `kumitecontrol.adult_age`). */
    public function isMinorOn(CarbonInterface $date): bool
    {
        return $this->ageOn($date) < config('kumitecontrol.adult_age');
    }

    /** Número de competidor para mostrar (`#12`) o cadena vacía si aún no se ha asignado. */
    public function bibLabel(): string
    {
        return $this->bib_number ? '#'.$this->bib_number : '';
    }

    public function hasPhoto(): bool
    {
        return filled($this->photo_path) && Storage::disk('local')->exists($this->photo_path);
    }

    /**
     * URL pública de la foto o null si no debe mostrarse: exige foto, permiso del evento y de la ficha,
     * y que sea adulto el día del evento o tenga consentimiento del tutor. No toca disco.
     */
    public function publicPhotoUrl(Event $event): ?string
    {
        $adult = $this->birth_date !== null && ! $this->isMinorOn($event->starts_on);

        if (! filled($this->photo_path) || ! $this->photo_public || ! $event->show_photos_publicly
            || ! ($adult || $this->guardian_consent_at !== null)) {
            return null;
        }

        return route('public.competitors.photo', [$event, $this]).'?v='.$this->updated_at?->timestamp;
    }

    public function hasGuardianData(): bool
    {
        return filled(trim((string) $this->guardian_name)) && filled(trim((string) $this->guardian_contact));
    }
}
