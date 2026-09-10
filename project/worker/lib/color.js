/**
 * Acepta un color `#RRGGBB` o cae al gris por defecto del catálogo.
 */

import { text } from "./text.js";

export const color = value => /^#[0-9a-f]{6}$/i.test(text(value)) ? text(value) : "#64748B";
