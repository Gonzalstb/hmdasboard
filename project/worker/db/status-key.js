/**
 * Clave canónica de un estado: minúsculas y solo `[a-z0-9]`.
 * Se usa al unificar el catálogo en la migración de workflow.
 */

export const statusKey = value => String(value || "").toLowerCase().replace(/[^a-z0-9]/g, "");
