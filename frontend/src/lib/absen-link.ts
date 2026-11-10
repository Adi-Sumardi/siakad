/**
 * The address a unit's public absen link is shared and printed with
 * (docs/09). The server's `public_url` sits on the official domain
 * (FRONTEND_URL) and wins whenever it is HTTPS - a QR taped to a classroom
 * wall must not carry whatever address staff opened the app from. On a dev
 * machine FRONTEND_URL is plain http://localhost, which no phone can open,
 * so the current origin (e.g. a tunnel) is used instead.
 */
export function absenLinkUrl(publicUrl: string | null | undefined, publicPath: string | null | undefined): string | null {
  if (!publicPath) return null;
  if (publicUrl?.startsWith("https://")) return publicUrl;
  return typeof window !== "undefined" ? `${window.location.origin}${publicPath}` : null;
}
