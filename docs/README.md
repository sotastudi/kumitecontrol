# KumiteControl

Aplicación web ligera para organizar campeonatos de artes marciales: crear eventos y categorías, inscribir competidores, generar cuadros de eliminación, anotar combates y publicar clasificaciones.

**Estado:** primera versión implementada en `web/`. Las funciones descritas abajo están implementadas, salvo las decisiones pendientes de confirmar: ver [docs/decisiones-pendientes.md](docs/decisiones-pendientes.md) (la regla del bronce está aprobada; las retiradas y descalificaciones están implementadas, con deshacer baja y sanciones pendientes) y [docs/menores.md](docs/menores.md) (inscripción de menores).

## Objetivo y alcance

KumiteControl está pensado para organizadores de campeonatos pequeños o medianos que necesitan sustituir hojas de cálculo y cuadros preparados a mano. Cada organizador gestiona sus propios eventos desde una cuenta; el público consulta la información publicada mediante enlaces del evento.

La primera versión cubre un flujo completo: **crear evento → definir categorías → registrar participantes → cerrar inscripciones → generar cuadros → registrar resultados → publicar podios**. El diseño debe funcionar en ordenador y móvil, con especial atención a las pantallas usadas durante el campeonato.

### Funciones de la primera versión

- **Cuentas de organizador:** registro, acceso, cierre de sesión, recuperación de contraseña y panel con los eventos propios. Cada evento pertenece a un organizador; no se puede acceder a los datos privados de otro.
- **Eventos:** nombre, disciplina, lugar, fecha y hora de inicio (opcional), descripción breve y estados `borrador`, `inscripciones abiertas`, `inscripciones cerradas`, `en curso` y `finalizado`. Página pública con la información que el organizador haya publicado. La portada pública muestra los eventos en bloques con póster, lugar, fecha y botón «Inscribirse» (o «Ver evento» si no hay inscripción abierta).
- **Categorías:** nombre y criterios configurados por el organizador, por ejemplo modalidad, edad, peso, sexo o nivel. En esta versión el organizador asigna o valida la categoría; no se necesita un motor universal para todas las federaciones. La edad, si se usa, se calcula en la fecha del evento. El nivel es un desplegable (10º kyu a 10º dan) y el criterio de sorteo se elige por evento y, si hace falta, por categoría.
- **Competidores e inscripciones:** ficha básica (nombre, apellidos, club/equipo y datos necesarios para la categoría), alta manual por el organizador y formulario público opcional por evento, reservado a clubes aprobados en ese evento. Una inscripción vincula un competidor con una categoría de un evento y pasa por `pendiente`, `confirmada` o `cancelada`. Se impiden duplicados de un mismo competidor en la misma categoría. En la lista de inscripciones el organizador puede **confirmar de una vez todas las pendientes** que coincidan con el filtro actual (categoría, estado y búsqueda); las canceladas no se tocan.
- **Listas:** inscritos por evento/categoría, pendientes, orden de combates y resultados; filtros, impresión desde el navegador y exportación CSV de inscritos y clasificaciones.
- **Cuadros o diagramas:** eliminación directa por categoría, con emparejamiento inicial según los criterios de sorteo, posibilidad de ajustar emparejamientos antes de empezar, rondas, como mucho un pase automático (*bye*) por ronda y vista imprimible. El organizador confirma la generación; si cambian las inscripciones, puede regenerar el cuadro mientras no haya resultados.
- **Combates:** número/orden, ronda, dos participantes y ganador. Se registran resultados uno a uno; el vencedor avanza automáticamente al siguiente combate. Una corrección exige editar el combate afectado y revisar los resultados dependientes antes de continuar.
- **Clasificación y podio:** puestos 1.º, 2.º y 3.º; opción de mostrar también el 4.º. Para distinguir 3.º y 4.º se disputa un combate por el bronce entre quienes perdieron las semifinales. Si se elige un podio de tres puestos, se muestra solo el ganador de ese combate. Esta regla está **aprobada**; por categoría puede elegirse también la alternativa de dos terceros sin combate.
- **Eventos de prueba (Test):** al crear o editar un evento se puede marcar «Evento de prueba (Test)». Su panel muestra entonces un bloque «Datos de prueba» para generar categorías ficticias (por defecto infantil mixta, junior y senior por sexo; el número es configurable), generar inscripciones indicando categoría, participantes y clubes, y borrarlas.
- **Ayuda:** página pública (`/ayuda`, icono «?» de la cabecera y enlace en el pie) con guías paso a paso para organizadores, clubes y controladores de tatami (la guía del perfil con sesión iniciada aparece destacada), preguntas frecuentes y un buscador que no distingue mayúsculas, acentos ni plurales. El contenido está en `web/app/Support/HelpContent.php`: al añadir o cambiar una funcionalidad hay que actualizarlo.
- **Modalidades:** cada categoría es de una modalidad (Kumite por defecto, Tameshikari, Exhibición, Combate pactado o Katas). Solo Kumite y Combate pactado generan cuadro y sorteo; en las demás solo se gestionan inscripciones y lista. El campo «Disciplina» del evento viene rellenado con KARATE KYOKUSHIN.
- **Retiradas y descalificaciones:** con el campeonato en marcha (ya hay resultados), el organizador registra una baja con motivo privado. Los combates pendientes del participante se dan por ganados al rival por incomparecencia. Por defecto, el descalificado sale del podio y del CSV y los siguientes suben de puesto; el retirado que no llegó a combatir queda también sin puesto, y el que sí combatió conserva el suyo. Ambas reglas se pueden cambiar por evento (por ejemplo, dejar el puesto vacante para resolverlo según el reglamento).
- **Reglas del campeonato por evento:** en el formulario del evento se eligen cuatro reglas dudosas (qué pasa con el puesto del descalificado, puesto del retirado que no llegó a combatir, regla del bronce por defecto y si se admite la baja antes de los resultados). Los valores por defecto son los descritos arriba y solo se aplican a los eventos nuevos. Ver [docs/decisiones-pendientes.md](docs/decisiones-pendientes.md).
- **Puntuación del combate:** cada combate admite wazari (2 wazari = 1 ippon), ippon (gana al instante), abandono (gana el otro), el voto de cada juez con tres botones de bandera (blanca = SHIRO, roja = AKA, blanca y roja = hikiwake/empate) y faltas (F: 1 a 4). El ganador se deduce, por orden: abandono, ippon y mayoría de jueces; sin ippon ni abandono hay que marcar la bandera de todos los jueces para poder guardar, y quien gana por ippon (o 2 wazari) se lleva la bandera de todos los jueces. Si los jueces empatan, queda sin ganador para resolverlo a mano con «Gana». Dos participantes que se enfrentan no pueden tener ambos ippon (ni 2 wazari): con ippon en uno se bloquean los del otro hasta rectificar. AKA es el primer participante del combate y SHIRO el segundo. En el recuadro de cada participante del cuadro se ve W:1-2 (wazari), I (ippon) y F:1-4 (faltas, solo para el organizador); AKA (rojo) y SHIRO (blanco) se indican con un cuadrito de color que muestra su nombre al pasar el cursor o tocarlo.
- **Tatamis y jueces:** el evento define cuántos tatamis hay; cada categoría se asigna a un tatami y a una configuración de jueces (2, 3 o 5; máximo 5: 4 esquinas + 1 central). El panel del evento muestra un bloque por tatami con el combate que toca ahora (el siguiente con los dos participantes definidos de sus categorías) y los tres siguientes. El orden de las categorías dentro de un tatami se fija desde el panel del evento: «Reordenar» activa arrastrar y soltar (y flechas subir/bajar para pantallas táctiles) y ese orden decide qué categoría compite antes. No hay calendario ni reparto automático de combates, ni un estado «en curso» explícito.
- **Fechas de inscripción en la portada:** bajo el botón de cada evento se muestra «Inscripciones abiertas hasta …» (con el cierre) o «Las inscripciones abren el …» si aún no han empezado; y cuando faltan menos de 5 días para el cierre, una cuenta atrás en vivo (también en la página del evento) que se pone en rojo el último día.
- **Inscripciones por fechas:** el evento puede fijar inicio y cierre de inscripciones con hora; fuera de esa ventana el formulario público no acepta inscripciones (el alta manual del organizador sí).
- **Números de competidor:** con las inscripciones cerradas, el botón «Asignar números de competidor» numera a los confirmados agrupando por club (orden alfabético, sin club al final) y por apellidos dentro del club. El número aparece en la lista, la ficha, el CSV y el cuadro. Flujo previo a los cuadros: **cerrar inscripciones → el organizador aprueba (confirma o cancela) todas las pendientes → sin ninguna pendiente, asignar los números → generar los cuadros**. No se pueden asignar números mientras quede alguna inscripción pendiente, y el cuadro de una categoría no se puede generar hasta que las inscripciones estén cerradas y todos los confirmados tengan número.
- **Documentos del evento:** póster (se muestra en la portada y en la página del evento), normas en texto y PDF descargable. Los ficheros se guardan en disco privado y se sirven por rutas propias; no hace falta `storage:link`.
- **Foto del participante:** opcional; privada por defecto (el organizador siempre la ve en la ficha). Se muestra públicamente, tras el código de acceso, solo si el evento lo permite, la ficha del competidor lo permite y, en menores, consta el consentimiento del representante.
- **Cuadro en PDF para papel:** desde el cuadro de una categoría, «PDF» (icono de descarga) genera un PDF A4 horizontal para rellenar a mano: en la primera ronda constan los nombres (con club y número de competidor) y en las siguientes solo hay casillas «Nº ____» para escribir el número del competidor. Cabe en una hoja hasta 16 combates en primera ronda; si hay más, se reparte en bloques de 16 (una hoja por bloque) más una hoja final con las rondas finales, el combate por el bronce y el podio, sin cortar nada. Se genera en el servidor con `dompdf/dompdf`.
- **Puntuar sin conexión:** si falla internet al guardar una puntuación, se guarda en el navegador de ese dispositivo y se reenvía sola al volver la conexión (también con «Reintentar ahora»). La barra superior muestra una nube con check cuando todo está guardado en el servidor y un aviso con nube y el número de pendientes cuando no; cada combate muestra además su propio estado. Ver los límites en [docs/decisiones-pendientes.md](docs/decisiones-pendientes.md).
- **Controladores de tatami:** el organizador crea cuentas para puntuar el combate actual de uno o varios tatamis: elige un usuario y una contraseña (el correo es opcional) y marca los tatamis a los que tendrá acceso. Entran con su usuario (o su correo) y solo ven y puntúan sus tatamis: no gestionan eventos ni ven otros tatamis. Sin correo no hay «recuperar contraseña»: quien creó la cuenta puede fijarle una nueva desde el panel del evento. El propietario puede abrir y puntuar cualquier tatami haciendo clic en su bloque del panel del evento. La vista del tatami muestra los dos participantes y la puntuación en tres columnas (una debajo de otra en móvil), con el número de combate y los dorsales en recuadros; se puede volver al combate anterior para revisarlo (queda bloqueado hasta pulsar «Desbloquear» y guardar de nuevo, siempre que el combate siguiente no dependa ya de ese resultado).
- **Código de acceso a los resultados:** cada evento genera al crearse un código de 4 caracteres (A-Z, 0-9) que el organizador reparte a quien quiera. El público necesita ese código para ver cuadros, resultados, campeones y el estado de los tatamis; sin él solo ve la información del evento. El propietario y sus controladores no lo necesitan, y regenerarlo invalida el anterior.
- **Publicación:** página pública de categorías, cuadros y resultados, controlada por el organizador. Un evento puede permanecer privado mientras se prepara.

### Fuera de la primera versión

Pagos de inscripción, marcadores en directo, calendario o reparto automático de combates por tatami, rankings, clasificación por equipos, cabezas de serie, liguillas, doble eliminación, reglas de puntuación específicas de cada federación (solo hay wazari/ippon, jueces y faltas sin sanciones automáticas por faltas), aplicación móvil nativa y permisos para equipos de organizadores. Se podrán añadir tras validar el flujo básico con un campeonato real.

## Reglas de funcionamiento

1. Solo las inscripciones **confirmadas** entran en el cuadro; al generarlo, se guarda una versión estable de los participantes y emparejamientos.
2. Una categoría con un solo inscrito no genera combates. Su clasificación se revisa manualmente según el reglamento; no se le asigna un oro automáticamente. Con dos inscritos se genera una final. Con más inscritos, en cada ronda todos combaten salvo, como mucho, un participante suelto que recibe un pase automático (el de mayor nivel en la primera ronda); no se rellena el cuadro hasta una potencia de dos. Si para no dejar a nadie suelto hay que forzar una regla del sorteo (por ejemplo, enfrentar a dos del mismo club), el panel lo avisa para que se revise.
3. El sorteo se genera con los criterios configurados (por defecto nivel > peso > club, con azar entre iguales) y solo se modifica antes de anotar el primer resultado. Las bajas posteriores y sus efectos se resuelven manualmente por el organizador en la primera versión.
4. No se permite registrar el ganador de un combate hasta conocer ambos participantes, excepto el avance automático por un pase.
5. El sistema calcula la clasificación a partir de resultados validados; al finalizar el evento, una corrección requiere reabrirlo y revisar las clasificaciones afectadas.
6. Las páginas públicas muestran únicamente los datos necesarios: nombre deportivo, club, categoría, cuadro y resultado. Fecha de nacimiento, contacto y datos administrativos quedan fuera de la vista pública.

## Usuarios y acceso

| Perfil | Puede hacer |
| --- | --- |
| Visitante | Ver eventos, cuadros y resultados publicados. No puede inscribir competidores sin una cuenta de club. |
| Club | Cuenta propia (el nombre de la cuenta es el nombre del club). Se registra en cada campeonato y, cuando el organizador lo aprueba, inscribe a sus competidores con el formulario público. No crea ni gestiona eventos. |
| Organizador | Crear y gestionar solo sus eventos, inscripciones, cuadros, combates y publicaciones. |
| Administrador de plataforma | Gestionar incidencias y cuentas. Puede añadirse más adelante si se necesita. |

**No hay inscripciones a título personal.** Los competidores no tienen cuenta: los inscribe su club. Primero se crea la cuenta del club (`/registro-club`); después, en cada campeonato, el club pulsa «Registrar mi club en este campeonato» y queda **pendiente** hasta que el organizador lo **aprueba** (o rechaza) en «Clubes» del evento. El club puede subir su logo en «Mi perfil»; aparece junto al nombre del club en las vistas de tatami (pública y privada). Solo un club aprobado en ese evento ve el formulario de inscripción, y el campo «Club» viene rellenado con el nombre del club y bloqueado (el servidor lo fuerza aunque se manipule el formulario). Las inscripciones siguen entrando como pendientes de confirmar. La cuenta de club tiene «Mis eventos» (los campeonatos a los que está vinculado, confirmados o pendientes) y «Mis competidores». Cada club guarda sus competidores en «Mis competidores» y los agrega a la categoría que corresponda de cada campeonato con un clic, sin volver a rellenar el formulario, si cumplen los requisitos de la categoría. El organizador puede añadir a otro evento suyo un club que ya conoce o invitar por correo a un club con cuenta (el club recibe un email y un aviso en su cuenta para confirmar la invitación). También puede crear la cuenta de un club desde «Clubes» del evento (con contraseña propia o generada automáticamente) y queda aprobado en él; las cuentas que crea las puede editar después, incluido su logo. La alta manual del organizador no depende de esta aprobación. El formulario público admite menores (menos de `ADULT_AGE` años, 18 por defecto, en la fecha del evento) solo si se aportan los datos del representante legal y su consentimiento; esos datos son privados y nunca se publican. Los textos legales siguen pendientes de revisión: ver [docs/menores.md](docs/menores.md).

## Modelo de datos orientativo

| Entidad | Datos principales | Relación |
| --- | --- | --- |
| `users` | nombre, email, contraseña | Organizador propietario de eventos. |
| `events` | `user_id`, nombre, disciplina, fecha, lugar, estado, visibilidad, reglas del campeonato, criterio de sorteo, tatamis, ventana de inscripción, póster, normas (texto y PDF) | Tiene categorías. |
| `categories` | `event_id`, nombre, criterios, tipo de podio, criterio de sorteo propio, tatami, jueces, estado del cuadro | Pertenece a un evento. |
| `competitors` | nombre, apellidos, club, sexo, nivel, número de competidor, foto y datos privados mínimos (incluido el representante legal) | Puede inscribirse en varios eventos. |
| `registrations` | `event_id`, `category_id`, `competitor_id`, estado | Inscripción única por competidor, evento y categoría. |
| `bouts` (combates) | `category_id`, ronda, posición, participante A (AKA) / B (SHIRO), ganador, wazari/ippon, abandono, votos de jueces, amonestaciones | Modela el cuadro y el avance entre rondas. |

Los resultados y asignaciones de combate deben persistirse en base de datos; no deben depender del HTML del diagrama. Las relaciones entre combates (destino del ganador y, cuando proceda, de los perdedores de semifinal) se definirán explícitamente al implementar el generador. Se usarán migraciones, índices y claves foráneas. Las autorizaciones se comprobarán en cada operación sobre recursos de un evento, no solo al mostrar botones.

## Tecnología propuesta

- **Backend:** Laravel 13, PHP 8.4 y Composer. Laravel 13 requiere PHP 8.3 o superior.
- **Base de datos:** MySQL mediante Eloquent y migraciones; comprobar la versión y compatibilidad de MySQL de MAMP PRO y del servidor final antes de iniciar el proyecto.
- **Interfaz:** Blade, Bootstrap 5.3 y JavaScript puntual para formularios y visualización del cuadro. Vite para compilar CSS y JS. Mantener las acciones principales funcionando mediante peticiones normales; evitar una SPA en la primera versión.
- **Autenticación:** componentes nativos de Laravel para sesiones, contraseñas y restablecimiento. La interfaz de acceso se hará con Blade y Bootstrap; registrar usuarios no equivale a tener autorización sobre eventos ajenos.
- **Pruebas:** pruebas de las reglas del cuadro (1, 2, 3 y 5 participantes, pases, final y bronce), avance de ganadores, inscripciones duplicadas y permisos entre organizadores.

Esta combinación favorece el desarrollo en PHP y permite una interfaz familiar sin añadir infraestructura como Redis, WebSockets o colas al principio.

## Preparación local con MAMP PRO

Requisitos: MAMP PRO con Apache, **PHP 8.4 tanto para el sitio como para la terminal**, MySQL compatible, Composer y Node.js con npm. Verificar en la terminal `php -v`, `composer --version` y `node -v`; si la terminal usa otro PHP, seleccionar el binario PHP 8.4 de MAMP antes de ejecutar Composer o Artisan.

El código de la aplicación está en `web/`. Desde esa carpeta:

```bash
cd web
source scripts/dev-env.sh   # PHP 8.4 de MAMP y Node en el PATH (ver nota abajo)
composer install
cp .env.example .env
php artisan key:generate
npm install
```

`scripts/dev-env.sh` tiene fijadas las rutas de esta máquina (MAMP PHP 8.4.17 y Node 26): hay que editarlo en otro equipo.

Crear una base de datos MySQL vacía y ajustar `web/.env` con los valores reales de MAMP PRO (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_PORT`; `APP_URL=https://kumitecontrol.test`). Después:

```bash
php artisan migrate
npm run dev
```

En MAMP PRO, crear el host `kumitecontrol.test` y apuntar el **DocumentRoot a `kumitecontrol/web/public`**. Activar `mod_rewrite` y permitir las reglas `.htaccess` de Laravel. No mover `public/index.php`. La configuración de correo puede usar `MAIL_MAILER=log` hasta configurar SMTP para recuperar contraseñas.

Pruebas y estilo: `php artisan test` y `vendor/bin/pint --test`.

Datos de prueba para el sorteo: `php artisan kumitecontrol:demo-inscritos ID_CATEGORIA --count=12` crea inscritos ficticios (marcados «DEMO») solo con los datos obligatorios; con `--extras` añade club y peso (`--clubs=3` limita los clubes), con `--pending=N` deja N pendientes, con `--seed=1` repite los datos y con `--clean` borra los de prueba. Se niega a actuar en producción (salvo `--force`) y en categorías con resultados o bajas.

## Despliegue básico en Apache
El servidor debe tener PHP 8.4 con las extensiones requeridas por Laravel, MySQL, y permisos de escritura para storage/ y bootstrap/cache/. La URL pública es `https://gimcontrol.com/kumite/`; el DocumentRoot del hosting apunta a `/web/kumite/` (raíz Laravel), y [`web/.htaccess`](web/.htaccess) reescribe a `public/`.

Crear un `.env` propio en el servidor (el deploy no lo sobrescribe). Plantilla: [`web/deploy/.env.production.example`](web/deploy/.env.production.example). Valores clave:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://gimcontrol.com/kumite`
- `DB_HOST=127.0.0.1` por TCP — **no** poner `DB_SOCKET` (eso es solo MAMP local)
- En el panel del hosting, el usuario MySQL debe tener privilegios sobre la base (`kc-demo`); sin eso Laravel no puede migrar ni usar sesiones/caché en BD
- `SESSION_PATH=/kumite`; hasta tener migraciones, `SESSION_DRIVER=file` y `CACHE_STORE=file`
- Tras el primer deploy: migrar (artisan en el servidor puede fallar si `proc_open` está deshabilitado; en ese caso migrar desde local con PHP 8.4 apuntando al MySQL remoto, o importar el esquema en el panel), `php artisan storage:link` (o `ln -sfn ../storage/app/public public/storage`)

Desde `web/`, copiar `deploy/.env.example` a `deploy/.env` (misma cuenta SSH que gimcontrol) y desplegar a `/web/kumite/`:

```bash
./deploy/deploy.sh --dry-run
./deploy/deploy.sh              # npm build + composer --no-dev + rsync
./deploy/deploy.sh --migrate    # + php artisan migrate --force en remoto
```

## Orden de desarrollo
1. Crear proyecto Laravel, entorno MAMP, estilos básicos y acceso de organizadores.
2. Modelos, migraciones y permisos de eventos y categorías.
3. Alta manual y formulario público de inscripciones; listas y confirmación.
4. Generador de cuadros de eliminación, pases y ajustes previos al inicio.
5. Registro de combates, clasificación, podios y páginas públicas.
6. Impresión, CSV, revisión con datos de un evento real y despliegue.
Primer hito verificable: un organizador crea un evento con una categoría, confirma cinco inscripciones, genera un cuadro con los pases necesarios, anota final y combate por el bronce, y publica la clasificación sin exponer datos privados.

## Decisiones pendientes
Ver [docs/decisiones-pendientes.md](docs/decisiones-pendientes.md) (regla del bronce, retiradas y descalificaciones, varias categorías por persona, etc.) y [docs/menores.md](docs/menores.md).

## Referencias
- Smoothcomp y su explicación de cuadros y resultados: referencia de recorrido del participante y visualización pública.
- MartialMatch: referencia para inscripciones, cuadros y publicación de resultados.
- Requisitos y despliegue de Laravel 13 y documentación oficial: referencia para instalación y requisitos del servidor.
Estos sitios sirven como inspiración funcional; KumiteControl comienza con un alcance deliberadamente más reducido.
