/**
 * Sesiones persistidas en D1: alta, lookup y limpieza de caducadas.
 */

import { cookieValue } from "./cookies.js";
import { SESSION_COOKIE } from "../config/session-cookie.js";
import { randomToken } from "./token.js";

export async function createSession(db, userId) {
  await db.prepare("DELETE FROM sessions WHERE expires_at < datetime('now')").run();
  const token = randomToken();
  await db.prepare("INSERT INTO sessions(user_id,token,expires_at) VALUES(?,?,datetime('now','+30 days'))").bind(userId, token).run();
  return token;
}

export async function sessionUser(db, request) {
  const token = cookieValue(request, SESSION_COOKIE);
  if (!token) return null;
  await db.prepare("DELETE FROM sessions WHERE expires_at < datetime('now')").run();
  return db.prepare("SELECT u.id,u.email,u.name,u.created_at createdAt FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND s.expires_at >= datetime('now')")
    .bind(token).first();
}
