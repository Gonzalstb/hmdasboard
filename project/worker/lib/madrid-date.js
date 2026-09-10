/**
 * Fecha civil de hoy en la zona `Europe/Madrid`, formato `YYYY-MM-DD`.
 */

export const madridDateKey = () => new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Madrid", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());
