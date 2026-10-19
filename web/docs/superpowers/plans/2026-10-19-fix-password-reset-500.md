# Fix 500 al restaurar contraseña (producción) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que `POST /recuperar-contrasena` nunca devuelva 500 aunque falle el envío del correo, y que la configuración SMTP de producción sea válida.

**Architecture:** `Password::sendResetLink()` envía `ResetPassword` de forma síncrona (`QUEUE_CONNECTION=sync`), así que cualquier excepción del transporte SMTP burbujea como 500. Se captura en `PasswordResetLinkController::store`, se registra en el log y se responde con el mismo mensaje neutro. Aparte se corrige la config de correo en producción (causa probable) y se documenta en `deploy/.env.production.example`.

**Tech Stack:** Laravel (PHP 8.5), Symfony Mailer, PHPUnit.

**Spec:** Sin documento de diseño; el requisito es el reporte del usuario: "al intentar restaurar contraseña da un error 500" (en producción).

## Global Constraints

- Tras cada tarea: subir versión, tag `vX.Y.Z` y push (memoria `release-version-tag-per-task`); commits por tema, solo mis cambios, PHP 8.5.
- Mensaje de respuesta idéntico exista o no la cuenta (`__('passwords.sent')`): no revelar correos registrados.
- No escribir credenciales en el repo; `.env` está ignorado y no se toca desde código.
- Textos de UI/logs en español, como el resto del proyecto.

## Review Focus

- Fallo de transporte SMTP (host caído, credenciales malas, esquema inválido): debe ver el mensaje neutro, no 500, y quedar registrado en el log con `Log::error`.
- Cuenta inexistente vs. existente con SMTP caído: misma respuesta (si no, el 500 revela qué correos existen).
- Que un fallo de envío no deje un token huérfano inservible: es aceptable, el siguiente intento lo sobrescribe (el broker borra el anterior).
- Throttle `6,1` sigue aplicándose tras el cambio.
- `MAIL_SCHEME` duplicado/ inválido (`tls`, `https`) en el `.env` de producción: documentado como `smtps` para el puerto 465.

---

### Task 1: Controlador resiliente al fallo de envío

**Files:**
- Modify: `app/Http/Controllers/Auth/PasswordResetLinkController.php` (método `store`)
- Test: `tests/Feature/AuthTest.php`

**Interfaces:**
- Consumes: `Password::sendResetLink(array $credentials)`; `__('passwords.sent')`.
- Produces: `store(Request): RedirectResponse` sin cambio de firma.

- [ ] **Step 1: Escribir el test que falla** en `AuthTest.php`: `test_reset_link_survives_mail_transport_failure`. Crear un `User`, registrar `Notification::extend`/o bien `Password::shouldReceive('sendResetLink')->andThrow(new \Symfony\Component\Mailer\Exception\TransportException('smtp caído'))`; `Log::spy()`. Hacer `post(route('password.email'), ['email' => $user->email])` y afirmar: `assertRedirect()`, `assertSessionHas('status', __('passwords.sent'))`, y `Log::shouldHaveReceived('error')->once()`.
- [ ] **Step 2: Ejecutar** `php artisan test --filter=test_reset_link_survives_mail_transport_failure`. Esperado: FAIL (excepción `TransportException` sin capturar → 500).
- [ ] **Step 3: Implementar** en `store`: envolver `Password::sendResetLink(...)` en `try { } catch (\Throwable $e) { report($e) o Log::error('No se pudo enviar el enlace de recuperación', ['exception' => $e]); }` (usar `Log::error` para que el test lo verifique) y mantener el `return back()->with('status', ...)` igual. Capturar `\Throwable` y no solo `TransportException` porque un esquema inválido lanza `UnsupportedSchemeException`/`InvalidArgumentException` al construir el transporte.
- [ ] **Step 4: Ejecutar** el test anterior y luego `php artisan test --filter=AuthTest`. Esperado: PASS (incluidos `test_password_reset_flow` y `test_reset_does_not_reveal_whether_the_email_exists`).
- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Auth/PasswordResetLinkController.php tests/Feature/AuthTest.php
git commit -m "fix: la recuperación de contraseña no da 500 si falla el envío del correo"
```

### Task 2: Config de correo de producción y release

**Files:**
- Modify: `deploy/.env.production.example` (bloque `MAIL_*`, ~líneas 47-49)
- Modify: `config/mail.php` solo si hace falta (ver Step 1)

**Interfaces:** ninguna.

- [ ] **Step 1: Verificar la causa real** en el servidor (el log de producción no está en el repo): pedir al usuario `tail -n 60 storage/logs/laravel.log` del hosting tras provocar el error, o `php artisan tinker --execute="Mail::raw('x', fn(\$m)=>\$m->to('jordi@sotastudi.com')->subject('t'));"`. Hipótesis a confirmar: `.env` de producción tiene `MAIL_SCHEME` duplicado (`https` y luego `tls`); Symfony solo admite `smtp`/`smtps` → `UnsupportedSchemeException`. Si el log indica otra causa (credenciales, host, puerto), ajustar el Step 2 a esa causa.
- [ ] **Step 2: Documentar en `deploy/.env.production.example`** un bloque SMTP de ejemplo sin secretos: `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.gimcontrol.com`, `MAIL_PORT=465`, `MAIL_SCHEME=smtps` (una sola vez; comentario: "465 = smtps, 587 = smtp con STARTTLS; `tls`/`https` no son válidos"), `MAIL_USERNAME`/`MAIL_PASSWORD` vacíos.
- [ ] **Step 3: Pedir al usuario** que en el `.env` del servidor deje una sola línea `MAIL_SCHEME=smtps` (o la causa confirmada), ejecute `php artisan config:clear` (o `config:cache` si lo usa) y repita la prueba en producción. Esperado: llega el correo con el enlace de restablecimiento.
- [ ] **Step 4: Suite completa** `php artisan test`. Esperado: todo en verde.
- [ ] **Step 5: Release** según memoria: subir versión (siguiente a 0.0.30 → 0.0.31), commit `chore: versión 0.0.31`, tag `v0.0.31`, push de rama y tag, y desplegar con `deploy/deploy.sh`.

---

**Self-review:** cubre el 500 (Task 1) y la causa de config (Task 2); firmas sin cambios; plan breve respecto al problema.
