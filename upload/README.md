# upload/: actualizar el kit de INCIBE

Esta carpeta es la **bandeja de entrada** del kit original de INCIBE. El kit **no se distribuye con TrustForma**: lo descargas tú desde INCIBE y dejas el `.zip`
aquí tal como se descarga. Para una instalación nueva basta `make live START=<fecha>` (README, «Puesta en marcha»), que lo importa y lo despliega todo.
`make import-kit` comprueba el zip, lo compara con lo que ya tienes y, solo si tú lo confirmas, lo instala en `kit/` (la única copia del kit que lee el build).

- Descarga: https://www.incibe.es/empresas/formacion/kit-concienciacion → `kit_concienciacion.zip` (~550 MB).
- Todo lo de esta carpeta está fuera de git (excepto este README): el zip incluye las herramientas de phishing/USB, que **nunca** se publican.
- `kit/` tampoco va a git (es contenido de INCIBE). Contiene solo lo que usa el curso; `kit/IMPORT.json` guarda de qué zip viene (nombre, sha256 y hash de cada fichero).
  Las preguntas de `courses/*/questions/` se generan a partir del kit y tampoco se suben.

Todos los comandos se ejecutan desde la raíz del repositorio (`make <objetivo>`; en macOS `gmake <objetivo>`).

## Procedimiento

1. **Descarga** `kit_concienciacion.zip` de la página de INCIBE y **cópialo aquí**, borrando antes el zip anterior (tiene que haber exactamente uno;
   si hay varios, indica cuál con `ZIP=upload/<fichero>.zip`).
2. **Simulacro:** `make import-kit`. No escribe nada en `kit/`. Lee el informe (también queda en `upload/import-report.md`):
   - *Added / Changed / Removed*, agrupado por módulo (`M01`…`M09`), material de bienvenida (carteles, trípticos) y contenido que el curso no usa.
   - *Problems*: falta la carpeta o el PDF principal de un módulo, o ha desaparecido/renombrado el PDF del test. `APPLY=1` se niega mientras haya problemas.
   - *Action needed*: PDFs de test cambiados, o módulos nuevos que aún no están en `course.yaml`.
   - Si no hay diferencias: el kit ya está al día. Termina aquí.
   - Si el zip es inseguro (rutas `..`, absolutas, enlaces simbólicos, duplicados, bomba de compresión) o no es un zip: se rechaza sin tocar nada.
3. **Instala:** `make import-kit APPLY=1`. Sustituye `kit/` por el contenido del zip y deja **una** copia anterior en `upload/.previous-kit/` (para volver atrás).
   Nunca extrae `Ataques_dirigidos/`, `Manual_Gophish/` ni `Thumbs.db`. Con problemas y sabiendo lo que haces: `FORCE=1`.
4. **Si cambió algún PDF de test:** `make extract FORCE=1`, y revisa a mano las preguntas que cambian (compara con una copia previa de `courses/*/questions/`; no están en git). Las claves de pregunta (`M05-Q03`) son permanentes:
   si INCIBE añade, quita o reordena preguntas, no reutilices una clave para otra pregunta; añade claves nuevas y marca las quitadas con `retired: true`.
5. **Si hay un módulo nuevo:** una entrada más en `modules:` de `courses/concienciacion/course.yaml` con la siguiente clave libre (`M10`) y su
   `questions/M10.yaml` (`make extract COURSE=concienciacion GLOB='RecursosFormativos/10_*/Test_evaluacion/*.pdf'`).
6. **Comprueba:** `make validate test`. (En una instalación nueva, `make setup` hace los pasos 3 a 6: importa, extrae las preguntas que falten y valida.)
7. **Lee lo que cambiaría en Moodle:** `make build START=<fecha de inicio del ciclo>` y `make plan START=<misma fecha>`.
   - Si el plan sale `blocked` (habría que reevaluar intentos ya hechos, o borrar el estado de compleción de la gente), **no lo fuerces**:
     haz el cambio en el ciclo siguiente (`START=` del año próximo).
8. **Despliega:** `make backup`, luego `make deploy START=…`, `make e2e` (prueba el contenido en una copia desechable, nunca en el ciclo real) y un segundo `make plan START=… EXTRA=--fail-on-changes` (debe salir vacío).
9. **Si has cambiado `course.yaml` o textos:** commit de esos ficheros con el texto del plan en el mensaje. `kit/` y las preguntas generadas no se suben.

## Cuándo actualizar

- **Ciclo en curso con intentos ya hechos:** solo cambios que no reevalúan a nadie (textos, PDFs, carteles). Lo demás va al ciclo siguiente: `make build START=2027-10-01 && make deploy START=2027-10-01`.
- **Ciclo cerrado (`make close-cycle`):** no admite más despliegues. El cambio va al ciclo siguiente.
- Un ciclo nuevo puede usar el kit actualizado sin tocar el anterior: cada ciclo es un curso distinto.

## Volver atrás

- Antes de desplegar: `rm -rf kit && mv upload/.previous-kit kit` (en la raíz del repo) y, si cambió algún test, `make extract FORCE=1`.
- Ya desplegado en Moodle: Moodle conserva las versiones anteriores (intentos y registros son evidencia); vuelve a poner el kit anterior y haz otro `plan` + `deploy`, o restaura con `make restore`.

## Qué comprueba `make import-kit`

Los nombres del zip de INCIBE no llevan UTF-8 (CP437: `Informaci¢n` se lee `Información`): se decodifican y normalizan a NFC. Se elimina la carpeta
raíz `kit_concienciacion/`. Las reglas de módulo (un único PDF principal, `Ficha/`, `Presentacion/`…) son exactamente las del build (`discover_module`).
