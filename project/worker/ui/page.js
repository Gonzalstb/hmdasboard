/**
 * Ensambla el documento HTML que el worker entrega en `GET /`.
 *
 * Concatena estilos, markup y script. Los strings de `ui/` son el valor
 * que Wrangler/esbuild producía a partir del template original, para
 * que el HTML servido (regex del cliente incluidas) sea el mismo.
 */

import { styles } from "./styles.js";
import { markup } from "./markup.js";
import { clientScript } from "./client.js";

export const page = [
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
  markup.replace(/\n$/, ""),
  "  <script>",
  clientScript,
  "  </script>",
  "</body>",
  "</html>",
].join("\n");
