/**
 * Usuario semilla leído desde variables de entorno del Worker.
 *
 * En local Wrangler carga `project/.dev.vars` (no versionado).
 * Variables: SEED_USER_EMAIL, SEED_USER_NAME, SEED_USER_PASSWORD.
 */

export function seedUserFromEnv(env = {}) {
  const email = String(env.SEED_USER_EMAIL || "").trim().toLowerCase();
  const name = String(env.SEED_USER_NAME || "").trim();
  const password = String(env.SEED_USER_PASSWORD || "");
  if (!email || !name || !password) return null;
  return { email, name, password };
}
