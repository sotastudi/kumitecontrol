<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Contenido de la página de Ayuda: guías paso a paso por tipo de usuario y preguntas frecuentes.
 * Cada paso puede llevar un enlace (`link` => [texto, nombre de ruta]) para quien aún no tiene sesión y, con sesión iniciada,
 * enlaces a las secciones del panel que menciona, solo si el perfil del usuario tiene acceso a ellas (ver `LINKS`).
 */
final class HelpContent
{
    /**
     * Enlaces del panel por guía y número de paso (base 1): [texto, ruta sin parámetros, perfiles con acceso].
     * Perfiles: organizador, club, tatami. Las páginas de un evento concreto se alcanzan desde «Mis eventos».
     */
    private const LINKS = [
        'organizador' => [
            1 => [['Mi perfil', 'panel.profile.edit', ['organizador', 'club', 'tatami']]],
            2 => [['Nuevo evento', 'panel.events.create', ['organizador']], ['Mis eventos', 'panel.events.index', ['organizador']]],
            3 => [['Mis eventos', 'panel.events.index', ['organizador']]],
            4 => [['Mis eventos', 'panel.events.index', ['organizador']]],
            5 => [['Mis eventos', 'panel.events.index', ['organizador']]],
            6 => [['Mis eventos', 'panel.events.index', ['organizador']]],
            7 => [['Mis eventos', 'panel.events.index', ['organizador']]],
            8 => [['Mis eventos', 'panel.events.index', ['organizador']]],
            9 => [['Mis eventos', 'panel.events.index', ['organizador']]],
            10 => [['Mis eventos', 'panel.events.index', ['organizador']]],
            11 => [['Mis eventos', 'panel.events.index', ['organizador']]],
            12 => [['Mis eventos', 'panel.events.index', ['organizador']]],
        ],
        'club' => [
            2 => [['Mi perfil', 'panel.profile.edit', ['club']]],
            3 => [['Mis competidores', 'panel.athletes.index', ['club']]],
            4 => [['Invitaciones', 'panel.invitations.index', ['club']], ['Portada con los campeonatos', 'home', ['club']]],
            5 => [['Mis eventos', 'panel.events.index', ['club']]],
            6 => [['Mis eventos', 'panel.events.index', ['club']], ['Mis competidores', 'panel.athletes.index', ['club']]],
            7 => [['Mis eventos', 'panel.events.index', ['club']]],
        ],
        'tatami' => [
            1 => [['Mis tatamis', 'panel.events.index', ['tatami']]],
            2 => [['Mis tatamis', 'panel.events.index', ['tatami']]],
            3 => [['Mis tatamis', 'panel.events.index', ['tatami']]],
            4 => [['Mis tatamis', 'panel.events.index', ['tatami']]],
        ],
    ];

    /** Perfil del usuario a efectos de acceso: club, tatami u organizador. */
    public static function profileOf(User $user): string
    {
        return $user->isClubAccount() ? 'club' : ($user->isTatamiAccount() ? 'tatami' : 'organizador');
    }

    /**
     * Guías con, en cada paso, `links` ya resueltos para el usuario: [texto, url] solo de las secciones a las que su perfil accede.
     * Sin sesión iniciada no hay enlaces del panel (quedan los de «Crear cuenta» / «Iniciar sesión»).
     *
     * @return array<string, array<string,mixed>>
     */
    public static function guides(?User $user = null): array
    {
        $guides = self::baseGuides();
        if (! $user) {
            return $guides;
        }

        $profile = self::profileOf($user);
        foreach ($guides as $key => &$guide) {
            foreach ($guide['steps'] as $i => &$step) {
                // Con sesión iniciada los enlaces de registro y acceso (solo para invitados) sobran.
                unset($step['link']);
                $step['links'] = collect(self::LINKS[$key][$i + 1] ?? [])
                    ->filter(fn (array $l) => in_array($profile, $l[2], true) && Route::has($l[1]))
                    ->map(fn (array $l) => [$l[0], route($l[1])])->values()->all();
            }
        }

        return $guides;
    }

    /** @return array<string, array{title: string, intro: string, steps: list<array{title: string, text: string, link?: array{0: string, 1: string}}>}> */
    private static function baseGuides(): array
    {
        return [
            'organizador' => [
                'title' => 'Soy organizador',
                'intro' => 'El organizador crea el campeonato, aprueba a los clubes y a sus inscripciones, genera los cuadros y sigue los resultados.',
                'steps' => [
                    ['title' => 'Crea tu cuenta de organizador', 'text' => 'Regístrate con tu nombre, correo y contraseña e inicia sesión. Después, desde «Mi perfil», puedes cambiar tus datos y tu contraseña.', 'link' => ['Crear cuenta de organizador', 'register']],
                    ['title' => 'Crea el evento', 'text' => 'En «Mis eventos» pulsa «Nuevo evento» e indica nombre, disciplina (por defecto KARATE KYOKUSHIN), fecha y hora, lugar, número de tatamis y, si quieres, fechas de apertura y cierre de inscripciones, póster y normas en PDF. Marca «Público» para que aparezca en la portada. Con «Evento de prueba (Test)» se habilita un bloque para generar categorías e inscripciones ficticias y probar el flujo.'],
                    ['title' => 'Define las reglas del campeonato', 'text' => 'En el formulario del evento eliges qué pasa con el puesto de un descalificado (por defecto suben los siguientes), qué pasa con quien se retira sin haber combatido (por defecto queda sin puesto), la regla del bronce por defecto y los criterios del sorteo.'],
                    ['title' => 'Crea las categorías', 'text' => 'En el evento pulsa «Nueva categoría»: nombre, modalidad (Kumite, Tameshikari, Exhibición, Combate pactado o Katas), sexo, edad, peso, nivel, regla del bronce, tatami y número de jueces. Solo Kumite y Combate pactado generan cuadro; las demás modalidades se gestionan solo con inscripciones y lista. En cada tatami puedes ordenar las categorías arrastrándolas.'],
                    ['title' => 'Da de alta a los clubes', 'text' => 'Los competidores los inscriben los clubes. En «Clubes» del evento puedes: crear la cuenta de un club (queda aprobado, con contraseña propia o generada), añadir un club que ya conoces de otro evento tuyo sin duplicarlo, invitar por correo a un club que ya tiene cuenta (debe aceptar la invitación) y aprobar o rechazar las solicitudes de los clubes que se registran solos.'],
                    ['title' => 'Abre las inscripciones', 'text' => 'Pon el evento en «Inscripciones abiertas». Los clubes aprobados inscriben a sus competidores desde el formulario público; cada inscripción llega como «Pendiente». También puedes registrar competidores tú mismo con «Nueva inscripción» en «Inscripciones».'],
                    ['title' => 'Aprueba las inscripciones', 'text' => 'En «Inscripciones» confirma o cancela cada una, o usa «Confirmar todos» para confirmar las pendientes que coinciden con el filtro actual (categoría, estado o búsqueda). Las categorías de cada inscrito se avisan si no cumplen edad, sexo o peso.'],
                    ['title' => 'Cierra las inscripciones y asigna los números', 'text' => 'Cuando no quede ninguna inscripción pendiente, el aviso del evento te ofrece el botón «Cerrar inscripciones» (también puedes cambiar el estado a «Inscripciones cerradas» al editar el evento). Después pulsa «Asignar números de competidor»: se numera por club y apellidos y cada competidor tiene un único número aunque esté en varias categorías. Si confirmas a alguien después, vuelve a asignar.'],
                    ['title' => 'Genera y publica los cuadros', 'text' => 'Desde cada categoría pulsa «Cuadro» y «Generar cuadro» (se exige tener las inscripciones cerradas y los números asignados). Si el número de inscritos no es potencia de 2, el cuadro empieza con una «Ronda previa» entre los participantes de menor rango (o los primeros del sorteo si eliges azar puro) y el resto queda exento hasta la ronda siguiente: los de la previa hacen un combate más, así que deja 20–30 minutos de descanso antes de la segunda ronda. Puedes revisar el sorteo, intercambiar participantes y regenerar mientras no haya resultados. Con «Publicar» el cuadro se ve en la web pública.'],
                    ['title' => 'Crea los controladores de tatami', 'text' => 'En el evento, en «Controladores de tatami», crea las cuentas (usuario y contraseña) de quienes puntuarán y asígnales sus tatamis. Ellos solo ven y puntúan los tatamis que les asignas. Desde «Mis controladores» (menú del panel) gestionas todas tus cuentas en un solo sitio y las asignas a varios eventos y tatamis.'],
                    ['title' => 'Sigue el campeonato', 'text' => 'Los controladores registran los resultados en cada tatami y los cuadros avanzan solos. Tú puedes corregir resultados, registrar retiradas o descalificaciones, resetear un cuadro, imprimir el cuadro en PDF y exportar inscritos y clasificación en CSV.'],
                    ['title' => 'Comparte los resultados', 'text' => 'Dale al público el código de acceso del evento (en la página del evento): con él ven cuadros, resultados y el estado de los tatamis desde la página pública. Al terminar, pon el evento en «Finalizado».'],
                ],
            ],
            'club' => [
                'title' => 'Soy un club',
                'intro' => 'El club guarda a sus competidores una sola vez y los inscribe en los campeonatos donde el organizador lo ha aprobado. No hay inscripciones a título personal.',
                'steps' => [
                    ['title' => 'Crea la cuenta del club', 'text' => 'Regístrate con el nombre del club, un correo y una contraseña. Ese nombre es el club que constará en las inscripciones. Si el organizador te ha creado la cuenta, entra con el correo y la contraseña que te dio y cámbiala en «Mi perfil».', 'link' => ['Crear cuenta de club', 'register.club']],
                    ['title' => 'Sube el logo (opcional)', 'text' => 'En «Mi perfil» puedes subir el logo del club (JPG, PNG o WebP, hasta 2 MB). Se muestra junto al nombre del club en las vistas de tatami de los campeonatos donde participas.'],
                    ['title' => 'Añade tus competidores', 'text' => 'En «Mis competidores» guarda los datos de cada competidor: nombre, fecha de nacimiento, sexo, peso, nivel y contacto. Si es menor de edad aparece el bloque del representante legal, con su nombre, contacto y la declaración de consentimiento; sin eso un menor no se puede inscribir. Si es mayor de edad ese bloque no aparece.'],
                    ['title' => 'Entra en un campeonato', 'text' => 'Abre el evento desde la portada, pulsa «Inscribirse» y después «Registrar mi club en este campeonato». Tu solicitud queda «Pendiente» hasta que el organizador la apruebe. Si el organizador te invita, verás un aviso en tu cuenta y recibirás un correo: abre «Invitaciones» y pulsa «Confirmar invitación».'],
                    ['title' => 'Consulta tus eventos', 'text' => 'En «Mis eventos» ves todos los campeonatos a los que estás vinculado y su estado: confirmado, pendiente de confirmar por el organizador, invitación pendiente de tu confirmación o rechazado.'],
                    ['title' => 'Inscribe a tus competidores', 'text' => 'Con el club aprobado, en la página de inscripción verás «Agregar desde mis competidores»: pulsa «Agregar a…» en la categoría que corresponda y se inscribe sin rellenar nada. Solo se ofrecen las categorías cuyos requisitos de edad, sexo y peso cumple; pulsa en una categoría apagada para ver el motivo. Para un competidor nuevo puedes usar el formulario de debajo.'],
                    ['title' => 'Espera la confirmación', 'text' => 'Cada inscripción llega al organizador como «Pendiente» y él la confirma. Cuando se cierren las inscripciones se asignan los números de competidor y se generan los cuadros.'],
                ],
            ],
            'tatami' => [
                'title' => 'Soy controlador de tatami',
                'intro' => 'El controlador de tatami puntúa los combates de los tatamis que le asigna el organizador. No necesita crear cuenta: la crea el organizador.',
                'steps' => [
                    ['title' => 'Entra con tu usuario', 'text' => 'El organizador te da un usuario (o correo) y una contraseña. Inicia sesión en «Iniciar sesión». Si solo tienes un tatami asignado, entras directamente en él; si tienes varios, los ves en «Mis tatamis».', 'link' => ['Iniciar sesión', 'login']],
                    ['title' => 'Abre tu tatami', 'text' => 'La pantalla del tatami muestra el combate actual con los dos participantes (AKA en rojo y SHIRO en blanco), su número de competidor y su club, y debajo los combates siguientes en «A continuación». El botón «Ver lista completa del tatami» muestra todos los combates por categoría.'],
                    ['title' => 'Puntúa el combate', 'text' => 'Anota los wazari, el ippon, el abandono, las faltas y el voto de cada juez (AKA, SHIRO o hikiwake). Un ippon o dos wazari dan la victoria al instante y un abandono la da al rival. Si los jueces empatan, elige al ganador con «Gana». Guarda el resultado y el sistema pasa al siguiente combate.'],
                    ['title' => 'Revisa o corrige', 'text' => 'Con «Combate anterior» y «Combate siguiente» puedes volver a combates ya puntuados. Para corregir uno pulsa «Desbloquear», cambia lo necesario y guarda de nuevo.'],
                    ['title' => 'Si se corta la conexión', 'text' => 'Si falla la red, el resultado se guarda en tu dispositivo y se envía solo cuando vuelva la conexión. El icono de la nube arriba indica si todo está guardado en el servidor o si hay resultados pendientes de sincronizar; no cierres la página hasta que vuelva a marcar «todo guardado».'],
                ],
            ],
        ];
    }

    /** @return list<array{q: string, a: string}> */
    public static function faqs(): array
    {
        return [
            ['q' => '¿Puedo inscribirme a título personal?', 'a' => 'No. Las inscripciones las hacen los clubes: primero el club crea su cuenta y se registra en el campeonato; cuando el organizador lo aprueba, el club inscribe a sus competidores.'],
            ['q' => '¿Qué significa que una inscripción esté «Pendiente»?', 'a' => 'Que el club la ha enviado pero el organizador todavía no la ha confirmado. Solo las inscripciones confirmadas entran en el cuadro.'],
            ['q' => '¿Por qué no puedo agregar a un competidor a una categoría?', 'a' => 'Porque no cumple sus requisitos de edad, sexo o peso, o porque es menor y faltan los datos o el consentimiento del representante. Pulsa la categoría apagada para ver el motivo exacto. Si el dato es incorrecto, corrígelo en «Mis competidores».'],
            ['q' => '¿Cómo inscribo a un menor de edad?', 'a' => 'En su ficha de «Mis competidores» indica el nombre y contacto del representante legal y marca su consentimiento. Con eso podrás agregarlo a las categorías. Los datos del representante no se publican.'],
            ['q' => '¿Por qué no puedo asignar los números de competidor?', 'a' => 'Hay que cerrar las inscripciones y no puede quedar ninguna inscripción pendiente: confirma o cancela todas las pendientes (puedes usar «Confirmar todos») y vuelve a pulsar «Asignar números de competidor».'],
            ['q' => '¿Por qué no puedo generar el cuadro de una categoría?', 'a' => 'Necesitas tener las inscripciones cerradas y todos los inscritos confirmados con número de competidor. Además, solo las modalidades Kumite y Combate pactado tienen cuadro, y una categoría con un solo inscrito no genera combates.'],
            ['q' => '¿Cómo añado un club a otro de mis eventos sin crearlo otra vez?', 'a' => 'En «Clubes» del evento usa «Añadir un club que ya conoces». Si el club tiene cuenta pero no es de los tuyos, invítalo con su correo: recibirá un aviso y un email para confirmar.'],
            ['q' => '¿Qué es un evento de prueba (Test)?', 'a' => 'Un evento marcado como «Test» muestra un bloque para generar categorías e inscripciones ficticias (con la nota DEMO) y probar el sorteo, los tatamis y los resultados. Los datos de prueba se pueden borrar desde ese mismo bloque.'],
            ['q' => '¿Cómo veo los cuadros y resultados como público?', 'a' => 'Abre la página del evento e introduce el código de acceso que te haya dado el organizador. Verás los cuadros publicados, los resultados y qué se está combatiendo en cada tatami.'],
            ['q' => '¿Qué pasa si un competidor se retira o es descalificado?', 'a' => 'El organizador lo registra en el cuadro como baja. Sus combates pendientes se dan por ganados a los rivales y el puesto se resuelve según las reglas del evento: por defecto, al descalificado suben los siguientes y quien se retira sin haber combatido queda sin puesto.'],
            ['q' => '¿Puedo reutilizar un controlador en varios eventos?', 'a' => 'Sí. En «Mis controladores» ves todas las cuentas de controlador que has creado, puedes crear nuevas y asignar cada una a varios eventos y a los tatamis que quieras, sin tener que crearla de nuevo en cada evento.'],
            ['q' => '¿Qué hago si me equivoco al puntuar un combate?', 'a' => 'Si eres controlador de tatami, abre el combate, pulsa «Desbloquear», corrige y guarda. Si el error es de un combate que ya avanzó en el cuadro, avisa al organizador, que puede resetear el cuadro de la categoría.'],
            ['q' => '¿Qué pasa si se corta internet mientras puntúo?', 'a' => 'El resultado se guarda en tu dispositivo y se envía automáticamente al volver la conexión. Vigila el icono de la nube: cuando marque «todo guardado», ya está en el servidor.'],
            ['q' => 'He olvidado mi contraseña', 'a' => 'En «Iniciar sesión» pulsa «¿Olvidaste tu contraseña?» e indica tu correo para recibir un enlace de restablecimiento. Las cuentas de controlador de tatami sin correo deben pedirle una contraseña nueva al organizador.'],
            ['q' => '¿Cómo cambio mis datos, mi correo o mi contraseña?', 'a' => 'Pulsa el botón de usuario de la cabecera para abrir «Mi perfil». Allí cambias tu nombre, correo y contraseña (te pide la actual) y, si eres un club, el logo.'],
            ['q' => '¿Para qué sirve el logo del club?', 'a' => 'Se muestra junto al nombre del club en las vistas de tatami, para identificar rápidamente a los participantes. Lo sube el club en «Mi perfil» o el organizador si creó él la cuenta.'],
        ];
    }

    /**
     * Busca en las guías y en las preguntas frecuentes. Todas las palabras deben aparecer (sin distinguir mayúsculas ni acentos).
     *
     * @return list<array{kind: string, label: string, anchor: string, title: string, text: string}>
     */
    public static function search(string $query): array
    {
        $terms = array_values(array_filter(array_map([self::class, 'singular'], preg_split('/\s+/', self::normalize($query)) ?: [])));
        if ($terms === []) {
            return [];
        }

        $matches = fn (string $haystack) => collect($terms)->every(fn (string $term) => str_contains(self::normalize($haystack), $term));
        $results = [];

        foreach (self::guides() as $key => $guide) {
            foreach ($guide['steps'] as $i => $step) {
                if ($matches($guide['title'].' '.$step['title'].' '.$step['text'])) {
                    $results[] = ['kind' => 'guía', 'label' => $guide['title'].' · paso '.($i + 1), 'anchor' => $key.'-'.($i + 1), 'title' => $step['title'], 'text' => $step['text']];
                }
            }
        }
        foreach (self::faqs() as $i => $faq) {
            if ($matches($faq['q'].' '.$faq['a'])) {
                $results[] = ['kind' => 'faq', 'label' => 'Pregunta frecuente', 'anchor' => 'faq-'.($i + 1), 'title' => $faq['q'], 'text' => $faq['a']];
            }
        }

        return $results;
    }

    /** Quita el plural más habitual (menores → menor, números → numero) para que la búsqueda no dependa de él. */
    private static function singular(string $term): string
    {
        return match (true) {
            strlen($term) > 5 && str_ends_with($term, 'es') => substr($term, 0, -2),
            strlen($term) > 3 && str_ends_with($term, 's') => substr($term, 0, -1),
            default => $term,
        };
    }

    private static function normalize(string $text): string
    {
        return Str::of($text)->squish()->ascii()->lower()->toString();
    }
}
