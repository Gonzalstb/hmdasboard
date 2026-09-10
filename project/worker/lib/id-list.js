/**
 * Lista de identificadores enteros positivos, sin duplicados y en orden de aparición.
 */

export const idList = value => Array.isArray(value) ? [...new Set(value.map(Number).filter(x => Number.isInteger(x) && x > 0))] : [];
