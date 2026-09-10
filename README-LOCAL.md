# MyTickets — copia local

Código del Worker y documentación. Los **datos de trabajo** (`data/`, `attachments/`) y el ZIP de exportación son solo locales: no se versionan.

## Contenido versionado

- `project/`: código fuente y configuración.
- `README-LOCAL.md`, `manifest.json`: guías e inventario de la copia.

## Datos locales (fuera de git)

- `data/`: SQLite / SQL / JSON de la exportación.
- `attachments/`: documentos de notas permanentes.
- `project/.dev.vars`: usuario semilla (copiar desde `.dev.vars.example`).

## Ejecutar en local con Wrangler

Necesitas Node.js 20 o posterior.

1. Entra en `project/`.
2. Si tienes el volcado SQL local, inicializa la base:

   `npx wrangler@latest d1 execute mytickets-local --local --file=../data/mytickets.sql --persist-to .wrangler/state`

3. Si tienes adjuntos locales, restáuralos en R2 según `manifest.json`.
4. Copia `.dev.vars.example` a `.dev.vars` y rellena el usuario semilla.
5. Arranca:

   `npx wrangler@latest dev --local --persist-to .wrangler/state`

Wrangler mostrará la dirección local, normalmente `http://localhost:8787`.

## Privacidad

No subas `data/`, `attachments/` ni `.dev.vars` a un remoto público.
