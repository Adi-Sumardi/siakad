import { API_BASE } from "@/lib/api";

/**
 * The one blob-download every file endpoint uses.
 *
 * Download endpoints live on the API origin behind Sanctum's session
 * cookie, so a plain <a href="/api/..."> navigation never carries the
 * auth the endpoint needs - the browser gets a JSON 401 instead of the
 * file. Fetch with the session cookie and hand the browser a blob.
 *
 * No token in the URL, ever: query strings end up in server logs and
 * browser history, which is a fresh leak for a credential that opens
 * everything else.
 */
export async function downloadApiFile(path: string, filename: string): Promise<void> {
  const res = await fetch(`${API_BASE}${path}`, { credentials: "include" });

  if (!res.ok) {
    if (res.status === 401) {
      throw new Error("Sesi Anda berakhir — silakan login ulang, lalu coba unduh kembali.");
    }
    throw new Error("Gagal mengunduh file. Coba lagi sebentar.");
  }

  const blob = await res.blob();
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}
