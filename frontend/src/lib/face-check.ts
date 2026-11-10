"use client";

import { useEffect, useRef, useState } from "react";

/**
 * Gate check-in by face (docs/09-ABSEN-WAJAH-GERBANG.md), browser half.
 *
 * Runs face-api with models served from our own /models/face, watches the
 * front camera until the student blinks once (the liveness check), then
 * captures one frame with eyes open: a 128-float descriptor plus a small
 * JPEG. The server compares the descriptor against the student's registered
 * face (or registers it on the first check-in) - nothing is decided here.
 */

type FaceApi = typeof import("@vladmandic/face-api");

const MODEL_URL = "/models/face";
let loading: Promise<FaceApi> | null = null;

/** Loads the library and the three models once per page; later calls reuse the same promise. */
export function loadFaceApi(): Promise<FaceApi> {
  if (!loading) {
    loading = (async () => {
      const faceapi = await import("@vladmandic/face-api");
      // The bundled typings omit ready(), but the runtime tfjs has it: wait
      // for the WebGL/CPU backend before the first model touches it.
      await (faceapi.tf as unknown as { ready: () => Promise<void> }).ready();
      await Promise.all([
        faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
        faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
        faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
      ]);
      return faceapi;
    })();
    // A failed load (offline, blocked) must be retryable, not cached forever.
    loading.catch(() => {
      loading = null;
    });
  }
  return loading;
}

export type FaceCapture = { descriptor: number[]; photo: string };

export type BlinkStatus =
  | "loading" // models or camera starting
  | "no-face"
  | "many-faces"
  | "too-far"
  | "ready" // face found, waiting for the blink
  | "captured"
  | "camera-error"
  | "model-error";

type Point = { x: number; y: number };

const dist = (a: Point, b: Point) => Math.hypot(a.x - b.x, a.y - b.y);

/** Eye aspect ratio over the 6 landmark points of one eye: ~0.3 open, ~0.1 shut. */
function eyeAspectRatio(eye: Point[]): number {
  return (dist(eye[1], eye[5]) + dist(eye[2], eye[4])) / (2 * dist(eye[0], eye[3]));
}

/**
 * While `active`: front camera, a detection loop, and one capture after a
 * blink. `onCapture` fires exactly once per activation; `retry` starts a new
 * activation after the server refused the capture.
 */
export function useBlinkCapture(active: boolean, onCapture: (capture: FaceCapture) => void) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const onCaptureRef = useRef(onCapture);
  const [attempt, setAttempt] = useState(0);
  const [status, setStatus] = useState<BlinkStatus>("loading");

  useEffect(() => {
    onCaptureRef.current = onCapture;
  });

  useEffect(() => {
    if (!active) return;

    let stream: MediaStream | null = null;
    let timer = 0;
    let stopped = false;

    // Adaptive baseline: what "open" looks like for this face in this light.
    let openEar = 0;
    let closedSeen = false;

    const update = (next: BlinkStatus) => {
      if (!stopped) setStatus(next);
    };

    (async () => {
      update("loading");

      let faceapi: FaceApi;
      try {
        faceapi = await loadFaceApi();
      } catch {
        update("model-error");
        return;
      }

      try {
        stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: "user", width: { ideal: 640 }, height: { ideal: 480 } },
          audio: false,
        });
      } catch {
        update("camera-error");
        return;
      }

      const video = videoRef.current;
      if (!video || stopped) return;
      video.srcObject = stream;
      await video.play().catch(() => {});

      const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 });

      const tick = async () => {
        if (stopped) return;

        try {
          const results = await faceapi.detectAllFaces(video, options).withFaceLandmarks();

          if (results.length === 0) {
            update("no-face");
          } else if (results.length > 1) {
            update("many-faces");
          } else {
            const { detection, landmarks } = results[0];

            if (detection.box.width < video.videoWidth * 0.2) {
              update("too-far");
            } else {
              update("ready");
              const ear = (eyeAspectRatio(landmarks.getLeftEye()) + eyeAspectRatio(landmarks.getRightEye())) / 2;
              openEar = Math.max(openEar * 0.98, ear);

              if (openEar > 0 && ear < openEar * 0.7) {
                closedSeen = true;
              } else if (closedSeen && ear > openEar * 0.85) {
                // Blink finished and the eyes are open again: capture this
                // moment. The descriptor comes from this same frame.
                const full = await faceapi
                  .detectSingleFace(video, options)
                  .withFaceLandmarks()
                  .withFaceDescriptor();

                if (full && !stopped) {
                  update("captured");
                  onCaptureRef.current({ descriptor: Array.from(full.descriptor), photo: snapshot(video) });
                  return;
                }
              }
            }
          }
        } catch {
          // One bad frame is not an error worth showing - try the next.
        }

        timer = window.setTimeout(tick, 80);
      };

      tick();
    })();

    return () => {
      stopped = true;
      window.clearTimeout(timer);
      stream?.getTracks().forEach((t) => t.stop());
    };
  }, [active, attempt]);

  return {
    videoRef,
    status,
    /** Start over after the server refused a capture. */
    retry: () => setAttempt((a) => a + 1),
  };
}

/** One frame as a ~480px JPEG data URL - the evidence photo staff can look at. */
function snapshot(video: HTMLVideoElement): string {
  const scale = Math.min(1, 480 / video.videoWidth);
  const canvas = document.createElement("canvas");
  canvas.width = Math.round(video.videoWidth * scale);
  canvas.height = Math.round(video.videoHeight * scale);
  canvas.getContext("2d")?.drawImage(video, 0, 0, canvas.width, canvas.height);
  return canvas.toDataURL("image/jpeg", 0.8);
}
