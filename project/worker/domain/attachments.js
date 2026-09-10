/**
 * Subida de documentos de notas permanentes a R2 y metadatos en D1.
 */

import { ensureSetup } from "../db/setup.js";
import { number } from "../lib/number.js";

export const attachmentMimeByExtension = new Map([
  ["pdf","application/pdf"],["doc","application/msword"],["docx","application/vnd.openxmlformats-officedocument.wordprocessingml.document"],
  ["xls","application/vnd.ms-excel"],["xlsx","application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"],
  ["ppt","application/vnd.ms-powerpoint"],["pptx","application/vnd.openxmlformats-officedocument.presentationml.presentation"],
  ["txt","text/plain; charset=utf-8"],["csv","text/csv; charset=utf-8"],["png","image/png"],["jpg","image/jpeg"],["jpeg","image/jpeg"],["webp","image/webp"],
]);
export const safeAttachmentName = value => String(value||"documento").replace(/[\\/\u0000-\u001f\u007f]/g,"_").trim().slice(0,180)||"documento";

export async function savePermanentNoteAttachments(db, bucket, formData, userId) {
  await ensureSetup(db);
  if(!bucket)throw new Error("El almacenamiento de documentos no está disponible.");
  const noteId=number(formData.get("noteId")),note=await db.prepare("SELECT id,archived_at archivedAt FROM permanent_notes WHERE id=? AND user_id=?").bind(noteId,userId).first();
  if(!note)throw new Error("La nota permanente ya no existe.");
  if(note.archivedAt)throw new Error("Restaura la nota antes de añadir documentos.");
  const files=formData.getAll("files").filter(file=>file&&typeof file==="object"&&typeof file.stream==="function");
  if(!files.length)throw new Error("Selecciona al menos un documento.");
  if(files.length>5)throw new Error("Puedes adjuntar un máximo de 5 documentos cada vez.");
  const saved=[];
  try{
    for(const file of files){
      if(!Number(file.size)||Number(file.size)>10*1024*1024)throw new Error("Cada documento debe ocupar entre 1 byte y 10 MB.");
      const fileName=safeAttachmentName(file.name),extension=(fileName.split(".").pop()||"").toLowerCase(),contentType=attachmentMimeByExtension.get(extension);
      if(!contentType)throw new Error("El formato de "+fileName+" no está permitido.");
      const storageKey="permanent-notes/"+noteId+"/"+crypto.randomUUID();
      await bucket.put(storageKey,file.stream(),{httpMetadata:{contentType},customMetadata:{fileName}});
      await db.prepare("INSERT INTO permanent_note_attachments(note_id,storage_key,file_name,content_type,size_bytes) VALUES(?,?,?,?,?)").bind(noteId,storageKey,fileName,contentType,Number(file.size)).run();
      saved.push(storageKey);
    }
    const suggestedTitle=safeAttachmentName(files[0]?.name).replace(/\.[^.]+$/,'').trim().slice(0,100)||"Documento";
    await db.prepare("UPDATE permanent_notes SET title=CASE WHEN trim(title)='' THEN ? ELSE title END,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?").bind(suggestedTitle,noteId,userId).run();
  }catch(error){
    await Promise.all(saved.map(async key=>{await bucket.delete(key);await db.prepare("DELETE FROM permanent_note_attachments WHERE storage_key=?").bind(key).run()}));
    throw error;
  }
}
