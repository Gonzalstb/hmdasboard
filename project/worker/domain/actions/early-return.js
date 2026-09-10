/**
 * Señal interna para reproducir un `return;` desde `action()`.
 *
 * En el monolito, algunas ramas hacían `return;` (devuelve `undefined`)
 * en lugar de `return result`. Este símbolo conserva ese comportamiento.
 */

export const EARLY_RETURN = Symbol('action.earlyReturn');
