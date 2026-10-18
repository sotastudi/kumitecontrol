<?php

namespace Tests\Unit;

use App\Registrations\DemoCatalog;
use Tests\TestCase;

class DemoCatalogTest extends TestCase
{
    public function test_catalog_has_eight_clubs_and_twenty_competitors_per_category(): void
    {
        $c = new DemoCatalog;
        $this->assertCount(8, $c->clubs());
        foreach (['infantil', 'junior-masculina', 'junior-femenina', 'senior-masculina', 'senior-femenina'] as $k) {
            $this->assertCount(20, $c->competitors($k));
            foreach ($c->competitors($k) as $p) {
                $this->assertContains($p['club'], $c->clubs());
            }
        }
    }

    public function test_aliases_resolve_and_logos_and_avatars_exist_with_cyclic_index(): void
    {
        $c = new DemoCatalog;
        $this->assertSame('Dojo Red Ribbon Martial Arts', $c->canonicalClub('dojo red ribbon'));
        $this->assertSame('Club de Artes Marciales Marineford', $c->canonicalClub('Club Marítimo Marineford'));
        $this->assertNull($c->canonicalClub('Club Inventado'));
        foreach ($c->clubs() as $club) {
            $this->assertFileExists($c->clubLogoPath($club));
        }
        $this->assertNull($c->clubLogoPath('Club 9'));
        $this->assertSame($c->avatarPath('juvenil-masculino', 0), $c->avatarPath('juvenil-masculino', 20));
        $this->assertFileExists($c->posterPath());
    }
}
