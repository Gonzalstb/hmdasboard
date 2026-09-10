#!/usr/bin/env node
/**
 * Extrae estilos, markup y script del template original *después* de que
 * esbuild lo cueza (igual que Wrangler). Así las regex del cliente (`\d`,
 * `https?:\/\/`, etc.) coinciden con las que servía el monolito.
 */
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { spawnSync } from "node:child_process";
import { tmpdir } from "node:os";
import { join } from "node:path";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const monolith = resolve(root, ".refactor-backup/index.monolith.js");
const src = readFileSync(monolith, "utf8");
const snippet = src.split("\nconst defaultStatuses")[0].replace("const page =", "export const page =", 1);
const dir = join(tmpdir(), "mytickets-cook-page");
mkdirSync(dir, { recursive: true });
const inFile = join(dir, "page-src.js");
const outFile = join(dir, "page-out.js");
writeFileSync(inFile, snippet);
const built = spawnSync("npx", ["--yes", "esbuild", inFile, "--format=esm", `--outfile=${outFile}`, "--platform=neutral"], {
  cwd: root,
  encoding: "utf8",
});
if (built.status !== 0) {
  console.error(built.stdout, built.stderr);
  process.exit(built.status || 1);
}
const { page: cooked } = await import(outFile + "?t=" + Date.now());

const styles = cooked.slice(cooked.indexOf("  <style>\n") + "  <style>\n".length, cooked.indexOf("\n  </style>"));
const markup = cooked.slice(cooked.indexOf("<body>\n") + "<body>\n".length, cooked.indexOf("  <script>\n"));
const client = cooked.slice(cooked.indexOf("  <script>\n") + "  <script>\n".length, cooked.indexOf("\n  </script>"));

const rebuilt = [
  "<!doctype html>",
  '<html lang="es">',
  "<head>",
  '  <meta charset="utf-8">',
  '  <meta name="viewport" content="width=device-width,initial-scale=1">',
  "  <title>MyTickets · Agenda visual</title>",
  "  <style>",
  styles,
  "  </style>",
  "</head>",
  "<body>",
  markup.endsWith("\n") ? markup.slice(0, -1) : markup,
  "  <script>",
  client,
  "  </script>",
  "</body>",
  "</html>",
].join("\n");
if (rebuilt !== cooked) {
  console.error("rebuilt page does not match esbuild-cooked template");
  process.exit(1);
}
try {
  new Function(client);
} catch (error) {
  console.error("client script is not valid JS:", error.message);
  process.exit(1);
}

writeFileSync(join(dir, "parts.json"), JSON.stringify({ styles, markup, client }));
console.log(join(dir, "parts.json"));
