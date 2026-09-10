/**
 * Puente HTTP Node ↔ Fetch API.
 *
 * Convierte IncomingMessage en `Request` y `Response` en la respuesta
 * nativa, para reutilizar `worker/http/router.js` sin cambios.
 */

import { Buffer } from "node:buffer";

export async function nodeToFetchRequest(req, origin) {
  const host = req.headers.host || "localhost";
  const url = new URL(req.url || "/", origin || `http://${host}`);
  const skip = new Set(["connection", "keep-alive", "proxy-connection", "transfer-encoding", "upgrade"]);
  const headers = new Headers();
  for (const [name, value] of Object.entries(req.headers)) {
    if (value == null) continue;
    if (skip.has(String(name).toLowerCase())) continue;
    if (Array.isArray(value)) headers.set(name, value.join(", "));
    else headers.set(name, String(value));
  }

  const method = (req.method || "GET").toUpperCase();
  const hasBody = method !== "GET" && method !== "HEAD";
  let body;
  if (hasBody) {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    body = Buffer.concat(chunks);
  }

  return new Request(url, {
    method,
    headers,
    body,
    duplex: hasBody ? "half" : undefined,
  });
}

export async function fetchToNodeResponse(webResponse, res) {
  res.statusCode = webResponse.status;
  const setCookies = typeof webResponse.headers.getSetCookie === "function"
    ? webResponse.headers.getSetCookie()
    : [];
  webResponse.headers.forEach((value, name) => {
    if (name.toLowerCase() === "set-cookie") return;
    res.setHeader(name, value);
  });
  if (setCookies.length) res.setHeader("set-cookie", setCookies);

  if (!webResponse.body) {
    res.end();
    return;
  }
  const buffer = Buffer.from(await webResponse.arrayBuffer());
  res.end(buffer);
}
