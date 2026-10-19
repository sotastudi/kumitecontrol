<?php

namespace Tests\Feature;

use App\Models\Bout;
use App\Models\Category;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Red de seguridad: recorre TODAS las rutas del panel. Si alguien añade una ruta
 * sin auth o sin comprobar la propiedad del evento, o sin fixture aquí, este test falla.
 */
class OrganizerIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_panel_route_requires_authentication(): void
    {
        $routes = $this->panelRoutes();
        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertContains('auth', $route->gatherMiddleware(), $route->uri().' sin auth');
        }
    }

    public function test_every_event_scoped_route_rejects_other_organizers(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $params = $this->fixtureParameters($owner);
        $checked = 0;

        foreach ($this->panelRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'panel/eventos/{event}')) {
                $this->assertContains(
                    $route->methods()[0].' '.$route->uri(),
                    ['GET panel/csrf', 'GET panel/administracion', 'GET panel/eventos', 'GET panel/eventos/crear', 'POST panel/eventos',
                        'GET panel/perfil', 'PUT panel/perfil', 'PUT panel/perfil/contrasena',
                        'GET panel/perfil/logo', 'PUT panel/perfil/logo',
                        'GET panel/competidores', 'POST panel/competidores', 'GET panel/competidores/{athlete}/editar', 'PUT panel/competidores/{athlete}', 'DELETE panel/competidores/{athlete}',
                        'GET panel/invitaciones', 'POST panel/invitaciones/{invitation}/aceptar', 'POST panel/invitaciones/{invitation}/rechazar',
                        'GET panel/controladores', 'POST panel/controladores', 'PUT panel/controladores/{operator}', 'DELETE panel/controladores/{operator}', 'PUT panel/controladores/{operator}/eventos/{event}'],
                    'Ruta del panel fuera de panel/eventos/{event} sin comprobación de propiedad: '.$route->uri(),
                );

                continue;
            }

            $middleware = $route->gatherMiddleware();
            $this->assertTrue(
                in_array('can:manage,event', $middleware, true) || in_array('can:operate,event,tatami', $middleware, true),
                $route->uri().' sin policy',
            );

            $uri = $route->uri();
            foreach ($route->parameterNames() as $name) {
                $this->assertArrayHasKey($name, $params, 'Falta fixture para {'.$name.'} en '.$route->uri());
                $uri = str_replace('{'.$name.'}', (string) $params[$name], $uri);
            }

            $this->actingAs($intruder)->call($route->methods()[0], '/'.$uri)->assertForbidden();
            $checked++;
        }

        $this->assertGreaterThan(0, $checked);
    }

    /** @return array<string,int> id por nombre de parámetro de ruta, todo perteneciente a $owner */
    private function fixtureParameters(User $owner): array
    {
        $event = Event::factory()->for($owner)->create();

        $category = Category::factory()->for($event)->create();

        $registration = Registration::factory()->forCategory($category)->create();

        $bout = Bout::create(['category_id' => $category->id, 'round' => 1, 'position' => 1]);

        $club = \App\Models\EventClub::create(['event_id' => $event->id, 'user_id' => User::factory()->create()->id]);

        $operator = $event->operators()->create(['user_id' => User::factory()->create()->id, 'tatami' => 1]);

        return [
            'event' => $event->id, 'category' => $category->id, 'bout' => $bout->id, 'tatami' => 1, 'operator' => $operator->id, 'club' => $club->id,
            'registration' => $registration->id, 'competitor' => $registration->competitor_id,
        ];
    }

    /** @return list<LaravelRoute> */
    private function panelRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (LaravelRoute $r) => str_starts_with($r->uri(), 'panel'),
        ));
    }
}
