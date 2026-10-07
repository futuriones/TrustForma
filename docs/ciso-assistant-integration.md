# Integración con CISO Assistant (evidencia de formación)

El portal GRC (CISO Assistant CE en `https://<portal-grc>`) recoge la evidencia con su motor nativo de *workflows*:
el workflow **W6** (`WF-EV-TRAINING`) llama a la API de este Moodle el día 2 de cada mes y archiva el informe como una
**revisión nueva** de la evidencia `EV-TRN-01-awareness` (tarea TSK-11). Este repositorio aporta la API; el workflow vive en
el espacio de trabajo del portal (`r4_workflows.py`, `workflows/WF-EV-TRAINING.yaml` y su `README.md`).

> Estado (2026-10-01): la API está verificada con `make e2e`. W6 se probó de extremo a extremo en una copia **local** de
> CISO Assistant v4.0.6 contra el piloto: ejecución correcta (revisión archivada, evidencia *In review*, caducidad +35 días),
> token erróneo y ciclo inexistente (aviso, no se archiva nada), informe con personas y publicación del borrador.
> **No probado:** el portal real (W6 aún no está importado), el disparador programado y el correo de aviso.
> **Requisito pendiente:** Moodle necesita una dirección **https pública** (ver 1.3); el piloto en `localhost` no es alcanzable.

## 1. La llamada

### 1.1 Cómo llama W6

| | |
|---|---|
| Método | **`GET`** (el motor de workflows solo sabe archivar un fichero descargándolo con GET) |
| URL | `https://<moodle>/webservice/rest/server.php?wsfunction=local_awarenesssync_get_compliance_report&moodlewsrestformat=json&course=concienciacion&cycle=2026` |
| Token | cabecera **`Authorization: Bearer <token>`**. Nunca en la URL: quedaría en los logs de acceso de nginx y de cualquier proxy |
| Comprobación previa | la misma URL con `&detail=totals`: respuesta pequeña y sin datos personales. W6 solo archiva el informe completo si esta respuesta trae `version` = 1 |
| Frecuencia | mensual (día 2, 06:45 Europe/Madrid) y a mano antes de una auditoría (*Execute*) |

Moodle solo lee su token del parámetro `wstoken`. El nginx de este repositorio (`lms/docker/nginx/moodle.conf`) acepta además la
cabecera `Authorization: Bearer` **solo** en `/webservice/rest/server.php` y la pasa a ese parámetro al entregar la petición a PHP;
la línea que se registra en el log es la URL tal como llegó, sin token.

**Moodle responde HTTP 200 también cuando hay un error** (`{"exception": …, "errorcode": "invalidtoken"}`). Por eso un cliente no
puede fiarse del código HTTP: debe comprobar que la respuesta trae `version`. Es lo que hace la comprobación previa de W6.

### 1.2 Otras formas de llamar

`POST` con los parámetros en el cuerpo (`application/x-www-form-urlencoded`, incluido `wstoken=<token>`) sigue funcionando y es
lo que usa `make report START=2026-10-01` (o `CYCLE=2026`): comprueba la respuesta contra el contrato y falla si Moodle devuelve
un error. No pongas nunca el token en la URL ni en la línea de comandos (se vería en logs y en la lista de procesos).

### 1.3 Lo que exige el motor de workflows al Moodle de producción

Comprobado en el código de CISO Assistant v4.0.6 (`core/net_safety.py`, `automation/workflows/actions.py`):

- **https obligatorio** cuando la petición lleva una credencial; con `http://` el paso falla sin llamar.
- **Dirección pública**: se rechaza un nombre que resuelva a una IP privada, de loopback o de enlace local.
- **No sigue redirecciones**: la variable `moodle_base` de W6 debe ser exactamente el `wwwroot` de Moodle (mismo host y puerto,
  sin barra final), porque Moodle redirige cualquier otro host.

### 1.4 El token

- Se crea con `make configure` y queda en `lms/docker/secrets/grc_token` (no está en git). Léelo una vez con
  `docker compose -f lms/docker/compose.alpine.yaml exec moodle cat /secrets/grc_token` y pégalo en el portal:
  *Workflows › W6 › Secrets › `moodle_token`*. No lo pegues en un chat ni en un fichero.
- Solo puede llamar a **esta** función y pertenece a un usuario (`grc_api`) que no puede iniciar sesión en la interfaz.
- El portal guarda los secretos de los workflows **sin cifrar** en su base de datos y en sus copias (hallazgo F37 del portal):
  por eso el token es de solo lectura y se rota. Rotación: `make rotate-token` aquí y pegar el valor nuevo en el secreto (tarea TSK-29).
- Opcional: `MOODLE_GRC_TOKEN_IPS` en `lms/docker/.env` (IP o subredes separadas por comas) limita desde dónde se acepta el token;
  lo aplica `make configure`. Úsalo con la dirección de salida del portal cuando Moodle esté en producción (si Moodle queda detrás
  de un proxy, antes hay que configurar la IP real del cliente, o Moodle verá la del proxy).

## 2. La respuesta (contrato v1: solo cambios aditivos; cualquier otro cambio sube `version`)

```json
{
  "version": 1,
  "generated_at": "2026-11-20T09:00:00+01:00",
  "course": "concienciacion", "cycle": 2026, "shortname": "CONC-2026",
  "content_version": "c9d65dbd83c15",
  "start_date": "2026-10-01T00:00:00+02:00", "due_date": "2027-06-30T23:59:59+02:00",
  "pass_percent": 80,
  "totals": {"enrolled": 120, "completed": 97, "in_progress": 15, "not_started": 5, "overdue": 3, "coverage_percent": 80.8, "with_overdue_modules": 11,
             "compliance": {"compliant": 97, "on_track": 9, "degraded": 11, "failed": 3}},
  "users": [
    {
      "username": "jperez", "email": "jperez@empresa.es", "idnumber": "E-1042", "fullname": "Juan Pérez",
      "enrolled_at": "2026-10-01T08:00:00+02:00",
      "enrolment_active": true,
      "status": "completed", "compliance_status": "compliant",
      "completed_at": "2026-10-14T11:32:10+02:00",
      "certificate_code": "xQEhqiWKbm",
      "modules": [
        {"key": "M01", "pdf_viewed": true, "quiz_best_grade": 10.0, "passed": true, "passed_at": "2026-10-02T10:15:00+02:00",
         "due_date": "2026-10-31T23:59:59+01:00", "overdue": false}
      ]
    }
  ]
}
```

El contrato en forma comprobable por una máquina es `lms/tools/schema/compliance-report.v1.schema.json` (JSON Schema; admite campos
nuevos, no admite quitar ni cambiar los existentes). `docs/compliance-report.v1.sample.json` es un ejemplo completo con personas
inventadas, pensado para probar un cliente sin tocar Moodle. `make report` y `make e2e` validan la respuesta real contra ese esquema.

Notas sobre los campos (el ejemplo es JSON válido; estas aclaraciones no caben como comentarios):

- `detail` (parámetro y campo añadidos, el contrato sigue en v1): con `detail=totals` en la llamada, la respuesta es la misma pero con
  `users` **vacío a propósito** y `"detail": "totals"`; el número de personas está en `totals.enrolled`. Por defecto (`full`) hay una fila por persona.
- `content_version` identifica la edición del contenido desplegada (cambia solo si cambia el material, no las fechas).
- `pass_percent`: nota mínima de los tests, en %. Es `null` si el curso todavía no tiene ningún test.
- `status` es uno de `completed`, `in_progress`, `not_started`, `overdue`. **`completed` tiene prioridad**; pasada la fecha límite (`due_date`),
  quien no ha terminado figura como `overdue` aunque haya empezado (`overdue` tiene prioridad sobre `in_progress` y `not_started`).
- `certificate_code` es verificable en `/mod/customcert/verify_certificate.php`; vacío hasta que se emite. Si hubiera varios emitidos, se informa el primero.
- `quiz_best_grade` está sobre **10** y es `null` si esa persona nunca ha intentado el test.
- `passed_at` es la fecha de la última actualización del estado «aprobado» en Moodle, no necesariamente la del primer aprobado.
- `content_version` es la edición del **último despliegue que terminó**: un despliegue interrumpido a medias no la cambia.
- `enrolment_active` (campo añadido, el contrato sigue en v1): `false` si la matrícula está suspendida o caducada (incluye a quien sale de la cohorte `empleados`:
  su matrícula se suspende, no se borra) o si la **cuenta** está suspendida. Esas personas **siguen listadas** (no desaparecen de la evidencia), pero **no cuentan**
  en `totals`. `totals.enrolled` y `coverage_percent` se calculan solo con matrículas activas. Una cuenta **borrada** sí desaparece: suspéndela en su lugar.
- La API solo responde para cursos desplegados por esta plataforma; cualquier otro `course`/`cycle` devuelve el error `reportnocourse`.

- **Plazos por módulo** (campos añadidos, el contrato sigue en v1). El módulo *N* vence al final del mes natural *N* (el mes 1 es el de `start_date`);
  el curso termina con el último módulo, y ese es `due_date` del nivel superior. Todos los módulos están abiertos desde el primer día.
  - `modules[].due_date`: plazo del módulo (ISO 8601; vacío si el módulo no tiene fecha): es la fecha «Se espera que se complete el» de su test en Moodle.
  - `modules[].overdue`: `true` si el módulo **no está aprobado** y ya pasó su plazo. Un módulo aprobado tarde deja de estar `overdue`; el certificado no depende de los plazos.
  - `totals.with_overdue_modules`: personas con matrícula activa que tienen al menos un módulo `overdue`.
  - `totals.overdue` y `status` **no cambian de significado**: siguen midiendo «no ha terminado el curso y pasó el `due_date` del curso», es decir, tras el último módulo.
    Para saber quién va retrasado *durante* el ciclo usa `with_overdue_modules` o `modules[].overdue`.

- **Plazos y estado de cumplimiento** (campos añadidos, el contrato sigue en v1). Los plazos ya no vienen fijados por el repositorio: el administrador de Moodle los edita en cada test
  (*Se espera que se complete el*) y la API informa siempre **lo que Moodle tiene en ese momento**. `course.yaml` solo da el valor inicial al crear el ciclo.
  - `users[].compliance_status`: `compliant` (curso completado), `failed` (no completado y ya pasó el **último** plazo: el fin del curso o el plazo del último test, el que sea posterior),
    `degraded` (algún módulo sin aprobar y fuera de su plazo, pero el programa no ha terminado) u `on_track` (ningún módulo fuera de plazo). Prioridad: `compliant` > `failed` > `degraded` > `on_track`.
  - `totals.compliance`: `{compliant, on_track, degraded, failed}`, solo matrículas activas; la suma es `totals.enrolled`.
  - Correspondencia sugerida en CISO Assistant: `compliant` → conforme; `on_track` → en curso; `degraded` → parcialmente conforme (desviación respecto al calendario); `failed` → no conforme.
  - `status` y `totals.overdue` no cambian de significado (siguen midiendo «no terminó y pasó el fin del curso»).

## 3. Qué hace W6 en el portal

1. Pide `detail=totals`. Si Moodle no contesta, o contesta sin `version` = 1 (token rotado, `cycle` equivocado), envía un correo
   de aviso y **no archiva nada**: la evidencia conserva el fichero de la última ejecución correcta.
2. Archiva el informe completo como revisión nueva `EV-TRN-01-<fecha>-awareness-report.json` de la evidencia `EV-TRN-01-awareness`
   (control de formación y concienciación: ISO/IEC 27001:2022 **A.6.3**, cláusula 7.2/7.3). El portal pone la evidencia en *In review*:
   una persona la revisa y la aprueba; W6 nunca aprueba nada ni cambia el resultado de una auditoría.
3. Mueve la caducidad de la evidencia a hoy + 35 días. Si W6 deja de funcionar, la evidencia caduca y el aviso semanal W1 lo dice.
4. **Solo recoge.** Las pruebas de control quedan previstas (T-TRN-01-01: nadie en `failed`; T-TRN-01-02: nadie con un módulo fuera
   de plazo), a activar cuando el responsable del SGSI fije los umbrales. Los datos que usarían: `totals.compliance`,
   `totals.with_overdue_modules`, `totals.coverage_percent`.

Cada año hay que cambiar la variable `cycle` de W6 al empezar un ciclo nuevo (cada ciclo es un curso de Moodle distinto).

Puesta en marcha (pasos de una persona, en el repositorio del portal): `python3 r4_workflows.py` importa W6 como borrador con el
identificador real de la evidencia; en el portal se pega el secreto `moodle_token`, se fijan `moodle_base` y `cycle`, se publica,
se activa el disparador programado y se lanza una ejecución manual para comprobar que aparece la revisión.

## 4. Datos personales

El informe completo contiene nombre, correo, identificador de empleado y resultados de **cada** persona (decisión del 2026-10-01:
se archiva completo). Consecuencias:

- En el portal se clasifica como las listas de usuarios de W2: dato personal, solo en la carpeta del SGSI. Queda también en el volumen
  de evidencias del portal y en sus copias de seguridad; no debe llegar a un futuro *Trust Center* público.
- Cada mes se añade una revisión: fijad la retención de esas revisiones según vuestra política.
- En este lado: HTTPS obligatorio, y las copias de Moodle (`make backup`) contienen los mismos datos.
- `detail=totals` no contiene datos personales: úsalo para cualquier consumidor que solo necesite cifras.
