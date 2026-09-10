# MyTickets — documentación del proyecto

MyTickets (también llamada **Mi Agenda de Tickets**) es una aplicación de gestión de trabajo: tickets, agenda diaria, recordatorios, notas permanentes, standups y facturación. El dominio vive en `worker/` (ESM). En local puede correr como **Cloudflare Worker** (D1 + R2) o como **servidor Node** (SQLite + disco) para Hostinger.

Esta copia local corresponde a la versión 66 (10 de septiembre de 2026). La refactorización a módulos **no cambia rutas, SQL, validaciones, cookies ni la UI**: solo reparte el código que antes vivía en un único `worker/index.js`.

---

## Cómo arrancar en local

Desde `project/`, con Node 20+:

```sh
npx wrangler@latest dev --local --persist-to .wrangler/state
```

La app queda en `http://localhost:8787`.

Copia `project/.dev.vars.example` a `project/.dev.vars` y rellena el usuario semilla (`SEED_USER_EMAIL`, `SEED_USER_NAME`, `SEED_USER_PASSWORD`). Ese archivo **no se versiona**. Wrangler y el servidor Node lo cargan en local.

Sin sesión, `GET /api/data` responde **401** (`Debes iniciar sesión.`). El login crea la cookie HttpOnly `mt_session` (SameSite=Lax, 30 días).

### Hostinger (Node.js)

En el onboarding elige **«Sube tu código, nosotros lo alojamos»** → **Node.js** (no WordPress ni estático). Conecta el repo de GitHub (`Gonzalstb/hmdasboard`).

Ajustes del panel (Hostinger a veces los autocompleta mal):

| Campo | Valor |
| --- | --- |
| Framework | Other (no estático) |
| Node.js | 20 o 22 |
| Root directory | raíz del repo (vacío) |
| Build command | vacío (no uses `build`: eso es el bundle de Cloudflare) |
| Entry file | `server.js` |
| Output directory | vacío |

El servidor en `project/server/` traduce HTTP de Node al `fetch` del Worker, SQLite en disco (como D1) y archivos en disco (como R2). **Misma UI, mismas rutas y mismas acciones.**

Variables en el panel (obligatorias la primera vez): `SEED_USER_EMAIL`, `SEED_USER_NAME`, `SEED_USER_PASSWORD`. `PORT` lo pone Hostinger. Opcional: `DATA_DIR` (si no, en Hostinger se usa `~/domains/{dominio}/mytickets-data` para que un redeploy no borre tickets ni adjuntos), `SQLITE_FILE`.

```sh
npm install
npm start
```

La app queda en `http://localhost:3000` si no hay `PORT`.

Datos y adjuntos de la copia local: carpetas `data/` y `attachments/` en la raíz (solo en tu máquina; no van a git). Ver `README-LOCAL.md`.

---

## Principios de la arquitectura (SOLID)

| Principio | Cómo se aplica |
| --- | --- |
| **S** — una responsabilidad por archivo | Un módulo = una pieza: cookie, hash, una migración, una acción del API, el HTML, el CSS… |
| **O** — abierto a extensión | Una acción nueva es un archivo en `worker/domain/actions/` y una línea en el mapa de `actions/index.js`. El resto no se toca. |
| **L** — sustitución | Los handlers de acción comparten la misma firma `(db, payload, filesBucket, uid, result)`. |
| **I** — interfaces pequeñas | Auth no conoce SQL de tickets. El router no contiene SQL. Las migraciones no pintan HTML. |
| **D** — invertir dependencias | `index.js` solo reexporta el router. El router **orquesta** (`auth`, `db`, `domain`, `ui`); no implementa reglas de negocio. |

Flujo de una petición:

```
Navegador
  → http/router.js          (rutas y métodos)
    → auth/session.js       (quién eres)
    → db/setup.js           (esquema al día)
    → domain/actions/*      (si es POST /api/data)
    → domain/data.js        (lectura agregada del workspace)
    → JSON / HTML
```

---

## Mapa de carpetas

```
server/                    Runtime Hostinger: SQLite + disco + HTTP Node
worker/                    Dominio (igual en Cloudflare y Node)
├── index.js                 Punto de entrada: reexporta el router
├── http/
│   ├── router.js            GET /, login, logout, /api/data, adjuntos
│   └── json-response.js     Response.json + cabeceras extra
├── ui/
│   ├── page.js              Ensambla el HTML servido en /
│   ├── styles.js            CSS original (string)
│   ├── markup.js            Shell HTML original (string)
│   └── client.js            JS del navegador original (string)
├── config/                  Constantes (estados, llamadores, cookie, usuario semilla)
├── lib/                     Normalización de entradas (texto, número, color, ids, fecha Madrid)
├── auth/                    Identidad, contraseñas, cookies, sesiones, alta de usuarios
├── db/
│   ├── setup.js             CREATE TABLE + migraciones + memoización WeakMap
│   ├── workspace.js         Columna user_id y semilla de estados
│   ├── status-key.js        Clave canónica de un estado
│   └── migrations/          Una migración = un archivo
└── domain/
    ├── data.js              SELECT agregado del workspace (siempre filtrado por user_id)
    ├── links.js             Validación de enlaces de agenda
    ├── attachments.js       Subida de documentos de notas
    └── actions/             Una acción de POST /api/data = un archivo
```

### UI embebida

El Worker no sirve ficheros estáticos sueltos. `GET /` devuelve un HTML único: estilos + markup + script del cliente. Esos tres strings viven en `ui/` y son el **mismo documento** que servía el archivo único (Wrangler/esbuild “cuece” el template: las regex del cliente como `\d` o `https?:\/\/` se conservan). El cliente sigue siendo un `<script>` clásico y habla con `/api/login`, `/api/logout`, `/api/data` y `/api/permanent-note-attachments`.

### Autenticación (`auth/`)

- Contraseñas: PBKDF2-SHA-256, 120000 iteraciones, comparación en tiempo constante (`auth/password.js`).
- Sesión: token aleatorio en D1 + cookie `mt_session` (`auth/session.js`, `auth/cookies.js`).
- El DTO público de usuario **nunca** incluye hash ni salt (`auth/identity.js`).
- `create_user` siembra estados por defecto en el workspace vacío (`auth/users.js` + `db/workspace.js`).

### Base de datos (`db/`)

`ensureSetup` evita repetir migraciones en el mismo isolate. El orden de migraciones es el del archivo original (workflow, columnas de tickets, standups, llamadores, notas, billing, agenda, usuarios). Los datos van siempre ligados a `user_id`.

### Acciones (`domain/actions/`)

`POST /api/data` envía `{ action: "save_ticket", ... }`. `actions/index.js` busca el handler y, si no existe, lanza `Acción no reconocida.` — el mismo error de antes.

Cada handler puede mutar `result` (por ejemplo `savedNoteId` o `newSession` tras cambiar la contraseña). El router mezcla `result` con `getData()` y, si hay `newSession`, pone `Set-Cookie`.

Hay dos `return` tempranos en el monolito (`status` sin cambio y `complete_reminder` ya hecho). Se reproducen con el símbolo `EARLY_RETURN` para devolver `undefined` igual que antes.

---

## Rutas HTTP (sin cambios)

| Ruta | Método | Qué hace |
| --- | --- | --- |
| `/` | GET | HTML de la SPA |
| `/api/login` | POST | Email + contraseña → cookie de sesión |
| `/api/logout` | POST | Borra sesión y cookie |
| `/api/data` | GET | Workspace del usuario autenticado |
| `/api/data` | POST | Ejecuta una acción y devuelve el workspace |
| `/api/permanent-note-attachments` | POST | Sube documentos (multipart) a R2 |
| `/api/permanent-note-attachments/:id` | GET | Descarga o previsualiza un documento |

Cualquier otra ruta: `404`. Sin `env.DB`: error de base no disponible. Sin cookie válida (salvo `/` y login/logout): `401`.

---

## Bindings (Wrangler)

Definidos en `wrangler.toml`:

- `DB` → D1 `mytickets-local`
- `FILES` → R2 `mytickets-files-local`

En local se persisten con `--persist-to .wrangler/state`.

---

## Build del artefacto

El builder remoto espera **un solo** ESM en `dist/server/index.js` con `default.fetch`. Como el código ahora está en muchos archivos, `scripts/build.sh` los empaqueta con esbuild (formato ESM, `platform=neutral`). La validación `scripts/validate-artifact.mjs` no cambia: importa ese archivo y comprueba `default.fetch`.

En local Wrangler ya empaqueta solo a partir de `worker/index.js`; no hace falta construir `dist/` para desarrollar.

```sh
bash scripts/build.sh
node scripts/validate-artifact.mjs
```

---

## Cómo añadir cosas nuevas (sin romper el diseño)

- **Nueva acción de API:** copia un archivo de `domain/actions/`, exporta `handle`, regístralo en `domain/actions/index.js`.
- **Nueva migración D1:** un archivo en `db/migrations/` y una llamada en `db/setup.js`, en el orden correcto.
- **Cambio visual:** el CSS/HTML/JS del cliente están en `ui/`. Son el texto original; si los editas, estás cambiando la UI (esta refactorización no lo hizo).

---

## Qué no se ha tocado a propósito

- Consultas SQL, mensajes de error y validaciones
- Filtros por `user_id` y reglas de sesión
- HTML, CSS y JavaScript del navegador (byte a byte en el documento servido)
- Cookie, hash de contraseñas e iteraciones PBKDF2
- El usuario semilla (vía `.dev.vars`) y la migración `users_v1`

El monolito previo se conserva en `project/.refactor-backup/index.monolith.js` solo como referencia histórica.
