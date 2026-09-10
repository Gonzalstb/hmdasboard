/**
 * Barril de normalización de entradas del API.
 *
 * Todas las acciones reutilizan estas funciones (SRP: un solo sitio
 * para parsear textos, colores, ids y la fecha de Madrid).
 */

export { text } from "./text.js";
export { number } from "./number.js";
export { color } from "./color.js";
export { idList } from "./id-list.js";
export { madridDateKey } from "./madrid-date.js";
