/**
 * Hash y verificación de contraseñas.
 *
 * PBKDF2-SHA-256, 120000 iteraciones, comparación en tiempo constante.
 * No conoce HTTP ni D1: solo bytes, hashes y salts.
 */

import { bytesToB64, b64ToBytes } from "./encoding.js";

export function timingSafeEqual(a, b) {
  const left = String(a || ""), right = String(b || "");
  const len = Math.max(left.length, right.length);
  let diff = left.length ^ right.length;
  for (let i = 0; i < len; i++) diff |= (left.charCodeAt(i) || 0) ^ (right.charCodeAt(i) || 0);
  return diff === 0;
}
export async function hashPassword(password, saltB64) {
  const enc = new TextEncoder();
  const salt = saltB64 ? b64ToBytes(saltB64) : crypto.getRandomValues(new Uint8Array(16));
  const key = await crypto.subtle.importKey("raw", enc.encode(String(password)), "PBKDF2", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits({ name: "PBKDF2", hash: "SHA-256", salt, iterations: 120000 }, key, 256);
  return { hash: bytesToB64(bits), salt: bytesToB64(salt) };
}
export async function verifyPassword(password, hash, salt) {
  const next = await hashPassword(password, salt);
  return timingSafeEqual(next.hash, hash);
}