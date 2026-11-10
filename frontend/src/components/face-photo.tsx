"use client";

import { useEffect, useRef, useState } from "react";
import { Camera, X } from "lucide-react";
import { API_BASE } from "@/lib/api";

/**
 * Gate check-in by face (docs/09): a small "lihat foto" link that opens the
 * photo in an overlay. The photo endpoints sit behind the Sanctum session
 * cookie, so the image is fetched with credentials and shown as a blob -
 * a plain <img src="/api/..."> would carry no auth.
 */
export function FacePhotoButton({ path, label = "Foto", title }: { path: string; label?: string; title?: string }) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
      >
        <Camera className="size-3.5" /> {label}
      </button>
      {open && <FacePhotoOverlay path={path} title={title} onClose={() => setOpen(false)} />}
    </>
  );
}

function FacePhotoOverlay({ path, title, onClose }: { path: string; title?: string; onClose: () => void }) {
  const [url, setUrl] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);
  // The board above may re-render (polling) while this is open; a ref keeps
  // that from re-fetching the photo every time.
  const closeRef = useRef(onClose);
  useEffect(() => {
    closeRef.current = onClose;
  });

  useEffect(() => {
    let objectUrl: string | null = null;
    let cancelled = false;

    fetch(`${API_BASE}${path}`, { credentials: "include" })
      .then((res) => (res.ok ? res.blob() : Promise.reject()))
      .then((blob) => {
        if (cancelled) return;
        objectUrl = URL.createObjectURL(blob);
        setUrl(objectUrl);
      })
      .catch(() => !cancelled && setFailed(true));

    const onKey = (e: KeyboardEvent) => e.key === "Escape" && closeRef.current();
    window.addEventListener("keydown", onKey);

    return () => {
      cancelled = true;
      window.removeEventListener("keydown", onKey);
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [path]);

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={title ?? "Foto wajah"}
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
      onClick={onClose}
    >
      <div className="relative w-full max-w-xs rounded-xl bg-card p-3 shadow-lg" onClick={(e) => e.stopPropagation()}>
        <button
          type="button"
          onClick={onClose}
          aria-label="Tutup"
          className="absolute right-2 top-2 rounded-full bg-card/80 p-1 text-muted-foreground hover:text-foreground"
        >
          <X className="size-4" />
        </button>
        {title && <p className="mb-2 pr-6 text-sm font-medium">{title}</p>}
        {url ? (
          // eslint-disable-next-line @next/next/no-img-element -- a blob: URL, nothing for next/image to optimise
          <img src={url} alt={title ?? "Foto wajah"} className="w-full rounded-lg" />
        ) : (
          <div className="flex aspect-[3/4] items-center justify-center rounded-lg bg-muted text-xs text-muted-foreground">
            {failed ? "Foto tidak tersedia." : "Memuat foto…"}
          </div>
        )}
      </div>
    </div>
  );
}
