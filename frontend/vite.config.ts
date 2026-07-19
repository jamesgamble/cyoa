import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

const version = readFileSync(resolve(__dirname, "../VERSION"), "utf8").trim();

export default defineConfig({
  plugins: [react()],
  define: {
    __APP_VERSION__: JSON.stringify(version),
  },
  server: {
    // Development: proxy `/api` to the PHP built-in server started via
    // `php -S 127.0.0.1:8000 -t public`. In production the same
    // `/api` prefix is served from the same origin as the built
    // React bundle, so no proxy configuration is needed there.
    proxy: {
      "/api": {
        target: "http://127.0.0.1:8000",
        changeOrigin: false,
      },
    },
  },
  build: {
    outDir: "dist",
    emptyOutDir: true,
  },
  test: {
    globals: true,
    environment: "jsdom",
    setupFiles: ["./src/test-setup.ts"],
    css: false,
  },
});
