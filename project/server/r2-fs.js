/**
 * Adaptador R2 sobre el sistema de archivos.
 *
 * `put` / `get` / `delete` con las mismas claves que el Worker usaba en
 * el bucket (`permanent-notes/<noteId>/<uuid>`).
 */

import { createReadStream } from "node:fs";
import { mkdir, stat, unlink, writeFile } from "node:fs/promises";
import { dirname, join, normalize, relative, resolve } from "node:path";
import { Readable } from "node:stream";

async function toBuffer(body) {
  if (body == null) return Buffer.alloc(0);
  if (Buffer.isBuffer(body)) return body;
  if (body instanceof Uint8Array) return Buffer.from(body);
  if (typeof body.arrayBuffer === "function") return Buffer.from(await body.arrayBuffer());
  if (typeof body.getReader === "function") {
    const reader = body.getReader();
    const chunks = [];
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      chunks.push(Buffer.from(value));
    }
    return Buffer.concat(chunks);
  }
  const chunks = [];
  for await (const chunk of body) chunks.push(Buffer.from(chunk));
  return Buffer.concat(chunks);
}

function filePathFor(root, key) {
  const relativeKey = String(key || "").replace(/^\/+/, "");
  const full = resolve(root, relativeKey);
  const rel = relative(root, full);
  if (rel.startsWith("..") || normalize(rel) === "..") {
    throw new Error("Clave de almacenamiento no válida.");
  }
  return full;
}

export function openR2(root) {
  return {
    root,
    async put(key, body) {
      const target = filePathFor(root, key);
      await mkdir(dirname(target), { recursive: true });
      await writeFile(target, await toBuffer(body));
    },
    async get(key) {
      const target = filePathFor(root, key);
      try {
        const info = await stat(target);
        if (!info.isFile()) return null;
        const nodeStream = createReadStream(target);
        return {
          size: info.size,
          body: Readable.toWeb(nodeStream),
        };
      } catch (error) {
        if (error && error.code === "ENOENT") return null;
        throw error;
      }
    },
    async delete(key) {
      const target = filePathFor(root, key);
      try {
        await unlink(target);
      } catch (error) {
        if (error && error.code !== "ENOENT") throw error;
      }
    },
  };
}
