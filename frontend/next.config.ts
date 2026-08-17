import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Bundles only the node_modules a build actually needs into .next/standalone,
  // which is what the Docker image copies - same setup as PMB.
  output: "standalone",
};

export default nextConfig;
