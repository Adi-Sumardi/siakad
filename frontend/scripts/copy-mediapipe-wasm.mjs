// Copies MediaPipe's WebAssembly runtime from node_modules into
// public/mediapipe/wasm, so the blink tracker on /absen is served from our
// own server (docs/09-ABSEN-WAJAH-GERBANG.md) - no third-party CDN that a
// school or phone network might block. Runs before `next dev` and
// `next build` (predev / prebuild). The files are ~13 MB each, so they are
// copied, not committed.
import { copyFileSync, existsSync, mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const from = join(root, "node_modules", "@mediapipe", "tasks-vision", "wasm");
const to = join(root, "public", "mediapipe", "wasm");

// FilesetResolver picks the SIMD build when the browser supports it and the
// no-SIMD build otherwise (older phones) - both pairs are needed.
const files = [
  "vision_wasm_internal.js",
  "vision_wasm_internal.wasm",
  "vision_wasm_nosimd_internal.js",
  "vision_wasm_nosimd_internal.wasm",
];

if (!existsSync(from)) {
  console.error("[mediapipe] node_modules/@mediapipe/tasks-vision belum terpasang - jalankan npm install.");
  process.exit(1);
}

mkdirSync(to, { recursive: true });
for (const file of files) {
  copyFileSync(join(from, file), join(to, file));
}
console.log(`[mediapipe] ${files.length} file WASM disalin ke public/mediapipe/wasm`);
