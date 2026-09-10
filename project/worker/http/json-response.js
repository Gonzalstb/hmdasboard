/**
 * Respuesta JSON con cabeceras extra (cookie, status, etc.).
 */

export async function jsonResponse(body, noStore, extraHeaders = {}, status = 200) {
  const headers = { ...noStore, ...extraHeaders };
  return Response.json(body, { status, headers });
}
