/**
 * Valida que un enlace de agenda apunte a un registro del mismo usuario.
 */

export async function validateAgendaLink(db,linkType,linkId,userId){if(!linkType)return null;const tables={ticket:"tickets",reminder:"reminders",note:"permanent_notes",standup:"standup_guides"},table=tables[linkType];if(!table||!linkId)throw new Error("Selecciona un elemento válido para enlazar.");if(!await db.prepare("SELECT id FROM "+table+" WHERE id=? AND user_id=?").bind(linkId,userId).first())throw new Error("El elemento enlazado ya no existe.");return linkId}
