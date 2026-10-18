<?php

use App\Http\Controllers\Auth\ClubRegisterController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Panel\AdminController;
use App\Http\Controllers\Panel\BoutResultController;
use App\Http\Controllers\Panel\BoutScoreController;
use App\Http\Controllers\Panel\BracketController;
use App\Http\Controllers\Panel\BracketPdfController;
use App\Http\Controllers\Panel\CategoryController;
use App\Http\Controllers\Panel\ClubAthleteController;
use App\Http\Controllers\Panel\ClubController;
use App\Http\Controllers\Panel\CompetitorController;
use App\Http\Controllers\Panel\EventController;
use App\Http\Controllers\Panel\ExportController;
use App\Http\Controllers\Panel\InvitationController;
use App\Http\Controllers\Panel\ProfileController;
use App\Http\Controllers\Panel\PublicationController;
use App\Http\Controllers\Panel\RegistrationController;
use App\Http\Controllers\Panel\ResultResetController;
use App\Http\Controllers\Panel\TatamiController;
use App\Http\Controllers\Panel\TestDataController;
use App\Http\Controllers\Panel\TatamiOperatorController;
use App\Http\Controllers\Panel\WithdrawalController;
use App\Http\Controllers\PublicCategoryController;
use App\Http\Controllers\PublicEventController;
use App\Http\Controllers\PublicRegistrationController;
use App\Http\Middleware\EnsureResultsAccess;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('ayuda', HelpController::class)->name('help');
Route::get('eventos/{event:slug}', [PublicEventController::class, 'show'])->name('public.events.show');
Route::get('eventos/{event:slug}/poster', [PublicEventController::class, 'poster'])->name('public.events.poster');
Route::get('eventos/{event:slug}/clubes/{club}/logo', [PublicEventController::class, 'clubLogo'])->scopeBindings()->name('public.clubs.logo');
Route::get('eventos/{event:slug}/normas.pdf', [PublicEventController::class, 'rules'])->name('public.events.rules');
Route::get('eventos/{event:slug}/inscripcion', [PublicRegistrationController::class, 'create'])->name('public.registration.create');
Route::post('eventos/{event:slug}/codigo', [PublicEventController::class, 'access'])
    ->middleware('throttle:10,1')->name('public.events.access');
Route::get('eventos/{event:slug}/categorias/{category}', [PublicCategoryController::class, 'show'])
    ->middleware(EnsureResultsAccess::class)->scopeBindings()->name('public.categories.show');
Route::post('eventos/{event:slug}/inscripcion', [PublicRegistrationController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])->name('public.registration.store');
Route::post('eventos/{event:slug}/inscripcion/plantel', [PublicRegistrationController::class, 'fromRoster'])
    ->middleware(['auth', 'throttle:30,1'])->name('public.registration.roster');
Route::post('eventos/{event:slug}/club', [PublicRegistrationController::class, 'joinClub'])
    ->middleware(['auth', 'throttle:10,1'])->name('public.clubs.store');

Route::middleware('guest')->group(function () {
    Route::get('registro', [RegisterController::class, 'create'])->name('register');
    Route::post('registro', [RegisterController::class, 'store'])->middleware('throttle:10,1');
    Route::get('registro-club', [ClubRegisterController::class, 'create'])->name('register.club');
    Route::post('registro-club', [ClubRegisterController::class, 'store'])->middleware('throttle:10,1');
    Route::get('acceso', [LoginController::class, 'create'])->name('login');
    Route::post('acceso', [LoginController::class, 'store'])->middleware('throttle:6,1');
    Route::get('recuperar-contrasena', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('recuperar-contrasena', [PasswordResetLinkController::class, 'store'])->middleware('throttle:6,1')->name('password.email');
    Route::get('restablecer-contrasena/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('restablecer-contrasena', [NewPasswordController::class, 'store'])->middleware('throttle:6,1')->name('password.update');
});

Route::post('salir', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->prefix('panel')->name('panel.')->group(function () {
    // Token CSRF nuevo para reenviar resultados guardados en local tras estar sin conexión (sesión iniciada).
    Route::get('csrf', fn () => response()->json(['token' => csrf_token()]))->name('csrf');
    Route::get('administracion', [AdminController::class, 'index'])->name('admin.index');
    Route::get('competidores', [ClubAthleteController::class, 'index'])->name('athletes.index');
    Route::post('competidores', [ClubAthleteController::class, 'store'])->name('athletes.store');
    Route::get('competidores/{athlete}/editar', [ClubAthleteController::class, 'edit'])->name('athletes.edit');
    Route::put('competidores/{athlete}', [ClubAthleteController::class, 'update'])->name('athletes.update');
    Route::delete('competidores/{athlete}', [ClubAthleteController::class, 'destroy'])->name('athletes.destroy');
    Route::get('invitaciones', [InvitationController::class, 'index'])->name('invitations.index');
    Route::post('invitaciones/{invitation}/aceptar', [InvitationController::class, 'accept'])->name('invitations.accept');
    Route::post('invitaciones/{invitation}/rechazar', [InvitationController::class, 'decline'])->name('invitations.decline');
    Route::get('perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('perfil', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('perfil/logo', [ProfileController::class, 'logo'])->name('profile.logo.show');
    Route::put('perfil/logo', [ProfileController::class, 'updateLogo'])->name('profile.logo');
    Route::put('perfil/contrasena', [ProfileController::class, 'updatePassword'])->middleware('throttle:6,1')->name('profile.password');
    Route::get('eventos', [EventController::class, 'index'])->name('events.index');
    Route::get('eventos/crear', [EventController::class, 'create'])->name('events.create');
    Route::post('eventos', [EventController::class, 'store'])->name('events.store');

    // Tatami: el propietario o un controlador asignado a ese tatami puntúa el combate actual (policy operate).
    Route::middleware('can:operate,event,tatami')->prefix('eventos/{event}/tatamis/{tatami}')->whereNumber('tatami')->name('tatamis.')->group(function () {
        Route::get('/', [TatamiController::class, 'show'])->name('show');
        Route::put('combates/{bout}/puntuacion', [TatamiController::class, 'score'])->name('score');
    });

    // Todo lo que cuelga de un evento exige ser su propietario; scopeBindings impide mezclar recursos de otro evento.
    Route::middleware('can:manage,event')->scopeBindings()->prefix('eventos/{event}')->group(function () {
        Route::get('/', [EventController::class, 'show'])->name('events.show');
        Route::post('dorsales', [EventController::class, 'assignBibs'])->name('events.bibs');
        Route::post('cerrar-inscripciones', [EventController::class, 'closeRegistrations'])->name('events.close-registrations');
        Route::post('codigo-acceso', [EventController::class, 'regenerateAccessCode'])->name('events.access-code');
        Route::post('controladores-tatami', [TatamiOperatorController::class, 'store'])->name('operators.store');
        Route::delete('controladores-tatami/{operator}', [TatamiOperatorController::class, 'destroy'])->name('operators.destroy');
        Route::put('controladores-tatami/{operator}', [TatamiOperatorController::class, 'update'])->name('operators.update');
        Route::post('pruebas/categorias', [TestDataController::class, 'categories'])->name('test.categories');
        Route::post('pruebas/inscripciones', [TestDataController::class, 'registrations'])->name('test.registrations');
        Route::delete('pruebas/inscripciones', [TestDataController::class, 'clean'])->name('test.clean');
        Route::get('clubes', [ClubController::class, 'index'])->name('clubs.index');
        Route::post('clubes', [ClubController::class, 'store'])->name('clubs.store');
        Route::post('clubes/anadir', [ClubController::class, 'add'])->name('clubs.add');
        Route::post('clubes/invitar', [ClubController::class, 'invite'])->middleware('throttle:20,1')->name('clubs.invite');
        Route::post('clubes/{club}/reenviar', [ClubController::class, 'resend'])->middleware('throttle:20,1')->name('clubs.resend');
        Route::put('clubes/{club}/cuenta', [ClubController::class, 'account'])->name('clubs.account');
        Route::put('clubes/{club}/color', [ClubController::class, 'color'])->name('clubs.color');
        Route::put('clubes/{club}', [ClubController::class, 'update'])->name('clubs.update');
        Route::get('normas.pdf', [EventController::class, 'rules'])->name('events.rules');
        Route::get('poster', [EventController::class, 'poster'])->name('events.poster');
        Route::get('editar', [EventController::class, 'edit'])->name('events.edit');
        Route::put('/', [EventController::class, 'update'])->name('events.update');
        Route::delete('/', [EventController::class, 'destroy'])->name('events.destroy');
        Route::get('categorias/crear', [CategoryController::class, 'create'])->name('categories.create');
        Route::post('categorias', [CategoryController::class, 'store'])->name('categories.store');
        Route::get('categorias/{category}/editar', [CategoryController::class, 'edit'])->name('categories.edit');
        Route::put('categorias/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('categorias/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::post('categorias/{category}/orden', [CategoryController::class, 'move'])->name('categories.move');
        Route::post('orden-tatami/{tatami}', [CategoryController::class, 'reorder'])->whereNumber('tatami')->name('tatamis.reorder');
        Route::get('inscripciones', [RegistrationController::class, 'index'])->name('registrations.index');
        Route::get('inscripciones/crear', [RegistrationController::class, 'create'])->name('registrations.create');
        Route::post('inscripciones', [RegistrationController::class, 'store'])->name('registrations.store');
        Route::post('inscripciones/confirmar-todas', [RegistrationController::class, 'confirmAll'])->name('registrations.confirm-all');
        Route::get('inscripciones/{registration}', [RegistrationController::class, 'show'])->name('registrations.show');
        Route::patch('inscripciones/{registration}', [RegistrationController::class, 'update'])->name('registrations.update');
        Route::get('competidores/{competitor}/editar', [CompetitorController::class, 'edit'])->name('competitors.edit');
        Route::get('competidores/{competitor}/foto', [CompetitorController::class, 'photo'])->name('competitors.photo');
        Route::put('competidores/{competitor}', [CompetitorController::class, 'update'])->name('competitors.update');
        Route::get('exportar/inscritos.csv', [ExportController::class, 'registrations'])->name('exports.registrations');
        Route::get('categorias/{category}/cuadro', [BracketController::class, 'show'])->name('brackets.show');
        Route::get('categorias/{category}/cuadro.pdf', BracketPdfController::class)->name('brackets.pdf');
        Route::post('categorias/{category}/cuadro', [BracketController::class, 'store'])->name('brackets.store');
        Route::post('categorias/{category}/cuadro/intercambio', [BracketController::class, 'swap'])->name('brackets.swap');
        Route::put('categorias/{category}/combates/{bout}/resultado', [BoutResultController::class, 'update'])->name('bouts.result');
        Route::put('categorias/{category}/combates/{bout}/puntuacion', [BoutScoreController::class, 'update'])->name('bouts.score');
        Route::post('categorias/{category}/bajas', [WithdrawalController::class, 'store'])->name('withdrawals.store');
        Route::post('categorias/{category}/combates/{bout}/reiniciar', [ResultResetController::class, 'bout'])->name('bouts.reset');
        Route::post('categorias/{category}/reiniciar', [ResultResetController::class, 'category'])->name('categories.reset');
        Route::patch('categorias/{category}/publicacion', [PublicationController::class, 'toggle'])->name('categories.publication');
        Route::get('exportar/clasificacion.csv', [ExportController::class, 'standings'])->name('exports.standings');
        // Las tareas siguientes añaden aquí categorías, inscripciones, cuadros, exportaciones…
    });
});
