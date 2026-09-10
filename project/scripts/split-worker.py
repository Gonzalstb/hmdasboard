#!/usr/bin/env python3
"""Divide worker/index.js en módulos ESM copiando el código de negocio tal cual."""
from __future__ import annotations

import json
import re
import shutil
import subprocess
from pathlib import Path

ROOT = Path("/home/asusgonzalo/htdocs/dashboard/project")
WORKER = ROOT / "worker"
BACKUP = ROOT / ".refactor-backup" / "index.monolith.js"
SOURCE = BACKUP if BACKUP.exists() else (WORKER / "index.js")
SRC = SOURCE.read_text()
LINES = SRC.splitlines(True)


def slice_lines(start: int, end: int) -> str:
    """start/end son 1-based inclusivos."""
    return "".join(LINES[start - 1 : end])


def exportify(source: str) -> str:
    source = re.sub(r"^async function ", "export async function ", source, flags=re.M)
    source = re.sub(r"^function ", "export function ", source, flags=re.M)
    source = re.sub(r"^const ", "export const ", source, flags=re.M)
    return source


def write(rel: str, header: str, body: str) -> None:
    path = WORKER / rel
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(header.rstrip() + "\n\n" + body.rstrip() + "\n")
    print(f"wrote {path.relative_to(ROOT)} ({path.stat().st_size} bytes)")


def skip_string(src: str, i: int) -> int:
    quote = src[i]
    i += 1
    if quote == "`":
        while i < len(src):
            if src[i] == "\\":
                i += 2
                continue
            if src[i] == "`":
                return i + 1
            i += 1
        return i
    while i < len(src):
        if src[i] == "\\":
            i += 2
            continue
        if src[i] == quote:
            return i + 1
        i += 1
    return i


def matching_brace(src: str, open_idx: int) -> int:
    depth = 0
    i = open_idx
    while i < len(src):
        c = src[i]
        if c in "'\"`":
            i = skip_string(src, i)
            continue
        if c == "/" and i + 1 < len(src) and src[i + 1] == "/":
            nl = src.find("\n", i)
            i = len(src) if nl < 0 else nl + 1
            continue
        if c == "/" and i + 1 < len(src) and src[i + 1] == "*":
            end = src.find("*/", i + 2)
            i = len(src) if end < 0 else end + 2
            continue
        if c == "{":
            depth += 1
        elif c == "}":
            depth -= 1
            if depth == 0:
                return i
        i += 1
    raise SystemExit("no matching brace")


def extract_page_parts(page: str) -> tuple[str, str, str]:
    style_start = page.index("  <style>\n") + len("  <style>\n")
    style_end = page.index("\n  </style>")
    styles = page[style_start:style_end]
    body_start = page.index("<body>\n") + len("<body>\n")
    script_open = page.index("  <script>\n")
    markup = page[body_start:script_open]
    script_start = script_open + len("  <script>\n")
    script_end = page.index("\n  </script>")
    client = page[script_start:script_end]
    return styles, markup, client


def js_string_module(export_name: str, value: str) -> str:
    return f"export const {export_name} = {json.dumps(value, ensure_ascii=False)};\n"


def parse_action_branches(action_src: str) -> list[tuple[str, str]]:
    marker = "  const uid = Number(userId);\n"
    start = action_src.find(marker)
    if start < 0:
        raise SystemExit("action header not found")
    body = action_src[start + len(marker) :]
    i = 0
    branches: list[tuple[str, str]] = []
    while True:
        while i < len(body) and body[i] in " \t\n":
            i += 1
        if i >= len(body) or body.startswith("return result;", i):
            break
        if body.startswith("else throw", i) or body.startswith("} else throw", i):
            break
        m = re.match(r'(?:if|} else if|else if) \(name === "([^"]+)"\) \{', body[i:])
        if not m:
            raise SystemExit("branch parse fail at:\n" + body[i : i + 200])
        name = m.group(1)
        brace = i + m.end() - 1
        close = matching_brace(body, brace)
        inner = body[brace + 1 : close]
        if inner.startswith("\n"):
            inner = inner[1:]
        if inner.endswith("\n"):
            inner = inner[:-1]
        branches.append((name, inner))
        i = close + 1
    return branches


def imports_for(inner: str, name: str) -> str:
    lines: list[str] = []
    lib = [h for h in ("text", "number", "idList", "color", "madridDateKey") if re.search(rf"\b{h}\(", inner)]
    if lib:
        lines.append(f'import {{ {", ".join(lib)} }} from "../../lib/values.js";')
    if "validateAgendaLink(" in inner:
        lines.append('import { validateAgendaLink } from "../links.js";')
    crypto = [h for h in ("hashPassword", "verifyPassword") if re.search(rf"\b{h}\(", inner)]
    if crypto:
        lines.append(f'import {{ {", ".join(crypto)} }} from "../../auth/crypto.js";')
    if "createSession(" in inner:
        lines.append('import { createSession } from "../../auth/session.js";')
    if "createUser(" in inner:
        lines.append('import { createUser } from "../../auth/users.js";')
    ident = [h for h in ("normalizeEmail", "validEmail") if re.search(rf"\b{h}\(", inner)]
    if ident:
        lines.append(f'import {{ {", ".join(ident)} }} from "../../auth/identity.js";')
    if "EARLY_RETURN" in inner:
        lines.append('import { EARLY_RETURN } from "./early-return.js";')
    return ("\n".join(lines) + "\n\n") if lines else ""


# --- page (valor cocido por esbuild, igual que Wrangler) ---
if not BACKUP.exists():
    BACKUP.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(WORKER / "index.js", BACKUP)
    print(f"backup {BACKUP}")
else:
    print(f"using monolith {BACKUP}")

cook = subprocess.run(
    ["node", str(ROOT / "scripts/cook-ui.mjs")],
    cwd=ROOT,
    check=True,
    capture_output=True,
    text=True,
)
parts_path = Path(cook.stdout.strip().splitlines()[-1])
parts = json.loads(parts_path.read_text())
styles, markup, client = parts["styles"], parts["markup"], parts["client"]
page = "\n".join(
    [
        "<!doctype html>",
        "<html lang=\"es\">",
        "<head>",
        "  <meta charset=\"utf-8\">",
        "  <meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">",
        "  <title>MyTickets · Agenda visual</title>",
        "  <style>",
        styles,
        "  </style>",
        "</head>",
        "<body>",
        markup[:-1] if markup.endswith("\n") else markup,
        "  <script>",
        client,
        "  </script>",
        "</body>",
        "</html>",
    ]
)

write(
    "ui/styles.js",
    """/**
 * Estilos de la aplicación embebida.
 *
 * El worker sirve un HTML único. Este módulo conserva el CSS original
 * (incluido el orden de las reglas y las media queries) para no alterar
 * layout, colores ni estados `[hidden]`.
 *
 * Responsabilidad: exponer la hoja de estilos de la UI.
 */""",
    js_string_module("styles", styles),
)
write(
    "ui/markup.js",
    """/**
 * Marcado HTML estático de la SPA.
 *
 * Incluye shell (sidebar, cabecera), contenedor `#app`, overlay de
 * modales y la pantalla de login. El contenido vivo se pinta después
 * desde el script del cliente.
 *
 * Responsabilidad: estructura HTML que envuelve la aplicación.
 */""",
    js_string_module("markup", markup),
)
write(
    "ui/client.js",
    """/**
 * JavaScript del navegador (SPA).
 *
 * Se inyecta como `<script>` clásico (no ESM) dentro de la página que
 * sirve el worker. Conserva el código original: estado, vistas,
 * formularios y llamadas a `/api/*`.
 *
 * Responsabilidad: interacción en el cliente.
 */""",
    js_string_module("clientScript", client),
)
write(
    "ui/page.js",
    """/**
 * Ensambla el documento HTML que el worker entrega en `GET /`.
 *
 * Concatena estilos, markup y script. Los strings de `ui/` son el valor
 * que Wrangler/esbuild producía a partir del template original, para
 * que el HTML servido (regex del cliente incluidas) sea el mismo.
 */""",
    '''import { styles } from "./styles.js";
import { markup } from "./markup.js";
import { clientScript } from "./client.js";

export const page = [
  "<!doctype html>",
  "<html lang=\\"es\\">",
  "<head>",
  "  <meta charset=\\"utf-8\\">",
  "  <meta name=\\"viewport\\" content=\\"width=device-width,initial-scale=1\\">",
  "  <title>MyTickets · Agenda visual</title>",
  "  <style>",
  styles,
  "  </style>",
  "</head>",
  "<body>",
  markup.replace(/\\n$/, ""),
  "  <script>",
  clientScript,
  "  </script>",
  "</body>",
  "</html>",
].join("\\n");
''',
)

# Verify page.js join strategy in Python the same way
page_from_join_parts = "\n".join(
    [
        "<!doctype html>",
        "<html lang=\"es\">",
        "<head>",
        "  <meta charset=\"utf-8\">",
        "  <meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">",
        "  <title>MyTickets · Agenda visual</title>",
        "  <style>",
        styles,
        "  </style>",
        "</head>",
        "<body>",
        markup[:-1] if markup.endswith("\n") else markup,
        "  <script>",
        client,
        "  </script>",
        "</body>",
        "</html>",
    ]
)
if page_from_join_parts != page:
    raise SystemExit(
        "join strategy mismatch: "
        f"orig={len(page)} join={len(page_from_join_parts)} markup_endswith_nl={markup.endswith(chr(10))!r}"
    )

write(
    "config/default-statuses.js",
    """/**
 * Catálogo de estados que se siembra en un workspace vacío.
 * Cada tupla es `[nombre, color, isDone, position]`.
 */""",
    exportify(slice_lines(487, 499)),
)
write(
    "config/default-attention-markers.js",
    """/**
 * Llamadores visuales por defecto del workspace.
 * Cada tupla es `[nombre, color, position]`.
 */""",
    exportify(slice_lines(501, 505)),
)
write(
    "config/session-cookie.js",
    """/**
 * Nombre de la cookie HttpOnly de sesión (`mt_session`).
 */""",
    exportify(slice_lines(665, 665)),
)
write(
    "config/default-user.js",
    """/**
 * Usuario semilla de la copia local.
 * `migrateUsers` lo crea si no existe y le asigna el histórico sin `user_id`.
 */""",
    exportify(slice_lines(666, 666)),
)
write(
    "config/constants.js",
    """/**
 * Barril de constantes de dominio y autenticación.
 */""",
    '''export { defaultStatuses } from "./default-statuses.js";
export { defaultAttentionMarkers } from "./default-attention-markers.js";
export { SESSION_COOKIE } from "./session-cookie.js";
export { DEFAULT_USER } from "./default-user.js";
''',
)

write(
    "lib/text.js",
    """/**
 * Normaliza un valor a texto recortado.
 * Cualquier tipo que no sea string se convierte en cadena vacía.
 */""",
    exportify(slice_lines(888, 888)),
)
write(
    "lib/number.js",
    """/**
 * Convierte un valor a número; si no es finito, devuelve 0.
 */""",
    exportify(slice_lines(889, 889)),
)
write(
    "lib/color.js",
    """/**
 * Acepta un color `#RRGGBB` o cae al gris por defecto del catálogo.
 */""",
    '''import { text } from "./text.js";

'''
    + exportify(slice_lines(890, 890)),
)
write(
    "lib/id-list.js",
    """/**
 * Lista de identificadores enteros positivos, sin duplicados y en orden de aparición.
 */""",
    exportify(slice_lines(891, 891)),
)
write(
    "lib/madrid-date.js",
    """/**
 * Fecha civil de hoy en la zona `Europe/Madrid`, formato `YYYY-MM-DD`.
 */""",
    exportify(slice_lines(892, 892)),
)
write(
    "lib/values.js",
    """/**
 * Barril de normalización de entradas del API.
 *
 * Todas las acciones reutilizan estas funciones (SRP: un solo sitio
 * para parsear textos, colores, ids y la fecha de Madrid).
 */""",
    '''export { text } from "./text.js";
export { number } from "./number.js";
export { color } from "./color.js";
export { idList } from "./id-list.js";
export { madridDateKey } from "./madrid-date.js";
''',
)

write(
    "db/status-key.js",
    """/**
 * Clave canónica de un estado: minúsculas y solo `[a-z0-9]`.
 * Se usa al unificar el catálogo en la migración de workflow.
 */""",
    exportify(slice_lines(507, 507)),
)
write(
    "db/migrations/workflow-statuses.js",
    """/**
 * Migración `workflow_statuses_v2`.
 * Unifica nombres duplicados y alinea colores/posiciones por defecto.
 */""",
    "import { defaultStatuses } from \"../../config/default-statuses.js\";\n"
    "import { statusKey } from \"../status-key.js\";\n\n"
    + exportify(slice_lines(509, 538)),
)
write(
    "db/migrations/ticket-completion.js",
    """/**
 * Añade `tickets.completed_at` y lo rellena para los que están en Done.
 */""",
    exportify(slice_lines(540, 550)),
)
write(
    "db/migrations/ticket-cancellation.js",
    """/**
 * Añade `tickets.cancelled_at` y lo rellena para los Cancelled.
 */""",
    exportify(slice_lines(552, 562)),
)
write(
    "db/migrations/ticket-avatars.js",
    """/**
 * Añade `tickets.avatar_key` si la columna no existe.
 */""",
    exportify(slice_lines(564, 569)),
)
write(
    "db/migrations/ticket-focus.js",
    """/**
 * Añade `tickets.is_focus` (migración `ticket_focus_v1`).
 */""",
    exportify(slice_lines(571, 580)),
)
write(
    "db/migrations/ticket-status-history.js",
    """/**
 * Crea el historial inicial (`baseline`) de estados por ticket.
 */""",
    exportify(slice_lines(615, 621)),
)
write(
    "db/migrations/standup-ticket-details.js",
    """/**
 * Columnas `ticket_key` y `ticket_title` en ítems de standup.
 */""",
    exportify(slice_lines(582, 590)),
)
write(
    "db/migrations/standup-item-sections.js",
    """/**
 * Columna `section` e índice por guía/sección/posición.
 */""",
    exportify(slice_lines(592, 598)),
)
write(
    "db/migrations/attention-markers.js",
    """/**
 * Columna `attention_marker_id` en tickets y siembra del catálogo.
 */""",
    "import { defaultAttentionMarkers } from \"../../config/default-attention-markers.js\";\n\n"
    + exportify(slice_lines(600, 613)),
)
write(
    "db/migrations/permanent-note-archive.js",
    """/**
 * Columna `archived_at` en notas permanentes.
 */""",
    exportify(slice_lines(623, 628)),
)
write(
    "db/migrations/permanent-note-attachments.js",
    """/**
 * Tabla e índice de adjuntos de notas permanentes.
 */""",
    exportify(slice_lines(630, 634)),
)
write(
    "db/migrations/billing-carryovers.js",
    """/**
 * Tabla de facturación pendiente (`billing_carryovers_v1`).
 */""",
    exportify(slice_lines(636, 644)),
)
write(
    "db/migrations/agenda-tasks.js",
    """/**
 * Tabla de agenda diaria (`agenda_tasks_v1`).
 */""",
    exportify(slice_lines(646, 653)),
)
write(
    "db/migrations/agenda-tasks-v2.js",
    """/**
 * Columna `copied_from_id` e índice único de copias entre días.
 */""",
    exportify(slice_lines(655, 662)),
)
write(
    "db/migrations/agenda-tasks-v3.js",
    """/**
 * Columna JSON `subtasks` en tareas de agenda.
 */""",
    exportify(slice_lines(663, 663)),
)
write(
    "db/migrations/agenda-task-comments.js",
    """/**
 * Comentarios de tareas/subtareas de agenda.
 */""",
    exportify(slice_lines(800, 800)),
)
write(
    "db/workspace.js",
    """/**
 * Columnas `user_id` y semilla de estados para un workspace nuevo.
 */""",
    "import { defaultStatuses } from \"../config/default-statuses.js\";\n\n"
    + exportify(slice_lines(729, 741)),
)
write(
    "auth/encoding.js",
    """/**
 * Conversión entre bytes y Base64 (Web APIs `btoa` / `atob`).
 */""",
    exportify(slice_lines(668, 679)),
)
write(
    "auth/token.js",
    """/**
 * Token de sesión: 32 bytes aleatorios en hex.
 */""",
    exportify(slice_lines(680, 683)),
)
write(
    "auth/password.js",
    """/**
 * Hash y verificación de contraseñas.
 *
 * PBKDF2-SHA-256, 120000 iteraciones, comparación en tiempo constante.
 * No conoce HTTP ni D1: solo bytes, hashes y salts.
 */""",
    '''import { bytesToB64, b64ToBytes } from "./encoding.js";

'''
    + exportify(slice_lines(684, 701)),
)
write(
    "auth/crypto.js",
    """/**
 * Barril de primitivas criptográficas (password, token, encoding).
 */""",
    '''export { bytesToB64, b64ToBytes } from "./encoding.js";
export { randomToken } from "./token.js";
export { timingSafeEqual, hashPassword, verifyPassword } from "./password.js";
''',
)
write(
    "auth/cookies.js",
    """/**
 * Lectura y escritura de la cookie HttpOnly de sesión.
 */""",
    "import { SESSION_COOKIE } from \"../config/session-cookie.js\";\n\n"
    + exportify(slice_lines(702, 717)),
)
write(
    "auth/identity.js",
    """/**
 * Identidad pública: correo, nombre y DTO seguro (sin hash ni token).
 */""",
    "import { text } from \"../lib/text.js\";\n\n"
    + exportify(slice_lines(718, 727)),
)
write(
    "auth/users.js",
    """/**
 * Alta de usuarios y rehash de credenciales.
 *
 * Depende de crypto (hash) y del semillado de estados por workspace.
 */""",
    '''import { text } from "../lib/text.js";
import { hashPassword } from "./password.js";
import { normalizeEmail, validEmail, publicUser } from "./identity.js";
import { seedWorkspace } from "../db/workspace.js";

'''
    + exportify(slice_lines(743, 755)),
)
write(
    "auth/session.js",
    """/**
 * Sesiones persistidas en D1: alta, lookup y limpieza de caducadas.
 */""",
    '''import { cookieValue } from "./cookies.js";
import { SESSION_COOKIE } from "../config/session-cookie.js";
import { randomToken } from "./token.js";

'''
    + exportify(slice_lines(757, 770)),
)
write(
    "db/migrations/users.js",
    """/**
 * Crea tablas de usuarios/sesiones, añade `user_id` a las entidades
 * y asigna el histórico existente al usuario semilla.
 */""",
    '''import { DEFAULT_USER } from "../../config/default-user.js";
import { hashPassword } from "../../auth/password.js";
import { addUserIdColumn, seedWorkspace } from "../workspace.js";

'''
    + exportify(slice_lines(772, 798)),
)
write(
    "db/setup.js",
    """/**
 * Bootstrap de esquema D1.
 *
 * Crea tablas si no existen, aplica migraciones en el mismo orden que
 * el monolito y memoíza el resultado por instancia de base (`WeakMap`)
 * para no repetir trabajo en el mismo isolate del Worker.
 */""",
    '''import { defaultStatuses } from "../config/default-statuses.js";
import { migrateWorkflowStatuses } from "./migrations/workflow-statuses.js";
import { migrateTicketCompletion } from "./migrations/ticket-completion.js";
import { migrateTicketCancellation } from "./migrations/ticket-cancellation.js";
import { migrateTicketAvatars } from "./migrations/ticket-avatars.js";
import { migrateTicketFocus } from "./migrations/ticket-focus.js";
import { migrateStandupTicketDetails } from "./migrations/standup-ticket-details.js";
import { migrateStandupItemSections } from "./migrations/standup-item-sections.js";
import { migrateAttentionMarkers } from "./migrations/attention-markers.js";
import { migrateTicketStatusHistory } from "./migrations/ticket-status-history.js";
import { migratePermanentNoteArchive } from "./migrations/permanent-note-archive.js";
import { migratePermanentNoteAttachments } from "./migrations/permanent-note-attachments.js";
import { migrateBillingCarryovers } from "./migrations/billing-carryovers.js";
import { migrateAgendaTasks } from "./migrations/agenda-tasks.js";
import { migrateAgendaTasksV2 } from "./migrations/agenda-tasks-v2.js";
import { migrateAgendaTasksV3 } from "./migrations/agenda-tasks-v3.js";
import { migrateAgendaTaskComments } from "./migrations/agenda-task-comments.js";
import { migrateUsers } from "./migrations/users.js";

'''
    + exportify(slice_lines(802, 847))
    + "\n"
    + exportify(slice_lines(849, 861)),
)
write(
    "domain/data.js",
    """/**
 * Lectura agregada del workspace del usuario autenticado.
 *
 * Un único batch SQL para pintar la SPA. Filtra siempre por `user_id`.
 */""",
    '''import { ensureSetup } from "../db/setup.js";
import { publicUser } from "../auth/identity.js";

'''
    + exportify(slice_lines(863, 886)),
)
write(
    "domain/links.js",
    """/**
 * Valida que un enlace de agenda apunte a un registro del mismo usuario.
 */""",
    exportify(slice_lines(893, 893)),
)
write(
    "domain/attachments.js",
    """/**
 * Subida de documentos de notas permanentes a R2 y metadatos en D1.
 */""",
    '''import { ensureSetup } from "../db/setup.js";
import { number } from "../lib/number.js";

'''
    + exportify(slice_lines(1201, 1235)),
)
write(
    "http/json-response.js",
    """/**
 * Respuesta JSON con cabeceras extra (cookie, status, etc.).
 */""",
    exportify(slice_lines(1237, 1240)),
)
write(
    "domain/actions/early-return.js",
    """/**
 * Señal interna para reproducir un `return;` desde `action()`.
 *
 * En el monolito, algunas ramas hacían `return;` (devuelve `undefined`)
 * en lugar de `return result`. Este símbolo conserva ese comportamiento.
 */""",
    "export const EARLY_RETURN = Symbol('action.earlyReturn');\n",
)

action_src = slice_lines(895, 1198)
branches = parse_action_branches(action_src)
print("action branches", [n for n, _ in branches])
expected = [
    "update_profile", "change_password", "create_user",
    "save_ticket", "set_ticket_labels", "set_ticket_attention", "set_ticket_focus",
    "set_ticket_avatar", "status", "comment", "edit_comment", "delete_comment", "delete_ticket",
    "save_agenda_task", "toggle_agenda_task", "update_agenda_subtasks", "save_agenda_subtask",
    "toggle_agenda_subtask", "move_agenda_subtask", "delete_agenda_subtask",
    "save_agenda_task_comment", "edit_agenda_task_comment", "delete_agenda_task_comment",
    "delete_agenda_task", "reorder_agenda_tasks", "copy_agenda_tasks",
    "save_reminder", "complete_reminder",
    "save_billing_ticket", "postpone_billing_ticket", "set_billing_invoiced",
    "save_permanent_note", "delete_permanent_note", "delete_permanent_note_attachment", "archive_permanent_note",
    "save_standup", "toggle_standup_item", "delete_standup",
    "save_status", "delete_status", "save_label", "delete_label", "save_attention_marker", "delete_attention_marker",
]
got = [n for n, _ in branches]
if got != expected:
    raise SystemExit(f"action names mismatch\n got={got}\n exp={expected}")

EARLY = {
    "status": (
        "if(Number(currentStatus.statusId)===statusId)return;",
        "if(Number(currentStatus.statusId)===statusId)return EARLY_RETURN;",
    ),
    "complete_reminder": (
        "if(reminder.isDone)return;",
        "if(reminder.isDone)return EARLY_RETURN;",
    ),
}

DOCS = {
    "update_profile": "Actualiza email y nombre del usuario autenticado.",
    "change_password": "Cambia la contraseña, invalida sesiones y emite una nueva cookie.",
    "create_user": "Alta de un usuario nuevo con workspace vacío (estados por defecto).",
    "save_ticket": "Crea o actualiza un ticket, labels e historial de estado.",
    "set_ticket_labels": "Sustituye las labels de un ticket.",
    "set_ticket_attention": "Asigna o quita el llamador visual de un ticket.",
    "set_ticket_focus": "Marca o desmarca un ticket como foco.",
    "set_ticket_avatar": "Asigna el avatar de un ticket.",
    "status": "Cambia el estado de un ticket y registra el historial.",
    "comment": "Añade un comentario a un ticket (con anti-duplicado de 12s).",
    "edit_comment": "Edita el texto de un comentario.",
    "delete_comment": "Borra un comentario.",
    "delete_ticket": "Borra un ticket y sus relaciones.",
    "save_agenda_task": "Crea o actualiza una tarea de la agenda diaria.",
    "toggle_agenda_task": "Marca o desmarca una tarea de agenda como hecha.",
    "update_agenda_subtasks": "Sustituye el JSON de subtareas.",
    "save_agenda_subtask": "Crea o edita una subtarea.",
    "toggle_agenda_subtask": "Marca o desmarca una subtarea.",
    "move_agenda_subtask": "Reordena una subtarea arriba o abajo.",
    "delete_agenda_subtask": "Borra una subtarea y sus comentarios.",
    "save_agenda_task_comment": "Comenta una tarea o subtarea de agenda.",
    "edit_agenda_task_comment": "Edita un comentario de agenda.",
    "delete_agenda_task_comment": "Borra un comentario de agenda.",
    "delete_agenda_task": "Borra una tarea de agenda y sus comentarios.",
    "reorder_agenda_tasks": "Guarda el orden de las tareas de un día.",
    "copy_agenda_tasks": "Copia tareas pendientes de un día a otro.",
    "save_reminder": "Crea un recordatorio con fecha.",
    "complete_reminder": "Marca un recordatorio como hecho (solo si la fecha ya llegó).",
    "save_billing_ticket": "Crea o actualiza un seguimiento de facturación.",
    "postpone_billing_ticket": "Aplaza el mes de invoice de un seguimiento.",
    "set_billing_invoiced": "Marca seguimientos como facturados o pendientes.",
    "save_permanent_note": "Crea o actualiza una nota permanente.",
    "delete_permanent_note": "Borra una nota y sus documentos en R2.",
    "delete_permanent_note_attachment": "Borra un documento concreto de una nota.",
    "archive_permanent_note": "Archiva o restaura una nota permanente.",
    "save_standup": "Crea o actualiza una guía de standup y sus ítems.",
    "toggle_standup_item": "Marca o desmarca un punto de standup.",
    "delete_standup": "Borra una guía y sus ítems.",
    "save_status": "Crea o actualiza un estado del workspace.",
    "delete_status": "Borra un estado si ningún ticket lo usa.",
    "save_label": "Crea o actualiza una label.",
    "delete_label": "Borra una label y sus asignaciones.",
    "save_attention_marker": "Crea o actualiza un llamador visual.",
    "delete_attention_marker": "Borra un llamador y lo quita de los tickets.",
}

handler_imports = []
map_lines = []
for name, inner in branches:
    if name in EARLY:
        old, new = EARLY[name]
        if old not in inner:
            raise SystemExit(f"early-return needle missing in {name}")
        inner = inner.replace(old, new, 1)
    body = imports_for(inner, name) + f"export async function handle(db, payload, filesBucket, uid, result) {{\n{inner}\n}}\n"
    write(
        f"domain/actions/{name}.js",
        f'''/**
 * Acción `{name}`.
 *
 * {DOCS[name]}
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */''',
        body,
    )
    ident = name
    handler_imports.append(f'import {{ handle as {ident} }} from "./{name}.js";')
    map_lines.append(f"  {ident},")

write(
    "domain/actions/index.js",
    """/**
 * Despacha `payload.action` al handler de su dominio.
 *
 * Abierto a extensión (OCP): añadir un archivo handler y registrarlo
 * en `handlers` no requiere tocar el resto de acciones.
 */""",
    "import { ensureSetup } from \"../../db/setup.js\";\n"
    "import { text } from \"../../lib/text.js\";\n"
    "import { EARLY_RETURN } from \"./early-return.js\";\n"
    + "\n".join(handler_imports)
    + "\n\nconst handlers = {\n"
    + "\n".join(map_lines)
    + "\n};\n\n"
    + '''export async function action(db, payload, filesBucket, userId) {
  await ensureSetup(db);
  const name = text(payload.action);
  const result = {};
  const uid = Number(userId);
  const handler = handlers[name];
  if (!handler) throw new Error("Acción no reconocida.");
  const early = await handler(db, payload, filesBucket, uid, result);
  if (early === EARLY_RETURN) return;
  return result;
}
''',
)

write(
    "http/router.js",
    """/**
 * Enrutador HTTP del Worker.
 *
 * Traduce pathname + método a casos de uso: página, login, datos y adjuntos.
 * El cuerpo de `fetch` es el del monolito; solo se han convertido las
 * dependencias globales en imports.
 */""",
    '''import { page } from "../ui/page.js";
import { ensureSetup } from "../db/setup.js";
import { getData } from "../domain/data.js";
import { action } from "../domain/actions/index.js";
import { savePermanentNoteAttachments } from "../domain/attachments.js";
import { sessionUser, createSession } from "../auth/session.js";
import { cookieValue, sessionCookie, clearSessionCookie } from "../auth/cookies.js";
import { SESSION_COOKIE } from "../config/session-cookie.js";
import { normalizeEmail, publicUser } from "../auth/identity.js";
import { verifyPassword } from "../auth/password.js";
import { number } from "../lib/number.js";
import { jsonResponse } from "./json-response.js";

'''
    + slice_lines(1242, 1317),
)

write(
    "index.js",
    """/**
 * Punto de entrada del Worker.
 *
 * Wrangler carga este módulo (`main` en wrangler.toml). Solo reexporta
 * el enrutador: las reglas de negocio viven en `domain/`, `auth/` y `db/`.
 */""",
    'export { default } from "./http/router.js";\n',
)

print("done", len(branches), "actions")
