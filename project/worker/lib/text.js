/**
 * Normaliza un valor a texto recortado.
 * Cualquier tipo que no sea string se convierte en cadena vacía.
 */

export const text = value => typeof value === "string" ? value.trim() : "";
