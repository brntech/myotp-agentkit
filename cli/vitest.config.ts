import { defineConfig } from "vitest/config";

export default defineConfig({
  test: {
    environment: "node",
    include: ["tests/**/*.test.ts"],
    testTimeout: 5_000,
    hookTimeout: 5_000,
    clearMocks: true,
    restoreMocks: true,
    // The CLI mutates process.env in many tests; isolate them by file rather
    // than running everything in one big shared worker.
    isolate: true,
  },
});
