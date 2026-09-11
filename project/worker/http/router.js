/**
 * Enrutador HTTP del Worker.
 *
 * Traduce pathname + método a casos de uso: página, login, datos y adjuntos.
 * El cuerpo de `fetch` es el del monolito; solo se han convertido las
 * dependencias globales en imports.
 */

import { page } from "../ui/page.js";
import { ensureSetup } from "../db/setup.js";
import { getData } from "../domain/data.js";
import { action } from "../domain/actions/index.js";
import { savePermanentNoteAttachments } from "../domain/attachments.js";
import { sessionUser, createSession } from "../auth/session.js";
import { cookieValue, sessionCookie, clearSessionCookie } from "../auth/cookies.js";
import { SESSION_COOKIE } from "../config/session-cookie.js";
import { normalizeEmail, publicUser } from "../auth/identity.js";
import { verifyPassword } from "../auth/password.js";
import { number } from "../lib/number.js";
import { jsonResponse } from "./json-response.js";

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const noStore = {
      "cache-control": "no-store, no-cache, must-revalidate, max-age=0",
      "cdn-cache-control": "no-store",
      "pragma": "no-cache",
      "expires": "0",
    };
    if (url.pathname === "/") return new Response(page, { headers: { ...noStore, "content-type": "text/html; charset=utf-8" } });
    if (url.pathname === "/api/login") {
      try {
        if (request.method !== "POST") return new Response("Method not allowed", { status: 405, headers: noStore });
        if (!env.DB) throw new Error("La base de datos no está disponible.");
        await ensureSetup(env.DB, env);
        const payload = await request.json();
        const email = normalizeEmail(payload.email);
        const user = await env.DB.prepare("SELECT id,email,name,role,password_hash passwordHash,password_salt passwordSalt FROM users WHERE email=?").bind(email).first();
        if (!user || !(await verifyPassword(String(payload.password || ""), user.passwordHash, user.passwordSalt))) {
          return jsonResponse({ error: "Correo o contraseña incorrectos." }, noStore, {}, 401);
        }
        const token = await createSession(env.DB, Number(user.id));
        return jsonResponse({ user: publicUser(user) }, noStore, { "set-cookie": sessionCookie(token) });
      } catch (error) {
        return jsonResponse({ error: error instanceof Error ? error.message : "No se pudo iniciar sesión." }, noStore, {}, 400);
      }
    }
    if (url.pathname === "/api/logout") {
      if (request.method !== "POST") return new Response("Method not allowed", { status: 405, headers: noStore });
      const token = cookieValue(request, SESSION_COOKIE);
      if (token && env.DB) await env.DB.prepare("DELETE FROM sessions WHERE token=?").bind(token).run();
      return jsonResponse({ ok: true }, noStore, { "set-cookie": clearSessionCookie() });
    }
    if (!env.DB) return jsonResponse({ error: "La base de datos no está disponible." }, noStore, {}, 400);
    await ensureSetup(env.DB, env);
    const user = await sessionUser(env.DB, request);
    if (!user) return jsonResponse({ error: "Debes iniciar sesión." }, noStore, {}, 401);
    if (url.pathname === "/api/permanent-note-attachments") {
      try {
        if(request.method!=="POST")return new Response("Method not allowed",{status:405,headers:noStore});
        await savePermanentNoteAttachments(env.DB,env.FILES,await request.formData(),Number(user.id));
        return Response.json(await getData(env.DB, Number(user.id)),{headers:noStore});
      } catch (error) {
        return Response.json({error:error instanceof Error?error.message:"No se pudieron guardar los documentos."},{status:400,headers:noStore});
      }
    }
    const attachmentMatch=url.pathname.match(/^\/api\/permanent-note-attachments\/(\d+)$/);
    if(attachmentMatch){
      try{
        if(request.method!=="GET")return new Response("Method not allowed",{status:405,headers:noStore});
        if(!env.FILES)throw new Error("El almacenamiento de documentos no está disponible.");
        const attachment=await env.DB.prepare("SELECT a.storage_key storageKey,a.file_name fileName,a.content_type contentType FROM permanent_note_attachments a JOIN permanent_notes n ON n.id=a.note_id WHERE a.id=? AND n.user_id=?").bind(number(attachmentMatch[1]),Number(user.id)).first();
        if(!attachment)return new Response("Documento no encontrado",{status:404,headers:noStore});
        const object=await env.FILES.get(String(attachment.storageKey));
        if(!object)return new Response("Documento no encontrado",{status:404,headers:noStore});
        const inline=url.searchParams.get("download")!=="1"&&/^(application\/pdf|image\/|text\/)/i.test(String(attachment.contentType)),headers=new Headers(noStore);
        headers.set("content-type",String(attachment.contentType)||"application/octet-stream");
        headers.set("content-length",String(object.size));
        headers.set("content-disposition",(inline?"inline":"attachment")+"; filename*=UTF-8''"+encodeURIComponent(String(attachment.fileName)));
        headers.set("x-content-type-options","nosniff");
        return new Response(object.body,{headers});
      }catch(error){return Response.json({error:error instanceof Error?error.message:"No se pudo abrir el documento."},{status:400,headers:noStore})}
    }
    if (url.pathname === "/api/data") {
      try {
        const result=request.method==="POST"?await action(env.DB,await request.json(),env.FILES,Number(user.id)):{};
        const headers={...noStore};
        if(result.newSession){headers["set-cookie"]=sessionCookie(result.newSession);delete result.newSession}
        return Response.json({...await getData(env.DB, Number(user.id)),...result}, { headers });
      } catch (error) {
        return Response.json({ error: error instanceof Error ? error.message : "No se pudo guardar." }, { status: error?.status || 400, headers: noStore });
      }
    }
    return new Response("Not found", { status: 404 });
  },
};
