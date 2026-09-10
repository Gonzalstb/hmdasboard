/**
 * Carga variables locales (`.dev.vars` / `.env`) sin pisar las del panel
 * de Hostinger (`process.env` ya definido gana).
 *
 * Wrangler solo lee `.dev.vars` en `wrangler dev`. Este módulo replica
 * esa inyección para el runtime Node.
 */

import { existsSync, readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const serverDir = dirname(fileURLToPath(import.meta.url));
export const projectRoot = resolve(serverDir, "..");
export const repoRoot = resolve(projectRoot, "..");

function parseEnvFile(path) {
  if (!existsSync(path)) return;
  const text = readFileSync(path, "utf8");
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.trim();
    if (!line || line.startsWith("#")) continue;
    const eq = line.indexOf("=");
    if (eq < 1) continue;
    const key = line.slice(0, eq).trim();
    let value = line.slice(eq + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }
    if (process.env[key] == null || process.env[key] === "") process.env[key] = value;
  }
}

export function loadEnv() {
  parseEnvFile(resolve(projectRoot, ".dev.vars"));
  parseEnvFile(resolve(repoRoot, ".dev.vars"));
  parseEnvFile(resolve(projectRoot, ".env"));
  parseEnvFile(resolve(repoRoot, ".env"));
}

export function dataDir() {
  if (process.env.DATA_DIR) return resolve(process.env.DATA_DIR);
  // Hostinger sustituye hbuilds/current en cada deploy: SQLite y adjuntos
  // tienen que vivir fuera de esa instantánea.
  const cwd = process.cwd();
  if (cwd.includes("/hbuilds/") || cwd.includes("\\hbuilds\\")) {
    return resolve(cwd, "../../../mytickets-data");
  }
  const repoData = resolve(repoRoot, "data");
  if (existsSync(repoData)) return repoData;
  return resolve(projectRoot, "data");
}
