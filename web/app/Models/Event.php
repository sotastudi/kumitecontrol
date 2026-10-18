<?php

namespace App\Models;

use App\Enums\BronzeRule;
use App\Enums\DisqualifiedPodiumRule;
use App\Enums\DrawCriteria;
use App\Enums\EventStatus;
use App\Enums\PreResultsWithdrawalRule;
use App\Enums\RegistrationStatus;
use App\Enums\UnfoughtWithdrawalRule;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['name', 'discipline', 'description', 'starts_on', 'start_time', 'venue', 'status', 'is_public', 'registration_form_enabled', 'is_test',
    'dq_podium_rule', 'unfought_withdrawal_rule', 'default_bronze_rule', 'withdrawal_before_results_rule', 'draw_criteria', 'registration_opens_at', 'registration_closes_at', 'tatami_count', 'rules_text'])]
class Event extends Model
{
    /** @var array<string,int>|null cache por petición de clubLogoUrl() */
    private ?array $clubLogoIds = null;

    /** @var array<string,string>|null cache por petición de clubColor() */
    private ?array $clubColors = null;

    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'status' => EventStatus::class,
            'is_public' => 'boolean',
            'is_test' => 'boolean',
            'registration_form_enabled' => 'boolean',
            'dq_podium_rule' => DisqualifiedPodiumRule::class,
            'unfought_withdrawal_rule' => UnfoughtWithdrawalRule::class,
            'default_bronze_rule' => BronzeRule::class,
            'withdrawal_before_results_rule' => PreResultsWithdrawalRule::class,
            'draw_criteria' => DrawCriteria::class,
            'registration_opens_at' => 'datetime',
            'registration_closes_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Event $event) {
            $event->slug ??= (Str::slug($event->name) ?: 'evento').'-'.Str::lower(Str::random(6));
            $event->access_code ??= static::newAccessCode();
        });
    }

    /** Código sencillo de 4 caracteres alfanuméricos (A-Z, 0-9), único entre eventos. */
    public static function newAccessCode(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        do {
            $code = '';
            for ($i = 0; $i < 4; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::where('access_code', $code)->exists());

        return $code;
    }

    /** Comprueba un código escrito por una persona (sin distinguir mayúsculas ni espacios/guiones). */
    public function matchesAccessCode(string $input): bool
    {
        $clean = strtoupper(preg_replace('/[\s-]+/', '', $input));

        return $this->access_code !== null && hash_equals($this->access_code, $clean);
    }

    /** Valor que se guarda en la sesión al introducir el código; cambia si el organizador regenera el código. */
    public function accessToken(): string
    {
        return hash('sha256', 'event-access|'.$this->id.'|'.$this->access_code);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function competitors(): HasMany
    {
        return $this->hasMany(Competitor::class);
    }

    public function operators(): HasMany
    {
        return $this->hasMany(TatamiOperator::class);
    }

    public function registrations(): HasManyThrough
    {
        return $this->hasManyThrough(Registration::class, Category::class);
    }

    /** Inscripciones del evento aún sin confirmar ni cancelar. */
    public function pendingRegistrationsCount(): int
    {
        return $this->registrations()->where('registrations.status', RegistrationStatus::Pending->value)->count();
    }

    public function scopeListed(Builder $query): void
    {
        $query->where('is_public', true)->where('status', '!=', EventStatus::Draft->value);
    }

    public function isListed(): bool
    {
        return $this->is_public && $this->status !== EventStatus::Draft;
    }

    public function clubs(): HasMany
    {
        return $this->hasMany(EventClub::class);
    }

    /** URL del logo del club en este evento (club aprobado con logo, por nombre sin distinguir mayúsculas ni acentos), o null. */
    public function clubLogoUrl(?string $club): ?string
    {
        if (blank($club)) {
            return null;
        }

        $this->clubLogoIds ??= $this->clubs()->where('status', 'approved')->with('user')->get()
            ->filter(fn (EventClub $c) => $c->user->hasLogo())
            ->mapWithKeys(fn (EventClub $c) => [Competitor::normalizeName($c->user->name) => $c->id])->all();

        $id = $this->clubLogoIds[Competitor::normalizeName($club)] ?? null;

        return $id ? route('public.clubs.logo', [$this, $id]) : null;
    }

    /**
     * Color (#rrggbb) del club en este evento: el que le asignó el organizador o, si no, uno propuesto de la paleta
     * (los clubes sin color propio reciben, por orden de alta, colores que ningún otro club use aún). Null si el club no está aprobado.
     */
    public function clubColor(?string $club): ?string
    {
        if (blank($club)) {
            return null;
        }

        if ($this->clubColors === null) {
            $clubs = $this->clubs()->where('status', 'approved')->with('user')->orderBy('id')->get();
            $taken = $clubs->map(fn (EventClub $c) => $c->color)->filter()->map(fn ($c) => strtolower($c))->all();
            $free = array_values(array_diff(EventClub::PALETTE, $taken)) ?: EventClub::PALETTE;
            $this->clubColors = [];
            $i = 0;
            foreach ($clubs as $c) {
                $this->clubColors[Competitor::normalizeName($c->user->name)] = $c->color ?: $free[$i++ % count($free)];
            }
        }

        return $this->clubColors[Competitor::normalizeName($club)] ?? null;
    }

    public function acceptsPublicRegistrations(): bool
    {
        return $this->isListed()
            && $this->registration_form_enabled
            && $this->status === EventStatus::RegistrationOpen
            && $this->isInsideRegistrationWindow();
    }

    public const COUNTDOWN_DAYS = 5;

    /**
     * Fecha de inscripción que se muestra al público: la apertura si todavía no ha llegado, o el cierre si las
     * inscripciones están abiertas ahora mismo. Null si no hay nada que anunciar.
     *
     * @return array{type: 'opens'|'closes', at: Carbon, countdown?: bool}|null
     */
    public function registrationDateNote(): ?array
    {
        if ($this->registration_opens_at !== null && now()->lt($this->registration_opens_at)) {
            return ['type' => 'opens', 'at' => $this->registration_opens_at];
        }

        if ($this->acceptsPublicRegistrations() && $this->registration_closes_at !== null) {
            return [
                'type' => 'closes',
                'at' => $this->registration_closes_at,
                // Cuenta atrás cuando faltan menos de 5 días para el cierre.
                'countdown' => now()->diffInSeconds($this->registration_closes_at, false) < self::COUNTDOWN_DAYS * 86400,
            ];
        }

        return null;
    }

    /** Sin fechas, la ventana está siempre abierta; con fechas, solo entre apertura y cierre (ambos incluidos). */
    public function isInsideRegistrationWindow(): bool
    {
        $now = now();

        return ($this->registration_opens_at === null || $now->gte($this->registration_opens_at))
            && ($this->registration_closes_at === null || $now->lte($this->registration_closes_at));
    }

    /** Reglas del campeonato: nunca null (tras create() el atributo puede estar sin refrescar y la BD aplica el DEFAULT). */
    public function dqPodiumRule(): DisqualifiedPodiumRule
    {
        return $this->dq_podium_rule ?? DisqualifiedPodiumRule::ShiftUp;
    }

    public function unfoughtWithdrawalRule(): UnfoughtWithdrawalRule
    {
        return $this->unfought_withdrawal_rule ?? UnfoughtWithdrawalRule::NoPlace;
    }

    /** Número de tatamis del evento (mínimo 1; tras create() el atributo puede estar sin refrescar). */
    public function tatamiCount(): int
    {
        return max(1, (int) ($this->tatami_count ?? 1));
    }

    /** Hora de inicio como «HH:MM» (la base de datos la devuelve con segundos), o null si no se ha indicado. */
    public function startTime(): ?string
    {
        return $this->start_time ? substr((string) $this->start_time, 0, 5) : null;
    }

    /** Fecha del evento con la hora de inicio si la hay: «11 de noviembre de 2026 · 09:30 h». */
    public function startsAtLabel(string $dateFormat = 'j \d\e F \d\e Y'): string
    {
        return $this->starts_on->translatedFormat($dateFormat).($this->startTime() ? ' · '.$this->startTime().' h' : '');
    }

    public function hasRulesPdf(): bool
    {
        return filled($this->rules_pdf_path) && Storage::disk('local')->exists($this->rules_pdf_path);
    }

    public function hasPoster(): bool
    {
        return filled($this->poster_path) && Storage::disk('local')->exists($this->poster_path);
    }

    public function drawCriteria(): DrawCriteria
    {
        return $this->draw_criteria ?? DrawCriteria::LevelWeightClub;
    }

    public function defaultBronzeRule(): BronzeRule
    {
        return $this->default_bronze_rule ?? BronzeRule::BronzeMatch;
    }

    public function preResultsWithdrawalRule(): PreResultsWithdrawalRule
    {
        return $this->withdrawal_before_results_rule ?? PreResultsWithdrawalRule::CancelAndRegenerate;
    }
}
