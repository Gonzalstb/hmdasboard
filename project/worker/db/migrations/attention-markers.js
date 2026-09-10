/**
 * Columna `attention_marker_id` en tickets y siembra del catálogo.
 */

import { defaultAttentionMarkers } from "../../config/default-attention-markers.js";

export async function migrateAttentionMarkers(db) {
  const columns = (await db.prepare("PRAGMA table_info(tickets)").all()).results;
  if (!columns.some(column => column.name === "attention_marker_id")) {
    await db.prepare("ALTER TABLE tickets ADD COLUMN attention_marker_id INTEGER").run();
  }
  const migrationKey = "attention_markers_v1";
  const applied = await db.prepare("SELECT value FROM app_meta WHERE key=?").bind(migrationKey).first();
  if (applied?.value === "done") return;
  for (const [name, markerColor, position] of defaultAttentionMarkers) {
    await db.prepare("INSERT INTO attention_markers(name,color,position) SELECT ?,?,? WHERE NOT EXISTS (SELECT 1 FROM attention_markers WHERE lower(name)=lower(?))")
      .bind(name,markerColor,position,name).run();
  }
  await db.prepare("INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)").bind(migrationKey,"done").run();
}
