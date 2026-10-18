<?php

namespace App\Registrations;

use App\Enums\Modality;
use App\Enums\RegistrationStatus;
use App\Enums\Sex;
use App\Models\Category;
use App\Models\Competitor;
use App\Enums\ClubStatus;
use App\Models\Event;
use App\Models\EventClub;
use App\Models\User;
use App\Models\Registration;
use App\Support\MartialLevels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Categorías e inscripciones ficticias para probar un evento marcado como «Test». Los inscritos llevan la nota «DEMO». */
final class TestDataGenerator
{
    public const MARK = 'DEMO';

    /** Dominio de los correos de las cuentas de club ficticias (no existe: nadie puede entrar con ellas). */
    public const CLUB_EMAIL_DOMAIN = '@demo.kumitecontrol.invalid';

    public const MAX_CATEGORIES = 20;

    public const MAX_PARTICIPANTS = 200;

    private const FIRST = ['Aitor', 'Marta', 'Jordi', 'Lucía', 'Pau', 'Nuria', 'Hugo', 'Carla', 'Iván', 'Sara', 'Marc', 'Elena', 'Álex', 'Júlia', 'Dani', 'Irene', 'Raúl', 'Berta', 'Oriol', 'Paula'];

    private const LAST = ['García', 'Martínez', 'López', 'Sánchez', 'Vidal', 'Roca', 'Puig', 'Serra', 'Mora', 'Díez', 'Ferrer', 'Soler', 'Vila', 'Pons', 'Ramos', 'Gil', 'Navarro', 'Costa', 'Prat', 'Ortega'];

    /**
     * Crea las categorías de prueba que falten: infantil mixta, junior por sexo y senior por sexo; con más de cinco se añaden
     * categorías senior por peso. Las que ya existen (mismo nombre) no se duplican.
     *
     * @return int categorías creadas
     */
    public function categories(Event $event, int $count): int
    {
        $created = 0;

        if (! $event->hasPoster()) {
            $event->forceFill(['poster_path' => self::storeCopy((new DemoCatalog)->posterPath(), 'posters')])->save();
        }

        foreach (array_slice($this->categoryTemplates(), 0, max(1, min(self::MAX_CATEGORIES, $count))) as $template) {
            if ($event->categories()->where('name', $template['name'])->exists()) {
                continue;
            }

            $category = $event->categories()->create($template + [
                'modality' => Modality::Kumite->value,
                'bronze_rule' => $event->defaultBronzeRule()->value,
                'tatami' => $event->tatamiCount() > 0 ? ($created % $event->tatamiCount()) + 1 : null,
            ]);
            $category->placeInTatami();
            $created++;
        }

        return $created;
    }

    /**
     * Crea inscritos ficticios en la categoría, repartidos entre el número de clubes indicado.
     *
     * @return int inscritos creados
     */
    public function registrations(Category $category, int $participants, int $clubs, bool $confirmed = true): int
    {
        if ($category->isRegenerationLocked()) {
            throw new RegistrationException('La categoría ya tiene resultados o bajas: no se tocan sus inscripciones.');
        }

        $participants = max(1, min(self::MAX_PARTICIPANTS, $participants));
        $clubNames = $this->clubNames(max(1, $clubs));

        DB::transaction(function () use ($category, $participants, $clubNames, $confirmed) {
            self::ensureClubs($category->event, $clubNames);
            for ($i = 0; $i < $participants; $i++) {
                $competitor = (new Competitor($this->competitorData($category, $clubNames[$i % count($clubNames)])))->forceFill(['event_id' => $category->event_id]);
                $competitor->save();
                Registration::create([
                    'category_id' => $category->id,
                    'competitor_id' => $competitor->id,
                    'status' => $confirmed ? RegistrationStatus::Confirmed : RegistrationStatus::Pending,
                ]);
            }
        });

        return $participants;
    }

    /**
     * Borra los inscritos de prueba del evento salvo los de categorías con resultados o bajas.
     *
     * @return int inscripciones borradas
     */
    public function clean(Event $event): int
    {
        $removed = 0;

        foreach ($event->categories()->get() as $category) {
            if ($category->isRegenerationLocked()) {
                continue;
            }

            $registrations = Registration::where('category_id', $category->id)
                ->whereHas('competitor', fn ($q) => $q->where('notes', self::MARK))->get();
            if ($registrations->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($registrations) {
                Registration::whereIn('id', $registrations->pluck('id'))->delete();
                Competitor::whereIn('id', $registrations->pluck('competitor_id'))->where('notes', self::MARK)->doesntHave('registrations')->delete();
            });
            $removed += $registrations->count();
        }

        self::pruneClubs($event);

        return $removed;
    }

    /**
     * Da de alta, aprobados en el evento, los clubes ficticios (cuenta de club con correo @demo...invalid, creada por el organizador)
     * para que salgan en «Clubes» con su color. Si ya hay un club con ese nombre, solo se registra en el evento.
     *
     * @param  list<string>  $names
     */
    public static function ensureClubs(Event $event, array $names): void
    {
        foreach ($names as $name) {
            $user = User::where('role', 'club')->where('name', $name)->first();
            if (! $user) {
                $user = (new User(['name' => $name, 'email' => Str::slug($name).self::CLUB_EMAIL_DOMAIN, 'password' => Str::random(32)]));
                $user->forceFill(['role' => 'club', 'created_by_user_id' => $event->user_id])->save();
            }
            $logo = (new DemoCatalog)->clubLogoPath($name);
            if ($logo !== null && ! $user->hasLogo()) {
                $user->forceFill(['logo_path' => self::storeCopy($logo, 'club-logos')])->save();
            }
            $event->clubs()->firstOrCreate(['user_id' => $user->id], ['status' => ClubStatus::Approved]);
        }
    }

    /** Quita del evento los clubes ficticios que ya no tienen inscritos, y sus cuentas si no están en ningún otro evento. */
    public static function pruneClubs(Event $event): void
    {
        $used = Competitor::where('event_id', $event->id)->pluck('club')->filter()->map(fn ($c) => Competitor::normalizeName($c))->all();

        $event->clubs()->whereHas('user', fn ($q) => $q->where('email', 'like', '%'.self::CLUB_EMAIL_DOMAIN))->with('user')->get()
            ->each(function (EventClub $club) use ($used) {
                if (in_array(Competitor::normalizeName($club->user->name), $used, true)) {
                    return;
                }
                $user = $club->user;
                $club->delete();
                if (! EventClub::where('user_id', $user->id)->exists()) {
                    $user->deleteLogo();
                    $user->delete();
                }
            });
    }

    /** Copia un fichero del catálogo al disco local (carpeta dada) y devuelve la ruta relativa guardada. */
    private static function storeCopy(string $source, string $directory): string
    {
        return Storage::disk('local')->putFile($directory, new \Illuminate\Http\File($source));
    }

    /** @return list<array<string,mixed>> */
    private function categoryTemplates(): array
    {
        $templates = [
            ['name' => 'Infantil mixta', 'sex' => null, 'min_age' => 8, 'max_age' => 13],
            ['name' => 'Junior masculina', 'sex' => Sex::Male->value, 'min_age' => 14, 'max_age' => 17],
            ['name' => 'Junior femenina', 'sex' => Sex::Female->value, 'min_age' => 14, 'max_age' => 17],
            ['name' => 'Senior masculina', 'sex' => Sex::Male->value, 'min_age' => 18, 'max_age' => null],
            ['name' => 'Senior femenina', 'sex' => Sex::Female->value, 'min_age' => 18, 'max_age' => null],
        ];

        // Más allá de las cinco básicas: senior por tramos de peso, alternando sexo.
        foreach ([60, 70, 80, 90] as $limit) {
            $templates[] = ['name' => "Senior masculina -{$limit} kg", 'sex' => Sex::Male->value, 'min_age' => 18, 'max_age' => null, 'max_weight' => $limit];
            $templates[] = ['name' => 'Senior femenina -'.($limit - 15).' kg', 'sex' => Sex::Female->value, 'min_age' => 18, 'max_age' => null, 'max_weight' => $limit - 15];
        }
        foreach ([60, 70, 80, 90] as $limit) {
            $templates[] = ['name' => "Junior masculina -{$limit} kg", 'sex' => Sex::Male->value, 'min_age' => 14, 'max_age' => 17, 'max_weight' => $limit];
            $templates[] = ['name' => 'Junior femenina -'.($limit - 15).' kg', 'sex' => Sex::Female->value, 'min_age' => 14, 'max_age' => 17, 'max_weight' => $limit - 15];
        }

        return $templates;
    }

    /** @return list<string> */
    private function clubNames(int $count): array
    {
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $names[] = (new DemoCatalog)->clubs()[$i] ?? 'Club '.($i + 1);
        }

        return $names;
    }

    /** @return array<string,mixed> */
    private function competitorData(Category $category, string $club): array
    {
        $age = mt_rand($category->min_age ?? 14, $category->max_age ?? max(($category->min_age ?? 14) + 20, 40));
        $birth = $category->event->starts_on->copy()->subYears($age)->subDays(mt_rand(0, 364));
        $levels = MartialLevels::values();
        $minWeight = $category->min_weight ?? 45;
        $maxWeight = $category->max_weight ?? 90;

        return [
            'first_name' => self::FIRST[mt_rand(0, count(self::FIRST) - 1)],
            'last_name' => self::LAST[mt_rand(0, count(self::LAST) - 1)].' '.self::LAST[mt_rand(0, count(self::LAST) - 1)],
            'birth_date' => $birth->toDateString(),
            'sex' => $category->sex?->value ?? (mt_rand(0, 1) ? Sex::Male->value : Sex::Female->value),
            'level' => $levels[mt_rand(0, count($levels) - 1)],
            'club' => $club,
            'weight' => round($minWeight + mt_rand(0, 100) / 100 * ($maxWeight - $minWeight), 1),
            'notes' => self::MARK,
        ];
    }
}
