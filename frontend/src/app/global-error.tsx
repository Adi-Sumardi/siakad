"use client";

// The last-resort boundary (audit 2026-10-05): fires when the ROOT layout
// itself crashes, so it must render its own <html>/<body> and cannot rely
// on the app's global styles or theme - everything it needs is inline.
// The `retry` prop re-fetches and re-renders (this Next.js version's name
// for what older docs call `reset`).
import { useEffect } from "react";

export default function GlobalError({
  error,
  retry,
}: {
  error: Error & { digest?: string };
  retry: () => void;
}) {
  useEffect(() => {
    console.error("[global-error]", error);
  }, [error]);

  return (
    <html lang="id">
      <body
        style={{
          margin: 0,
          minHeight: "100vh",
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          backgroundColor: "#f4f6f5",
          color: "#1c2321",
          fontFamily: "system-ui, -apple-system, 'Segoe UI', sans-serif",
          padding: "16px",
        }}
      >
        <div style={{ maxWidth: "420px", textAlign: "center" }}>
          <h1 style={{ fontSize: "20px", margin: "0 0 8px" }}>
            Aplikasi gagal dimuat
          </h1>
          <p style={{ fontSize: "14px", lineHeight: 1.6, color: "#4b5a55", margin: "0 0 20px" }}>
            Kesalahan terjadi pada kerangka aplikasi, bukan pada data Anda.
            Tutup tab ini, masuk kembali, lalu coba lagi. Bila berulang,
            hubungi Tata Usaha.
          </p>
          <button
            onClick={retry}
            style={{
              backgroundColor: "#0e6b5c",
              color: "#fff",
              border: "none",
              borderRadius: "8px",
              padding: "10px 18px",
              fontSize: "14px",
              fontWeight: 600,
              cursor: "pointer",
            }}
          >
            Coba lagi
          </button>
        </div>
      </body>
    </html>
  );
}
