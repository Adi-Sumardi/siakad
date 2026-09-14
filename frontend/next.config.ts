import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Bundles only the node_modules a build actually needs into .next/standalone,
  // which is what the Docker image copies - same setup as PMB.
  output: "standalone",

  // Dev-only origin gate: Next 16 blocks cross-origin requests to its dev
  // assets (HMR, chunks) unless the origin is listed - without this, a page
  // opened through a tunnel loads its HTML but never its JavaScript. The
  // wildcards cover ngrok's random free-tunnel hostnames in both flavours
  // (.dev / .app), so a fresh tunnel needs no config edit. Production is not
  // affected: this option only applies to `next dev`.
  allowedDevOrigins: ["localhost:3000", "*.ngrok-free.dev", "*.ngrok-free.app"],

  // The production shape, reproduced locally: nginx serves /api and /sanctum
  // on the SAME origin as this app, which is what Sanctum SPA cookie auth and
  // lib/api.ts's same-origin XSRF cookie read both depend on. With these
  // rewrites, `next dev` proxies those paths to `php artisan serve`, so
  // localhost:3000 works with NEXT_PUBLIC_API_URL empty - and so does a
  // single public tunnel (ngrok), which could never serve a second port or
  // a cross-origin API anyway.
  //
  // In the Docker image nginx still owns /api before a request ever reaches
  // Next, and NEXT_PUBLIC_API_URL is empty there too - identical behaviour,
  // so these rewrites are dev/tunnel-only in practice.
  async rewrites() {
    const api = process.env.API_PROXY_TARGET ?? "http://127.0.0.1:8000";

    return [
      { source: "/api/:path*", destination: `${api}/api/:path*` },
      { source: "/sanctum/:path*", destination: `${api}/sanctum/:path*` },
    ];
  },
};

export default nextConfig;
