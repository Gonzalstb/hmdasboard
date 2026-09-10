/**
 * Entrada Node para Hostinger («Sube tu código» / Node.js).
 *
 * No reescribe reglas de negocio: instancia SQLite + disco, arma `env`
 * como el Worker (DB, FILES, SEED_USER_*) y delega en `router.fetch`.
 */

import { mkdirSync } from "node:fs";
import { createServer } from "node:http";
import { join } from "node:path";
import worker from "../worker/index.js";
import { openD1 } from "./d1-sqlite.js";
import { dataDir, loadEnv } from "./env.js";
import { fetchToNodeResponse, nodeToFetchRequest } from "./fetch-adapter.js";
import { openR2 } from "./r2-fs.js";

loadEnv();

const dir = dataDir();
mkdirSync(dir, { recursive: true });
mkdirSync(join(dir, "files"), { recursive: true });

const env = {
  DB: openD1(join(dir, process.env.SQLITE_FILE || "mytickets.sqlite")),
  FILES: openR2(join(dir, "files")),
  SEED_USER_EMAIL: process.env.SEED_USER_EMAIL,
  SEED_USER_NAME: process.env.SEED_USER_NAME,
  SEED_USER_PASSWORD: process.env.SEED_USER_PASSWORD,
};

const port = Number(process.env.PORT) || 3000;
const host = process.env.HOST || "0.0.0.0";

function headerValue(value) {
  if (value == null) return "";
  const raw = Array.isArray(value) ? value[0] : value;
  return String(raw).split(",")[0].trim();
}

function requestOrigin(req) {
  const proto = headerValue(req.headers["x-forwarded-proto"]) || "http";
  const host = headerValue(req.headers["x-forwarded-host"]) || headerValue(req.headers.host) || "localhost";
  return `${proto}://${host}`;
}

const server = createServer(async (req, res) => {
  try {
    const request = await nodeToFetchRequest(req, requestOrigin(req));
    const response = await worker.fetch(request, env);
    await fetchToNodeResponse(response, res);
  } catch (error) {
    console.error(error);
    if (!res.headersSent) {
      res.statusCode = 500;
      res.setHeader("content-type", "application/json; charset=utf-8");
      res.end(JSON.stringify({ error: "Error interno del servidor." }));
    } else {
      res.end();
    }
  }
});

function shutdown() {
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(0), 5000).unref();
}

process.on("SIGTERM", shutdown);
process.on("SIGINT", shutdown);

server.listen(port, host, () => {
  console.log(`MyTickets (Node/Hostinger) en http://${host}:${port}`);
});
