/**
 * Catálogo de estados que se siembra en un workspace vacío.
 * Cada tupla es `[nombre, color, isDone, position]`.
 */

export const defaultStatuses = [
  ["Open", "#64748B", 0, 0],
  ["Estimated", "#8B5CF6", 0, 1],
  ["Schedule", "#2563EB", 0, 2],
  ["Work in Progress", "#4F46E5", 0, 3],
  ["Pending Release to BD-Lab", "#0891B2", 0, 4],
  ["BD-Lab Testing", "#0D9488", 0, 5],
  ["Pending Release to BDOnline", "#EA580C", 0, 6],
  ["Awaiting for Client", "#D97706", 0, 7],
  ["Reopened", "#DB2777", 0, 8],
  ["Done", "#059669", 1, 9],
  ["Cancelled", "#DC2626", 1, 10],
];
