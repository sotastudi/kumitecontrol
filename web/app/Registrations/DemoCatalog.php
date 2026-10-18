<?php

namespace App\Registrations;

use App\Models\Competitor;

/** Datos de demostración versionados (database/demo): clubes, nombres del listado Manga, avatares, logos y cartel. */
final class DemoCatalog
{
    /** @var array{clubs:list<string>,aliases:array<string,string>,categories:array<string,list<array<string,string>>>}|null */
    private ?array $data = null;

    /** @var array<string,list<string>> */
    private array $avatars = [];

    /** @return list<string> nombres oficiales de los clubes, en el orden del listado */
    public function clubs(): array
    {
        return $this->data()['clubs'];
    }

    /** Ruta absoluta del logo del club (club/NN.jpg, NN = posición en el listado), o null si no es del catálogo. */
    public function clubLogoPath(string $club): ?string
    {
        $canonical = $this->canonicalClub($club);
        $position = $canonical === null ? false : array_search($canonical, $this->clubs(), true);

        return $position === false ? null : $this->path(sprintf('club/%02d.jpg', $position + 1));
    }

    /** Nombre oficial del club (acepta los alias abreviados de las tablas), o null si no es del catálogo. */
    public function canonicalClub(string $name): ?string
    {
        $wanted = Competitor::normalizeName($name);
        foreach ($this->clubs() as $club) {
            if (Competitor::normalizeName($club) === $wanted) {
                return $club;
            }
        }
        foreach ($this->data()['aliases'] as $alias => $club) {
            if (Competitor::normalizeName($alias) === $wanted) {
                return $club;
            }
        }

        return null;
    }

    /** @return list<array{first_name:string,last_name:string,club:string}> */
    public function competitors(string $categoryKey): array
    {
        return array_map(
            fn (array $p) => ['club' => $this->canonicalClub($p['club']) ?? $p['club']] + $p,
            $this->data()['categories'][$categoryKey] ?? [],
        );
    }

    /** Ruta absoluta del avatar; el índice es cíclico sobre las fotos del grupo. */
    public function avatarPath(string $group, int $index): string
    {
        $files = $this->avatars[$group] ??= (function () use ($group) {
            $found = glob($this->path("avatar/{$group}/*.jpg"));
            sort($found);

            return $found;
        })();

        return $files[$index % count($files)];
    }

    public function posterPath(): string
    {
        return $this->path('cartel.jpg');
    }

    private function path(string $relative): string
    {
        return base_path('database/demo/'.$relative);
    }

    private function data(): array
    {
        return $this->data ??= json_decode(file_get_contents($this->path('catalog.json')), true, 512, JSON_THROW_ON_ERROR);
    }
}
