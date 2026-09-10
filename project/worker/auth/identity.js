/**
 * Identidad pública: correo, nombre y DTO seguro (sin hash ni token).
 */

import { text } from "../lib/text.js";

export function normalizeEmail(value) {
  return text(value).toLowerCase();
}
export function validEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalizeEmail(value));
}
export function publicUser(row) {
  if (!row) return null;
  return { id: Number(row.id), email: String(row.email), name: String(row.name || ""), createdAt: row.createdAt || row.created_at || null };
}
