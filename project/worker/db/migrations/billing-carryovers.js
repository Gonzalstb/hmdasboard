/**
 * Tabla de facturación pendiente (`billing_carryovers_v1`).
 */

export async function migrateBillingCarryovers(db) {
  const migrationKey="billing_carryovers_v1",applied=await db.prepare("SELECT value FROM app_meta WHERE key=?").bind(migrationKey).first();
  if(applied?.value==="done")return;
  await db.prepare("CREATE TABLE IF NOT EXISTS billing_carryovers (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, ticket_key TEXT NOT NULL DEFAULT '', ticket_title TEXT NOT NULL DEFAULT '', jira_url TEXT NOT NULL DEFAULT '', worked_month TEXT NOT NULL, invoice_month TEXT NOT NULL, minutes INTEGER NOT NULL DEFAULT 0, client TEXT NOT NULL DEFAULT '', project TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '', defer_reason TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'pending', invoiced_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)").run();
  await db.prepare("CREATE UNIQUE INDEX IF NOT EXISTS idx_billing_carryovers_ticket_worked_unique ON billing_carryovers(ticket_id,worked_month)").run();
  await db.prepare("CREATE INDEX IF NOT EXISTS idx_billing_carryovers_status_invoice ON billing_carryovers(status,invoice_month)").run();
  await db.prepare("PRAGMA optimize").run();
  await db.prepare("INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)").bind(migrationKey,"done").run();
}
