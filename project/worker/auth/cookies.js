/**
 * Lectura y escritura de la cookie HttpOnly de sesión.
 */

import { SESSION_COOKIE } from "../config/session-cookie.js";

export function cookieValue(request, name) {
  const header = request.headers.get("Cookie") || "";
  const parts = header.split(";");
  for (const part of parts) {
    const trimmed = part.trim();
    if (trimmed.startsWith(name + "=")) return decodeURIComponent(trimmed.slice(name.length + 1));
  }
  return "";
}
export function sessionCookie(token, maxAge = 60 * 60 * 24 * 30) {
  const parts = [`${SESSION_COOKIE}=${encodeURIComponent(token)}`, "Path=/", "HttpOnly", "SameSite=Lax", `Max-Age=${maxAge}`];
  return parts.join("; ");
}
export function clearSessionCookie() {
  return `${SESSION_COOKIE}=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0`;
}