/**
 * Acción `save_permanent_note`.
 *
 * Crea o actualiza una nota permanente.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),title=text(payload.title).slice(0,100),content=text(payload.content).slice(0,5000),hasSelectedFiles=payload.hasFiles===true;
    if(id){
      const note=await db.prepare("SELECT id FROM permanent_notes WHERE id=? AND user_id=?").bind(id,uid).first();
      if(!note)throw new Error("La nota permanente ya no existe.");
      const attachmentCount=await db.prepare("SELECT COUNT(*) total FROM permanent_note_attachments WHERE note_id=?").bind(id).first();
      if(!content&&!hasSelectedFiles&&!Number(attachmentCount?.total))throw new Error("Escribe una anotación o adjunta al menos un documento.");
      await db.prepare("UPDATE permanent_notes SET title=?,content=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?").bind(title,content,id,uid).run();
      result.savedNoteId=id;
    }else{
      if(!content&&!hasSelectedFiles)throw new Error("Escribe una anotación o adjunta al menos un documento.");
      const row=await db.prepare("INSERT INTO permanent_notes(title,content,user_id) VALUES(?,?,?) RETURNING id").bind(title,content,uid).first();
      result.savedNoteId=number(row?.id);
    }
  
}
