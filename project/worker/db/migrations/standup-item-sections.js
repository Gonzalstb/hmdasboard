/**
 * Columna `section` e índice por guía/sección/posición.
 */

export async function migrateStandupItemSections(db) {
  const columns = (await db.prepare("PRAGMA table_info(standup_items)").all()).results;
  if (!columns.some(column => column.name === "section")) {
    await db.prepare("ALTER TABLE standup_items ADD COLUMN section TEXT NOT NULL DEFAULT 'points'").run();
  }
  await db.prepare("CREATE INDEX IF NOT EXISTS standup_items_guide_section_position_idx ON standup_items(guide_id,section,position,id)").run();
}
