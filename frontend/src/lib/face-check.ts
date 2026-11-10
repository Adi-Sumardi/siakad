"use client";

import { useEffect, useRef, useState } from "react";
import type { FaceLandmarker } from "@mediapipe/tasks-vision";
import { createBlinkDetector } from "@/lib/blink-detector";

/**
 * Gate check-in by face (docs/09-ABSEN-WAJAH-GERBANG.md), browser half.
 *
 * Two engines, each doing what it is good at:
 *
 * - MediaPipe Face Landmarker tracks the face on EVERY camera frame (GPU,
 *   ~20-30 fps on a phone) and reports a trained blink score per eye - the
 *   liveness check. Self-hosted under /mediapipe.
 * - face-api computes the 128-number identity descriptor the server matches
 *   against the student's registered face. It runs in the background while
 *   the student is looking at the camera (about once a second), so when the
 *   blink lands the latest descriptor is already there and the check-in is
 *   sent at once - no "please wait" after the blink.
 *
 * The photo sent as evidence is the frame right after the blink.
 */

type FaceApi = typeof import("@vladmandic/face-api");

const FACE_API_MODELS = "/models/face";
const MEDIAPIPE_WASM = "/mediapipe/wasm";
const MEDIAPIPE_MODEL = "/mediapipe/face_landmarker.task";
/** Descriptor pass input size - one face, close to the camera. */
const DESCRIPTOR_INPUT = 320;
/** Refresh the background descriptor when it is older than this. */
const DESCRIPTOR_REFRESH_MS = 1000;
/** A descriptor older than this at blink time is not trusted - compute a fresh one instead. */
const DESCRIPTOR_MAX_AGE_MS = 2500;
/** Face narrower than this share of the frame: ask to come closer. */
const MIN_FACE_WIDTH = 0.2;

let faceApiLoading: Promise<FaceApi> | null = null;
let trackerLoading: Promise<FaceLandmarker> | null = null;
let warming: Promise<void> | null = null;

/** face-api plus the three models, once per page. */
export function loadFaceApi(): Promise<FaceApi> {
  if (!faceApiLoading) {
    faceApiLoading = (async () => {
      const faceapi = await import("@vladmandic/face-api");
      // The bundled typings omit ready(), but the runtime tfjs has it.
      await (faceapi.tf as unknown as { ready: () => Promise<void> }).ready();
      await Promise.all([
        faceapi.nets.tinyFaceDetector.loadFromUri(FACE_API_MODELS),
        faceapi.nets.faceLandmark68Net.loadFromUri(FACE_API_MODELS),
        faceapi.nets.faceRecognitionNet.loadFromUri(FACE_API_MODELS),
      ]);
      return faceapi;
    })();
    faceApiLoading.catch(() => {
      faceApiLoading = null;
      warming = null;
    });
  }
  return faceApiLoading;
}

/**
 * MediaPipe's WASM runtime prints its own INFO/verbose lines ("INFO: Created
 * TensorFlow Lite XNNPACK delegate for CPU.", glog lines like "I0000 00:00..."
 * or "W0000 ...") through console.error. They are not errors, but Next's dev
 * overlay turns every console.error into a red "Console Error" over the
 * camera. Only those log shapes are dropped; anything else still goes through.
 */
const MEDIAPIPE_LOG = /^(INFO: |[IWV]\d{4} \d{2}:\d{2}:\d{2}|.*TensorFlow Lite XNNPACK delegate)/;
let consoleFiltered = false;

function silenceMediaPipeInfoLogs(): void {
  if (consoleFiltered || typeof console === "undefined") return;
  consoleFiltered = true;

  const original = console.error.bind(console);
  console.error = (...args: unknown[]) => {
    if (typeof args[0] === "string" && MEDIAPIPE_LOG.test(args[0])) return;
    original(...args);
  };
}

/** MediaPipe Face Landmarker in video mode, once per page: GPU first, CPU if the phone's GPU path fails. */
export function loadTracker(): Promise<FaceLandmarker> {
  if (!trackerLoading) {
    silenceMediaPipeInfoLogs();
    trackerLoading = (async () => {
      const { FaceLandmarker, FilesetResolver } = await import("@mediapipe/tasks-vision");
      const fileset = await FilesetResolver.forVisionTasks(MEDIAPIPE_WASM);

      const create = (delegate: "GPU" | "CPU") =>
        FaceLandmarker.createFromOptions(fileset, {
          baseOptions: { modelAssetPath: MEDIAPIPE_MODEL, delegate },
          runningMode: "VIDEO",
          numFaces: 2, // two faces must be SEEN to be refused
          outputFaceBlendshapes: true,
          minFaceDetectionConfidence: 0.5,
          minTrackingConfidence: 0.5,
        });

      try {
        return await create("GPU");
      } catch {
        return await create("CPU");
      }
    })();
    trackerLoading.catch(() => {
      trackerLoading = null;
      warming = null;
    });
  }
  return trackerLoading;
}

/**
 * Downloads and warms up both engines while the student is still reading
 * the intro and typing their NIS - first-run GPU shader compiles take
 * seconds on a phone and must not happen while they are told to blink.
 * Safe to call repeatedly.
 */
export function warmUpFaceApi(): Promise<void> {
  if (!warming) {
    warming = (async () => {
      const canvas = document.createElement("canvas");
      canvas.width = 160;
      canvas.height = 160;
      canvas.getContext("2d")?.fillRect(0, 0, 160, 160);

      const [faceapi, tracker] = await Promise.all([loadFaceApi(), loadTracker()]);
      tracker.detectForVideo(canvas, performance.now());
      await faceapi.nets.faceLandmark68Net.detectLandmarks(canvas);
      await faceapi.nets.faceRecognitionNet.computeFaceDescriptor(canvas);
    })().catch(() => {
      // Only an optimisation; the camera step reports real failures.
      warming = null;
    });
  }
  return warming ?? Promise.resolve();
}

export type FaceCapture = { descriptor: number[]; photo: string };

export type BlinkStatus =
  | "loading" // models or camera starting
  | "no-face"
  | "many-faces"
  | "too-far"
  | "ready" // face found, waiting for the blink
  | "eyes-closed" // mid-blink - instant feedback that it is being seen
  | "captured"
  | "camera-error"
  | "model-error";

/** Average of both eyes' blink scores, 0 (open) .. 1 (shut). */
function blinkScore(categories: { categoryName: string; score: number }[]): number {
  let left = 0;
  let right = 0;
  for (const c of categories) {
    if (c.categoryName === "eyeBlinkLeft") left = c.score;
    else if (c.categoryName === "eyeBlinkRight") right = c.score;
  }
  return (left + right) / 2;
}

/**
 * While `active`: front camera, per-frame tracking, and one capture on the
 * first blink. `onCapture` fires exactly once per activation; `retry`
 * starts a new activation after the server refused the capture.
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
    let raf = 0;
    let stopped = false;
    let lastStatus: BlinkStatus | null = null;
    const blink = createBlinkDetector();

    // The background identity descriptor (see the file comment).
    let latest: { descriptor: number[]; at: number } | null = null;
    let describing = false;

    const update = (next: BlinkStatus) => {
      // Called every frame - only touch React state when it changes.
      if (!stopped && next !== lastStatus) {
        lastStatus = next;
        setStatus(next);
      }
    };

    (async () => {
      update("loading");

      // Camera permission and model download in parallel.
      const engines = Promise.all([loadFaceApi(), loadTracker()]);
      engines.catch(() => {}); // handled below; avoid an unhandled rejection meanwhile

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
      if (!video || stopped) {
        stream.getTracks().forEach((t) => t.stop());
        return;
      }
      video.srcObject = stream;
      await video.play().catch(() => {});

      let faceapi: FaceApi;
      let tracker: FaceLandmarker;
      try {
        [faceapi, tracker] = await engines;
      } catch {
        update("model-error");
        return;
      }
      if (stopped) return;

      const descriptorOptions = new faceapi.TinyFaceDetectorOptions({ inputSize: DESCRIPTOR_INPUT, scoreThreshold: 0.4 });

      const describe = async (frame: HTMLCanvasElement) => {
        const full = await faceapi.detectSingleFace(frame, descriptorOptions).withFaceLandmarks().withFaceDescriptor();
        return full ? Array.from(full.descriptor) : null;
      };

      /** Fire-and-forget: keep `latest` fresh while the student looks at the camera. */
      const refreshDescriptor = () => {
        if (describing || (latest && performance.now() - latest.at < DESCRIPTOR_REFRESH_MS)) return;
        describing = true;
        const frame = frameCanvas(video);
        describe(frame)
          .then((descriptor) => {
            if (descriptor) latest = { descriptor, at: performance.now() };
          })
          .catch(() => {})
          .finally(() => {
            describing = false;
          });
      };

      const capture = async () => {
        // The frame right after the blink, eyes open again.
        const frame = frameCanvas(video);
        update("captured");

        let descriptor =
          latest && performance.now() - latest.at < DESCRIPTOR_MAX_AGE_MS ? latest.descriptor : null;
        if (!descriptor) descriptor = await describe(frame).catch(() => null);
        if (stopped) return;

        if (descriptor) {
          onCaptureRef.current({ descriptor, photo: frame.toDataURL("image/jpeg", 0.8) });
          return;
        }

        // Face too blurred to describe - keep watching for the next blink.
        blink.reset();
        update("ready");
        raf = requestAnimationFrame(loop);
      };

      let lastVideoTime = -1;

      const loop = () => {
        if (stopped) return;

        // Only run on a NEW camera frame; the screen can refresh faster.
        if (video.readyState >= 2 && video.currentTime !== lastVideoTime) {
          lastVideoTime = video.currentTime;

          try {
            const result = tracker.detectForVideo(video, performance.now());
            const faces = result.faceLandmarks.length;

            if (faces === 0) {
              blink.reset();
              latest = null;
              update("no-face");
            } else if (faces > 1) {
              blink.reset();
              latest = null; // a second face may be whose descriptor we hold
              update("many-faces");
            } else {
              const xs = result.faceLandmarks[0].map((p) => p.x);
              const width = Math.max(...xs) - Math.min(...xs);

              if (width < MIN_FACE_WIDTH) {
                blink.reset();
                update("too-far");
              } else {
                const score = blinkScore(result.faceBlendshapes[0]?.categories ?? []);

                if (blink.push(score)) {
                  capture();
                  return; // the loop resumes only if the capture fails
                }

                if (blink.isClosed()) {
                  update("eyes-closed");
                } else {
                  update("ready");
                  refreshDescriptor();
                }
              }
            }
          } catch {
            // One bad frame is not worth an error on screen.
          }
        }

        raf = requestAnimationFrame(loop);
      };

      raf = requestAnimationFrame(loop);
    })();

    return () => {
      stopped = true;
      cancelAnimationFrame(raf);
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

/** The current video frame at up to 480px wide - descriptor input and evidence photo. */
function frameCanvas(video: HTMLVideoElement): HTMLCanvasElement {
  const scale = Math.min(1, 480 / video.videoWidth);
  const canvas = document.createElement("canvas");
  canvas.width = Math.round(video.videoWidth * scale);
  canvas.height = Math.round(video.videoHeight * scale);
  canvas.getContext("2d")?.drawImage(video, 0, 0, canvas.width, canvas.height);
  return canvas;
}
