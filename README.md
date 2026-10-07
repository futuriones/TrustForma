<div align="center">

<img src="icons/trustforma.png" alt="TrustForma" width="120">

# TrustForma

**Formación en concienciación de ciberseguridad para tu plantilla, lista para auditoría.**<br>
Del kit de INCIBE a un curso de Moodle con tests, certificados y evidencia para CISO Assistant, con un solo comando.

![Licencia: Unlicense](https://img.shields.io/badge/licencia-Unlicense-ff2f70?style=flat-square)
![Moodle 4.5 LTS](https://img.shields.io/badge/Moodle-4.5%20LTS-f98012?style=flat-square)
![Docker](https://img.shields.io/badge/Docker-Compose%20v2-2d7bf2?style=flat-square)
![Linux y macOS](https://img.shields.io/badge/Linux%20%C2%B7%20macOS-a459ff?style=flat-square)

**Un producto de [Futurion Solutions S.L.](https://solutions.futurion.es)**

[Instalar](#instalar-paso-a-paso) · [Primeros pasos](#después-de-instalar) · [Operación](#operación-del-día-a-día) · [Si algo falla](#si-algo-falla) · [Licencia](#licencia)

<br>

<img src="docs/img/login.png" alt="Pantalla de acceso de TrustForma" width="48%"> <img src="docs/img/certificado.png" alt="Certificado de finalización" width="48%">

</div>

---

## Qué obtienes

| | |
|---|---|
| **Curso listo** | 9 módulos del kit de INCIBE con su documento, ficha, consejos, carteles y presentación del formador. |
| **Un test por módulo** | 90 preguntas generadas del propio kit; se aprueba cada test, en cualquier momento. |
| **Un plazo por módulo** | Todo abierto desde el primer día; el módulo N vence a final del mes N. Lo cambia el administrador desde Moodle, sin tocar código. |
| **Certificado** | Se emite solo al aprobar los 9 tests y llega por correo, con código de verificación pública. |
| **Avisos** | Recordatorios por correo antes de cada plazo, solo a quien aún no ha aprobado. |
| **Evidencia de auditoría** | API de solo lectura por persona (completado, notas, plazos). La recoge **CISO Assistant** (ISO 27001 A.6.3, ENS mp.per.3/4, NIS2 art. 21.2.g). |
| **Repetible** | Todo son ficheros: cambias, ejecutas `make plan` para ver qué cambiaría y `make deploy`. Nadie pierde su progreso. |
| **Imagen Futurion** | Tema de Moodle, logotipo, favicon, tipografías y certificado con la imagen corporativa de Futurion Solutions. |

> **El kit de INCIBE no forma parte de este repositorio.** Lo descargas tú desde INCIBE y TrustForma lo convierte en un curso
> de Moodle en marcha con un solo comando. Los textos del kit y las preguntas que se extraen de él son de INCIBE y se usan bajo sus condiciones (ver [Licencia](#licencia)).

## Cómo funciona

```
 kit INCIBE (zip que descargas)      courses/<curso>/course.yaml  +  preguntas (YAML, generadas del kit)
              │  make setup                          │
              └──────────────────────┬───────────────┘
                                     │  make build
                                     ▼
                  build/<curso>-<año>/manifest.json  +  ficheros optimizados
                                     │  make plan  (lees)  →  make deploy
                                     ▼
          Moodle 4.5 LTS: curso CONC-2026 · 9 módulos · 9 tests · certificado
                                     │  API de solo lectura
                                     ▼
          Portal GRC, CISO Assistant (workflow W6, mensual → evidencia EV-TRN-01)
```

---

## Instalar paso a paso

Funciona igual en **Linux** y en **macOS**. Tiempo total: entre 15 y 20 minutos la primera vez, según tu conexión (casi todo es construir las imágenes de Docker).

> **En macOS escribe `gmake` donde veas `make`.** Apple trae GNU make 3.81, demasiado antiguo; el Makefile se niega a ejecutarse con él.

### Paso 1 · Instala lo necesario

| Necesitas | Linux (Debian/Ubuntu) | macOS |
|---|---|---|
| **Docker** con Compose v2 | [Docker Engine](https://docs.docker.com/engine/install/) | [Docker Desktop](https://www.docker.com/products/docker-desktop/) (ábrelo y espera a que arranque) |
| **make, Python 3 con venv, poppler, openssl, curl, git** | `sudo apt install make python3 python3-venv poppler-utils openssl curl git` | `brew install make poppler` (lo demás ya viene con macOS) |

Comprueba que todo responde:

```bash
docker compose version      # debe mostrar la versión, no un error
python3 --version
pdftotext -v                # poppler
make --version              # en macOS: gmake --version  (debe decir GNU Make 4 o más)
```

### Paso 2 · Descarga el proyecto

```bash
git clone https://github.com/futuriones/TrustForma.git
cd TrustForma
```

### Paso 3 · Descarga el kit de INCIBE

1. Entra en <https://www.incibe.es/empresas/formacion/kit-concienciacion> y descarga **`kit_concienciacion.zip`** (unos 550 MB).
2. Déjalo, sin descomprimir, en la carpeta **`upload/`** del proyecto:

```
TrustForma/
└── upload/
    └── kit_concienciacion.zip     ← aquí (exactamente un zip)
```

### Paso 4 · Lanza todo con un comando

Elige la **fecha de inicio** del programa (formato `AAAA-MM-DD`): el módulo 1 vence a final de ese mes.

```bash
make live START=2026-10-01
```

Esto es lo que hace, en orden (puedes verlo en la pantalla):

| # | Qué pasa | Para qué |
|---|---|---|
| 1 | Crea el entorno Python (`.venv`) con las librerías fijadas y verificadas por hash | Que la construcción sea idéntica en cualquier máquina |
| 2 | Importa el zip a `kit/` (sin las herramientas de phishing/USB, que nunca se publican) | Material del curso |
| 3 | Genera las 90 preguntas desde los PDF de test del kit | Un banco de preguntas por módulo |
| 4 | Valida el curso, el kit y las preguntas | Falla pronto y con un mensaje claro si algo no cuadra |
| 5 | Arranca Moodle, base de datos, nginx, cron y Mailpit en Docker | La plataforma (la primera vez construye las imágenes: ~10 min) |
| 6 | Instala Moodle y el plugin de sincronización | |
| 7 | Configura el sitio: zona horaria, finalización, API, cohorte `empleados` e imagen Futurion | |
| 8 | Construye el curso y **te muestra el plan** de lo que va a crear | Nada se escribe sin que se vea antes |
| 9 | Despliega el curso en Moodle | Curso **CONC-2026** listo |

Al terminar verás algo como `== applied: 155 create, 90 link, 1 update`.

### Paso 5 · Abre Moodle

| | |
|---|---|
|  Moodle | <http://localhost:8080> |
|  Correo de prueba (Mailpit) | <http://localhost:8025> (o el puerto de `MAILPIT_PORT` en `lms/docker/.env`) |
|  Usuario administrador | el valor de `MOODLE_ADMIN_USER` en `lms/docker/.env` (por defecto `admin`) |
|  Contraseña | el valor de `MOODLE_ADMIN_PASSWORD` en el mismo fichero (se generó al azar) |

```bash
grep MOODLE_ADMIN lms/docker/.env      # para verlos
```

Solo son accesibles desde tu equipo (están ligados a `127.0.0.1`).

### Paso 6 · Comprueba que todo funciona

```bash
make e2e
```

Usuarios de prueba recorren todo el camino (leer, aprobar los 9 tests, certificado, API) en una **copia desechable** del curso que se borra al terminar: nunca toca el curso real. Debe acabar con `46 passed, 0 failed` y `report matches compliance-report.v1.schema.json`.

---

## Después de instalar

El curso real (CONC-2026) empieza **sin nadie matriculado**. Para dar de alta a las personas:

1. Entra como administrador → **Administración del sitio → Usuarios → Cuentas → Cohortes**.
2. Abre la cohorte **`empleados`** → **Asignar** y añade a las personas (o súbelas en bloque con *Subir usuarios*, columna `cohort1` = `empleados`).
3. En menos de un minuto (cron) quedan matriculadas en el curso. A partir de ahí cada persona entra, lee, aprueba los tests y recibe su certificado.

Para ver el resultado como empleado, crea una cuenta de prueba, añádela a la cohorte y entra con ella en una ventana privada.

**Lo siguiente que querrás hacer** (todo está en [Operación del día a día](#operación-del-día-a-día)):

- Ajustar los **plazos** de cada módulo (desde Moodle, sin terminal).
- Activar los **avisos por correo** y configurar el correo real ([Correo saliente](#correo-saliente-smtp-y-oauth-2)).
- Conectar el **inicio de sesión único** con Microsoft Entra ID ([SSO](#inicio-de-sesión-único-microsoft-entra-id)).
- Enlazar con **CISO Assistant** ([guía de integración](docs/ciso-assistant-integration.md)).

**Parar y volver a arrancar** (los datos se conservan):

```bash
make down      # parar
make up        # arrancar de nuevo (no hace falta repetir make live)
make status    # ¿está todo en marcha?
```

### Qué recibe cada persona

Al aprobar los 9 tests recibe por correo el certificado de finalización, con la imagen de Futurion Solutions, su nombre, la fecha y un código que cualquiera puede verificar.

<div align="center">
<img src="docs/img/certificado.png" alt="Certificado de finalización de TrustForma" width="70%">
</div>

---

## Casos habituales

| Quiero… | Ejecuto… |
|---|---|
| **A.** Abrir el LMS ya instalado | `make up` y `make status` (debe decir `installed: 4.5.x`). Parar: `make down`. Registros: `make logs` |
| **B.** Instalar por pasos (en vez de `make live`) | `make setup` → `make up` → `make install` → `make configure` → `make build START=…` → `make plan START=…` (léelo) → `make deploy START=…` → `make plan START=… EXTRA=--fail-on-changes` (debe decir «nothing to do») |
| **C.** He cambiado preguntas, textos o el kit | `make build START=…` → `make plan START=…` (léelo) → `make deploy START=…`. Qué se bloquea: tabla de más abajo. Kit nuevo de INCIBE: [upload/README.md](upload/README.md) |
| **D.** Probarlo como empleado | `make e2e` (copia desechable) y `make report START=…` (lo que recibirá el portal GRC) |

---

## Operación del día a día

Todo lo que harás después de instalar, agrupado por tema. Los comandos se ejecutan desde la raíz del proyecto (en macOS, `gmake`).

### Contenido y ciclos

| Quiero… | Hago… |
|---|---|
| INCIBE publica una versión nueva del kit | Dejo `kit_concienciacion.zip` en `upload/` → `make import-kit` (informe, no escribe nada) → `make import-kit APPLY=1`. Pasos completos: [upload/README.md](upload/README.md) |
| Cambiar el texto de una pregunta | Edito `courses/concienciacion/questions/Mnn.yaml` → `make build … && make plan … && make deploy …` (el kit de `kit/` no se edita a mano; las preguntas son locales, no se suben a git) |
| Añadir un módulo | Llega en el zip nuevo de INCIBE (`make import-kit`) + una entrada en `modules:` de `courses/concienciacion/course.yaml` (+ su test) |
| Empezar el ciclo del año siguiente | `make build START=2027-10-01 && make deploy START=2027-10-01`: curso nuevo, el de 2026 queda intacto como evidencia |
| Añadir una pregunta / cambiar la nota de corte con intentos ya hechos | Se **bloquea** (reevaluaría a personas): hazlo en el ciclo siguiente, o `EXTRA=--allow-structure-change` bajo tu responsabilidad |
| Cerrar un ciclo terminado (queda como evidencia inmutable) | `make close-cycle CYCLE=2026`: a partir de ahí cualquier `plan`/`deploy` de ese ciclo se rechaza (sin opción para saltárselo); quien no haya terminado aún puede seguir. Los cambios van al ciclo siguiente |
| Cambiar la regla de compleción de una actividad ya usada, o quitar un test que ya han aprobado | Se **bloquea** (borraría el estado de las personas): ciclo siguiente, o `EXTRA=--allow-structure-change` bajo tu responsabilidad |

### Plazos, avisos y resultados

| Quiero… | Hago… |
|---|---|
| Cambiar los plazos (lo hace el administrador de Moodle, sin terminal) | Curso → un test → **Ajustes** → *Finalización de la actividad* → **Se espera que se complete el** → Guardar. Para varios a la vez: curso → **Más** → **Finalización del curso** → **Edición masiva de finalización de actividades**. Inicio/fin del programa: curso → **Ajustes** → *Fecha de inicio/fin del curso*. Cada módulo tiene **una sola fecha, la de su test**; las personas la ven en su *Línea de tiempo* y *Calendario*. Nadie pierde progreso y `make deploy` **nunca** la sobrescribe (`course.yaml` solo da el valor inicial al crear el ciclo). Quién y cuándo: *Administración del sitio → Informes → Registros* |
| Qué ven las personas tras un test | Test → **Ajustes** → **Opciones de revisión** (lo edita el administrador; por defecto nota + qué respuestas acertaron, nunca la opción correcta). `quiz_defaults.review` de `course.yaml` solo es el valor inicial de un test nuevo |
| Avisos por correo antes de un plazo | *Administración del sitio → Plugins → Plugins locales → Sincronización de formación en concienciación*: activar, días antes (por defecto `7,1`) y aviso al vencer. Cada día a las 08:00 se avisa solo a quien no ha aprobado ese test, siguiendo las fechas del test (si mueves un plazo, los avisos se ajustan solos). Cada persona elige canales en sus preferencias de notificación |
| Configurar el correo saliente | Todo en `lms/docker/.env` (variables `MOODLE_SMTP_*`), sección «Correo saliente» más abajo. Prueba: `make mail-test TO=alguien@empresa.com` |
| Qué puede cambiar el administrador en Moodle sin que un despliegue lo deshaga | Plazos de cada test, opciones de revisión de los tests, fechas de inicio/fin y categoría del curso, avisos. **Todo lo demás lo fija el repositorio** y vuelve a su valor en el siguiente despliegue que toque ese elemento: nombres, ficheros, número de intentos, nota de corte, restricciones «completa X antes» y visibilidad de las actividades, diseño del certificado |

### Personas

| Quiero… | Hago… |
|---|---|
| Usuarios: alta de empleados | Añadirlos a la cohorte `empleados` (Administración → Usuarios → Cohortes) o en bloque con *Subir usuarios* (`cohort1=empleados`); se matriculan solos en todos los ciclos. El SSO de Entra crea la cuenta pero **no** la matricula: ver «Inicio de sesión único» |
| Una persona deja la empresa | Sácala de la cohorte `empleados` y **suspende** su cuenta; no la borres hasta archivar el ciclo (`make backup`). Su matrícula queda suspendida y sigue apareciendo en el informe con `enrolment_active=false` (fuera de los totales). Una cuenta **borrada** desaparece de la evidencia |

### Evidencia, seguridad y copias

| Quiero… | Hago… |
|---|---|
| Estado de cumplimiento para CISO Assistant | La API añade `compliance_status` por persona: `compliant` (curso completado), `on_track`, `degraded` (algún módulo fuera de plazo) y `failed` (pasó el último plazo sin completar) y `totals.compliance` |
| Copia de seguridad / restauración | `make backup` (base de datos + ficheros + `META` con la versión; una carpeta `backups/<fecha>` que existe está completa) / `make restore BACKUP=backups/<fecha> CONFIRM=yes` (destruye los datos actuales; la base de datos se restaura en una transacción, los ficheros no: si ese paso falla, repite la restauración). Las copias contienen datos personales y no se borran solas |
| Rotar el token de la API | `make rotate-token` y pegar el valor nuevo en el portal GRC: *Workflows › W6 › Secrets › `moodle_token`* |
| Limitar desde dónde se acepta el token de la API | `MOODLE_GRC_TOKEN_IPS=<IP o subred>,…` en `lms/docker/.env` y `make up configure` |

### Mantenimiento

| Quiero… | Hago… |
|---|---|
| Actualizar los paquetes del sistema de las imágenes | `make rebuild` (reconstruye sin caché, unos 10 minutos); `make up` no vuelve a aplicar actualizaciones |
| Comprobar el código | `make lint` (ruff + sintaxis PHP) y `make test` |
| Cambiar la versión de una librería Python | Edita `lms/tools/requirements.in`, luego `make lock venv` (regenera `requirements.txt` con todas las dependencias y sus hashes) |
| Ver todos los comandos | `make help` |

---

## Imagen corporativa

El tema de Moodle (Boost) lleva la imagen de **Futurion Solutions**: degradados azul marino e índigo, botones primarios en coral, tipografías Poppins e Inter
(alojadas en el propio plugin, sin peticiones a servidores de fuentes externos), logotipo, logotipo compacto y favicon de TrustForma, y un certificado con el mismo estilo (logotipo y franja coral).

| Qué | Dónde está | Quién lo gestiona después |
|---|---|---|
| Tema, color de marca, logotipos, favicon | `lms/moodle/local_awarenesssync/branding/` (`brand.scss`, imágenes) y `fonts/` (licencia SIL OFL) | El administrador, en *Administración del sitio → Apariencia* |
| Diseño del certificado | `lms/moodle/local_awarenesssync/certtemplates/concienciacion.json` | El repositorio (se reaplica al desplegar) |

`make configure` aplica la imagen **una sola vez**; un despliegue no la toca. Para volver a aplicarla: `make configure REBRAND=1`.

---

## Correo saliente (SMTP y OAuth 2)

Todo el correo se configura en `lms/docker/.env` y llega al contenedor solo por la lista de variables de `compose.alpine.yaml`; `config.php` lo fija (en Moodle aparece bloqueado).
Por defecto el piloto usa Mailpit (http://localhost:8025) sin autenticación. Para producción con **Microsoft 365** o **Google Workspace** se usa SASL **XOAUTH2** (sin contraseñas):

| Variable | Microsoft 365 | Google Workspace |
|---|---|---|
| `MOODLE_SMTP_HOST` / `MOODLE_SMTP_SECURE` | `smtp.office365.com:587` / `tls` | `smtp.gmail.com:587` / `tls` |
| `MOODLE_SMTP_AUTHTYPE` | `XOAUTH2` | `XOAUTH2` |
| `MOODLE_SMTP_USER` y `MOODLE_NOREPLY` | buzón de servicio (p. ej. `formacion@empresa.com`), nunca un buzón personal | ídem |
| `MOODLE_SMTP_OAUTH_PROVIDER` | `microsoft` | `google` |
| `MOODLE_SMTP_OAUTH_CLIENT_ID` / `_CLIENT_SECRET` | del registro de aplicación de Entra | del cliente OAuth de Google Cloud |
| `MOODLE_SMTP_OAUTH_TENANT` | ID de directorio (inquilino) | (vacío) |

Después: `make up configure` (crea el servicio OAuth 2 «Awareness SMTP»; se detiene con error si falta algo, sin volver nunca a usuario/contraseña) y **un paso en el navegador, una sola vez**:
*Administración del sitio → Servidor → Servicios OAuth 2 → «Awareness SMTP» → Conectar con una cuenta del sistema*, entrando con el buzón de servicio. Comprobación: `make mail-test TO=…`.
El fichero `.env` no se sube a git; contiene el secreto del cliente (renuévalo antes de que caduque).

**Lista para el administrador de Microsoft 365:** (1) crear un buzón compartido de servicio; (2) Entra → Registros de aplicaciones → Nuevo registro, «solo este directorio», URI de redirección
web `https://<vuestro-moodle>/admin/oauth2callback.php`; (3) Permisos de API (**delegados**): *Office 365 Exchange Online* → `SMTP.Send`, más `offline_access`, `openid` y `Microsoft Graph → User.Read`
(Moodle lo usa solo para leer la identidad de la cuenta conectada; no se pide ningún permiso de correo de Graph); conceder consentimiento de administrador; (4) Certificados y secretos → secreto de cliente
con caducidad corta; (5) Exchange Online: habilitar SMTP AUTH **solo en ese buzón** (`Set-CASMailbox -Identity <buzón> -SmtpClientAuthenticationDisabled $false`), manteniéndolo desactivado en el resto del inquilino.
**Google Workspace:** (1) cuenta de servicio de usuario con verificación en dos pasos, en su propia unidad organizativa; (2) proyecto de Google Cloud dedicado, pantalla de consentimiento **Interna** con el ámbito
`https://mail.google.com/` (no existe otro más estrecho para SMTP), cliente OAuth «Aplicación web» con la URI de redirección anterior; (3) Consola de administración → Seguridad → Controles de API: marcar el cliente como de confianza
solo para esa unidad. Diferencias con la guía genérica XOAUTH2: Moodle usa el flujo **delegado** (no soporta certificado ni «client credentials») y guarda el token de actualización en su base de datos (y por tanto en cada copia de seguridad: mantened `backups/` en privado).
Códigos habituales: `535 5.7.3` (autenticación: SMTP AUTH desactivado en el buzón o consentimiento pendiente), `invalid_grant` (reconectar la cuenta del sistema), `SendAsDenied` (`MOODLE_NOREPLY` distinto de `MOODLE_SMTP_USER`).

---

## Inicio de sesión único (Microsoft Entra ID)

Se usa el inicio de sesión **nativo** de Moodle: autenticación *OAuth 2* + servicio OAuth 2 «Microsoft» (OpenID Connect). No hay plugin ni contenedor adicional, y `make configure`/`make deploy` no lo tocan
(lo administra el administrador de Moodle). La cuenta se crea en el primer acceso (`auth=oauth2`). **Iniciar sesión no matricula a nadie**: la matrícula sigue saliendo de la cohorte `empleados`
(sin ella la persona ve «No se puede auto matricular en este curso»).

**Paso 1: Entra** (https://entra.microsoft.com)

1. *Entra ID → Registros de aplicaciones → Nuevo registro*: nombre `Moodle Concienciación (piloto)`, «solo este directorio» (un solo inquilino), URI de redirección de tipo **Web**
   `http://localhost:8080/admin/oauth2callback.php` (producción: `https://<vuestro-moodle>/admin/oauth2callback.php`, añadida a la misma aplicación). En *Información general* copia el **ID de aplicación (cliente)** y el **ID de directorio (inquilino)**.
2. *Certificados y secretos → Nuevo secreto de cliente*: descripción `moodle-pilot`, caducidad 90 días. Copia el **valor** en ese momento (solo se muestra una vez); va únicamente a Moodle, nunca a git, a un chat ni a un ticket. Renuévalo antes de que caduque.
3. *Permisos de API → Agregar un permiso → Microsoft Graph → Permisos delegados*: `openid`, `profile`, `email`, `offline_access`, `User.Read`; después **Conceder consentimiento de administrador**. Todas las filas deben quedar en verde.
4. *Aplicaciones empresariales →* tu aplicación *→ Propiedades*: **¿Se requiere asignación? = Sí** y guardar. *Usuarios y grupos → Agregar usuario/grupo*: las personas (o el grupo de empleados) que podrán entrar.

**Paso 2: Moodle** (administrador local; usuario y contraseña: `MOODLE_ADMIN_USER` / `MOODLE_ADMIN_PASSWORD` de `lms/docker/.env`)

1. *Administración del sitio → Servidor → Servicios OAuth 2 → Microsoft*: nombre `Microsoft Entra ID`, ID y secreto de cliente, **URL base del servicio** `https://login.microsoftonline.com/<id-inquilino>/v2.0`
   (nunca `/common`: los endpoints se descubren a partir de esa URL y una aplicación de un solo inquilino rechaza `/common` con AADSTS50194), «Mostrar en la página de inicio de sesión» = solo en esa página,
   dominios de acceso = el dominio de correo de la empresa, «Requerir verificación de correo» = No (Entra ya la hizo). Guardar.
2. *Plugins → Autenticación → Gestionar autenticación*: activar **OAuth 2** (el ojo). Deja activada **Cuentas manuales**: el administrador local es el acceso de emergencia si falla el SSO.

**Comprobación:** en una ventana privada la página de acceso muestra el botón «Microsoft Entra ID»; entra con una cuenta asignada. La cuenta necesita un correo (atributo `mail`) o Moodle la rechaza.
Errores habituales: AADSTS50105 (la persona no está asignada a la aplicación), AADSTS50194 (URL base con `/common`), AADSTS50011 (la URI de redirección no coincide con `wwwroot`).

**Matricular a las personas** (el SSO no lo hace):

- Una a una: *Administración del sitio → Usuarios → Cuentas → Cohortes →* `empleados` *→ Asignar* y añadir; la matrícula por cohorte las inscribe en todos los ciclos en la siguiente ejecución del cron (hasta un minuto).
- En bloque: exportar de Entra los miembros del grupo a CSV y usar *Administración del sitio → Usuarios → Subir usuarios* con una columna `cohort1` = `empleados`.
- No existe una regla nativa que convierta un grupo de Entra en una cohorte. Alternativas, cada una una decisión aparte: un plugin de terceros (`local_o365`/`auth_oidc`; el código solo entra por la imagen) o una carga de usuarios por LDAP (Entra Domain Services) o SCIM.
  No uses una matrícula por autoinscripción en el curso: `make deploy` la quitaría y se salta la cohorte, que es lo que suspende a quien se va.

**Bajas:** bloquea a la persona en Entra **y** suspende su cuenta en Moodle y sácala de la cohorte (regla de la tabla de arriba; no se borra).
**Ojo:** el secreto de cliente queda en la base de datos de Moodle y, por tanto, en cada copia de seguridad (mantened `backups/` en privado).

---

## Pruebas

- `make test-fast` (10 s) / `make test` (1 min): extractor de tests, construcción determinista, esquemas. No necesitan Moodle. Los tests que usan el kit de INCIBE se omiten si aún no lo has importado (`make setup`).
- `make e2e`: recorrido completo del empleado + API (incluye POST y matrículas suspendidas). Despliega el contenido real como ciclo desechable `9999`
  con una cohorte de prueba, lo comprueba y lo borra: los intentos de prueba nunca quedan en un ciclo real (Moodle los conserva aunque se borre el usuario).
- `make test-e2e`: ciclo de vida del contenido en una copia sintética: el progreso sobrevive a las actualizaciones, los intentos antiguos
  conservan la versión de pregunta que vieron, los cambios peligrosos se bloquean, los módulos retirados se ocultan (nunca se borran)
  y vuelven a mostrarse si regresan al manifiesto, y un ciclo cerrado rechaza cualquier despliegue.

---

## 🆘 Si algo falla

`make help` lista todos los comandos · `make status` dice si la pila está parada o Moodle sin instalar · `make logs` muestra los registros.

| Síntoma | Causa y solución |
|---|---|
| `GNU make 4 or newer is required` | Estás en macOS con el make antiguo. Instala `brew install make` y usa **`gmake`**. |
| `Cannot connect to the Docker daemon` | Docker no está arrancado. En macOS abre Docker Desktop y espera; en Linux `sudo systemctl start docker`. |
| `port is already allocated` (8025) | Otro programa usa ese puerto. Añade `MAILPIT_PORT=8026` (u otro libre) a `lms/docker/.env` y repite `make up`. Para el 8080 (Moodle), para el programa que lo usa. |
| `pdftotext: command not found` | Falta poppler. `brew install poppler` (macOS) o `sudo apt install poppler-utils` (Linux). |
| `import-kit` no encuentra el zip, o encuentra varios | Debe haber **exactamente un** `.zip` en `upload/`. Con varios: `make import-kit ZIP=upload/<fichero>.zip`. |
| `stack is not running: make up` / `not installed: make install` | La pila está parada o vacía: `make up` y `make install`. |
| El plan sale **`blocked`** | El cambio reevaluaría intentos ya hechos o borraría el progreso de la gente. No lo fuerces: hazlo en el ciclo siguiente (ver «Operación»). |
| Una persona entra con SSO y no ve el curso | Iniciar sesión no matricula: añádela a la cohorte `empleados`. |
| La pantalla de acceso sale en inglés | Moodle sigue el idioma del navegador; con el navegador en español se ve en español. |

Las causas de los errores conocidos están explicadas en los comentarios del `Makefile` y de `lms/`.

## Antes de producción (pendiente, no incluido en el piloto)

- Alojamiento con HTTPS (proxy inverso; `MOODLE_SSLPROXY=1`; `MOODLE_REVERSEPROXY=1` solo si el proxy sirve Moodle con otro host/puerto), SMTP real con OAuth 2 (los certificados y los avisos se envían por correo; ver «Correo saliente») y copias de seguridad programadas.
- **El repositorio debe estar en un sistema de ficheros que respete los permisos** (no una unidad compartida o de red donde todo aparece como legible por todos). `make up` se niega a arrancar con un `MOODLE_WWWROOT` que no sea `localhost` si `.env` o el token son legibles por otros usuarios, o si siguen las contraseñas de ejemplo.
- **Inicio de sesión único en producción**: el SSO con Microsoft Entra ID está probado en el piloto local (ver «Inicio de sesión único»). Falta la URI de redirección https de producción, asignar el grupo de empleados
  a la aplicación en Entra y decidir cómo se cargan las personas en la cohorte `empleados` (a mano, CSV, o un plugin/LDAP/SCIM).
- Revisión humana de las 90 preguntas extraídas de los PDF (`courses/concienciacion/questions/`, generadas por `make setup`); se verifican contra las claves de respuesta de INCIBE
  pero conviene una lectura antes de examinar a personas.
- Activar el workflow **W6** en el portal GRC: [docs/ciso-assistant-integration.md](docs/ciso-assistant-integration.md). El workflow ya está escrito y probado
  en local, pero el portal solo puede llamar a un Moodle con **dirección https pública**; el piloto en `localhost` no le sirve.
- **Integración continua** (el repositorio está en https://github.com/futuriones/TrustForma; falta configurarla).

---

## Estructura del repositorio

```
TrustForma/
├── Makefile                       ← punto de entrada: make help
├── courses/concienciacion/        ← definición del curso (course.yaml, textos) y preguntas generadas
├── lms/
│   ├── docker/                    ← Moodle + nginx + PostgreSQL + cron + Mailpit (Docker Compose)
│   ├── moodle/local_awarenesssync ← plugin de Moodle: sincronización, API de cumplimiento, imagen Futurion
│   └── tools/                     ← Python: extractor de tests, construcción, esquemas JSON, pruebas
├── docs/                          ← integración con CISO Assistant, capturas
├── icons/                         ← logotipo y favicons de TrustForma
├── upload/                        ← aquí va el zip de INCIBE (ver upload/README.md)
└── kit/                           ← contenido del kit de INCIBE (se crea solo; no está en git)
```

## Créditos

Contenidos formativos: Kit de concienciación de INCIBE (Instituto Nacional de Ciberseguridad). Este repositorio no está afiliado a INCIBE.

## Licencia

Futurion Solutions S.L. dedica el copyright de este repositorio al dominio público bajo [The Unlicense](UNLICENSE). Puedes reutilizarlo, modificarlo y redistribuirlo.

Los nombres y logotipos de TrustForma y de Futurion Solutions son marcas reservadas de Futurion Solutions S.L.; esta licencia no implica permiso de uso de las marcas.

**El kit de INCIBE no está incluido ni se redistribuye.** Lo descarga cada operador desde INCIBE y se usa bajo las condiciones de INCIBE; los ficheros que se generan a partir de él
(`kit/` y las preguntas en `courses/*/questions/`) no están cubiertos por The Unlicense y no se suben a git. Las tipografías Poppins e Inter (`lms/moodle/local_awarenesssync/fonts/`) van con su propia licencia SIL Open Font License 1.1.

---

TrustForma es desarrollado y mantenido por **Futurion Solutions S.L.** · https://solutions.futurion.es
