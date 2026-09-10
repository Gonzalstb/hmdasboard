/**
 * Punto de entrada del Worker.
 *
 * Wrangler carga este módulo (`main` en wrangler.toml). Solo reexporta
 * el enrutador: las reglas de negocio viven en `domain/`, `auth/` y `db/`.
 */

export { default } from "./http/router.js";
