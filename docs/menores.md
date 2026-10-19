# Menores de edad

## Qué hace ahora el sistema

- **Mayoría de edad configurable:** `ADULT_AGE` (18 por defecto, `config/kumitecontrol.php`). Es menor quien tiene menos años en la **fecha del evento** (el día del cumpleaños ya cuenta como cumplido).
- **Formulario público:** admite menores **solo si** se aportan el nombre y el contacto del representante legal y se marca la casilla de consentimiento («Declaro ser el padre, madre o representante legal del menor y consiento su inscripción y el tratamiento de sus datos para este evento.»). Si falta algún dato, la inscripción se rechaza y no se guarda nada. Si el competidor es adulto en la fecha del evento, los datos de representante enviados se descartan.
- **Qué se guarda:** `guardian_name`, `guardian_contact` y `guardian_consent_at` (fecha y hora en que se marcó la casilla), en el competidor. Si el competidor ya existía en el evento, sus datos no se sobrescriben.
- **Alta manual del organizador:** admite menores sin exigir datos del representante (el organizador asume la responsabilidad de haber obtenido el consentimiento fuera de la aplicación); puede registrar el nombre, el contacto y, al dar de alta, marcar «El representante ha dado su consentimiento». Los datos del representante pueden editarse después.
- **Panel:** la lista de inscripciones marca con «Menor» a los menores en la fecha del evento y avisa con «Menor sin datos de representante» cuando faltan. El CSV de inscritos incluye las columnas `Menor`, `Representante`, `Contacto representante` y `Consentimiento`.
- **Privacidad:** los datos del representante son **privados**: solo los ve el organizador del evento (panel y CSV). Las páginas públicas muestran únicamente nombre, club, categoría, cuadro y resultado; tampoco publican fecha de nacimiento ni datos de contacto.

- **Foto:** la foto opcional del participante es privada por defecto: solo la ve el organizador. Se muestra públicamente (miniaturas en tatamis, cuadros y páginas públicas, siempre tras el código de acceso del evento) solo si se cumplen **todas** estas condiciones: el evento lo permite («Mostrar públicamente las fotos de los inscritos», desactivado por defecto), la ficha del competidor lo permite («Mostrar la foto públicamente», activado por defecto; la ficha manda sobre el evento) y, si es **menor en la fecha del evento**, consta el consentimiento de su representante legal. Aun así, hoy no hay un consentimiento específico para la imagen del menor: el consentimiento general del representante es el único requisito, y su suficiencia queda pendiente de revisión legal.

## Qué NO hace el sistema

- **No verifica la identidad** de quien rellena el formulario ni que sea realmente el representante legal del menor: la casilla es una declaración, no una prueba.
- No envía confirmaciones al representante ni conserva el texto exacto aceptado en cada momento.

## PENDIENTE de revisión legal

Los textos y criterios siguientes **no están validados**; esta lista son cuestiones por resolver, no asesoramiento jurídico.

1. **Redacción definitiva del consentimiento** (la frase actual es provisional).
2. **Aviso de privacidad / RGPD y LOPDGDD** que debe mostrarse junto al formulario.
3. **Base legal** del tratamiento de los datos del menor y del representante.
4. **Conservación y borrado:** durante cuánto tiempo se guardan los datos del representante y cómo se eliminan.
5. **Edad de consentimiento digital:** en España se sitúa, según lo previsto, en 14 años; **verificar con asesoría legal** y decidir si `ADULT_AGE` o el flujo deben distinguir tramos.
6. **Verificación de que quien consiente es el representante** (por ejemplo, documento firmado o comprobación posterior), dado que el sistema no la hace.
7. **Imagen del menor:** si se admite la foto de un menor, definir la base legal y el texto de consentimiento específico, y cuánto tiempo se conserva (hoy se borra al eliminar el evento). Ya existe la opción de mostrarla públicamente si el representante dio su consentimiento general; falta decidir si ese consentimiento basta o debe ser específico para la imagen.
