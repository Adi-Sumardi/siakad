"use client";

import { useEffect, useRef, useState } from "react";
import jsQR from "jsqr";

/**
 * The shared plumbing of both self check-in surfaces (gate absen/ + lesson
 * presensi/): the device-once rule's browser token, the GPS fix the gate
 * radius check reads (accuracy included - the server rejects a fix wider
 * than half the radius), gallery-photo QR decoding, and the live camera
 * scan loop. Extracted from the gate page when the lesson flow grew its own
 * scanner, so the two stay in lockstep.
 */

/** localStorage UUID - survives visits; incognito starts fresh, which is exactly what the IP-sharing flag on the TU board is there to catch. */
export function deviceId(): string {
  let id = localStorage.getItem("absen-device-id");
  if (!id) {
    id = crypto.randomUUID();
    localStorage.setItem("absen-device-id", id);
  }
  return id;
}

/**
 * The NIS this phone last checked in as - remembered after one successful
 * lookup so the NEXT scan is one tap instead of a typing exercise. Stored
 * client-side only, on the student's own phone; "Bukan saya" on the confirm
 * screen falls back to the typing form and the retyped NIS overwrites it
 * (a borrowed phone self-heals the same way).
 */
export function rememberedNis(): string | null {
  return localStorage.getItem("absen-nis");
}

export function rememberNis(nis: string): void {
  localStorage.setItem("absen-nis", nis);
}

/** One high-accuracy GPS fix, or null when denied/unavailable - never a rejection, the pages render their own retry states. */
export function getPosition(): Promise<{ lat: number; lng: number; accuracy: number } | null> {
  return new Promise((resolve) => {
    if (!("geolocation" in navigator)) return resolve(null);

    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 },
    );
  });
}

/** Decodes a QR from a gallery photo onto the same code string a live scan yields. */
export function decodeQrFromFile(file: File): Promise<string | null> {
  return new Promise((resolve) => {
    const url = URL.createObjectURL(file);
    const img = new Image();

    img.onload = () => {
      URL.revokeObjectURL(url);

      const scale = Math.min(1, 1200 / Math.max(img.width, img.height));
      const canvas = document.createElement("canvas");
      canvas.width = Math.max(1, Math.round(img.width * scale));
      canvas.height = Math.max(1, Math.round(img.height * scale));

      const ctx = canvas.getContext("2d", { willReadFrequently: true });
      if (!ctx) return resolve(null);

      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      const frame = ctx.getImageData(0, 0, canvas.width, canvas.height);
      const found = jsQR(frame.data, frame.width, frame.height, { inversionAttempts: "attemptBoth" });

      resolve(found?.data?.trim() || null);
    };

    img.onerror = () => {
      URL.revokeObjectURL(url);
      resolve(null);
    };

    img.src = url;
  });
}

/**
 * The camera scan loop while `active`: back camera, jsQR over canvas frames,
 * and the decode fires `onCode` exactly once per activation - no
 * intermediate screen, no delay for a rotating code to go stale in. `rearm`
 * restarts the loop after a rejected code (wrong QR, expired window) so the
 * student can point at the screen again without reloading. Tears the stream
 * down the moment `active` goes false.
 */
export function useQrScanner(active: boolean, onCode: (code: string) => void) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const onCodeRef = useRef(onCode);
  const firedRef = useRef(false);
  const [attempt, setAttempt] = useState(0);
  const [cameraError, setCameraError] = useState(false);

  useEffect(() => {
    onCodeRef.current = onCode;
  });

  useEffect(() => {
    if (!active) return;

    firedRef.current = false;

    let stream: MediaStream | null = null;
    let raf = 0;
    let stopped = false;
    const canvas = document.createElement("canvas");
    const ctx = canvas.getContext("2d", { willReadFrequently: true });

    (async () => {
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } });
        const video = videoRef.current;
        if (!video || stopped) return;
        video.srcObject = stream;
        await video.play();
        // A fresh attempt earned a clean slate - clear whatever error a
        // previous attempt left (async, so not a render-cascade setState).
        if (!stopped) setCameraError(false);

        const tick = () => {
          if (stopped) return;

          if (video.readyState === video.HAVE_ENOUGH_DATA && ctx && !firedRef.current) {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0);
            const frame = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const found = jsQR(frame.data, frame.width, frame.height, { inversionAttempts: "dontInvert" });

            if (found?.data) {
              firedRef.current = true; // stop later frames from double-firing
              onCodeRef.current(found.data.trim());
              return;
            }
          }

          raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
      } catch {
        if (!stopped) setCameraError(true);
      }
    })();

    return () => {
      stopped = true;
      cancelAnimationFrame(raf);
      stream?.getTracks().forEach((t) => t.stop());
    };
  }, [active, attempt]);

  return {
    videoRef,
    cameraError,
    /** Restart the scan loop after a fired code was rejected server-side. */
    rearm: () => setAttempt((a) => a + 1),
  };
}
