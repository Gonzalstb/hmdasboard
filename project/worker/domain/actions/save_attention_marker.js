/**
 * Acción `save_attention_marker`.
 *
 * Crea o actualiza un llamador visual.
 *
 * Firma: `(db, payload, filesBucket, uid, result)`. Mutar `result`
 * cuando la respuesta deba incluir datos extra (`savedNoteId`, `newSession`).
 */

import { text, number, color } from "../../lib/values.js";

export async function handle(db, payload, filesBucket, uid, result) {
    const id=number(payload.id),markerName=text(payload.name);
    if(!markerName)throw new Error("Escribe qué significa este llamador.");
    if(id){
      if(!await db.prepare("SELECT id FROM attention_markers WHERE id=? AND user_id=?").bind(id,uid).first())throw new Error("El llamador ya no existe.");
      await db.prepare("UPDATE attention_markers SET name=?,color=? WHERE id=? AND user_id=?").bind(markerName,color(payload.color),id,uid).run();
    }else{
      const max=await db.prepare("SELECT COALESCE(MAX(position),-1)+1 position FROM attention_markers WHERE user_id=?").bind(uid).first();
      await db.prepare("INSERT INTO attention_markers(name,color,position,user_id) VALUES(?,?,?,?)").bind(markerName,color(payload.color),Number(max.position),uid).run();
    }
  
}
