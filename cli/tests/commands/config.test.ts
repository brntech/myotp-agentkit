import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { promises as fs } from "node:fs";
import os from "node:os";
import path from "node:path";
import { captureIo, stdout } from "../helpers/io.js";

let tmpHome: string;
let homedirSpy: ReturnType<typeof vi.spyOn>;

beforeEach(async () => {
  tmpHome = await fs.mkdtemp(path.join(os.tmpdir(), "myotp-cfg-test-"));
  homedirSpy = vi.spyOn(os, "homedir").mockReturnValue(tmpHome);
  delete process.env.MYOTP_API_KEY;
  delete process.env.MYOTP_BASE_URL;
});

afterEach(async () => {
  homedirSpy.mockRestore();
  await fs.rm(tmpHome, { recursive: true, force: true });
});

describe("config command — show", () => {
  it("reports `exists: false` when no config file is present", async () => {
    const { runConfig } = await import("../../src/commands/config.js");
    const io = captureIo();
    try {
      await runConfig({ json: true });
      const parsed = JSON.parse(stdout(io).trim());
      expect(parsed.ok).toBe(true);
      expect(parsed.data.exists).toBe(false);
      expect(parsed.data.api_key_set).toBe(false);
    } finally {
      io.restore();
    }
  });

  it("masks the API key when one is saved", async () => {
    const { writeConfig } = await import("../../src/lib/config.js");
    await writeConfig({ apiKey: "k_test_abcdef1234567890" });

    const { runConfig } = await import("../../src/commands/config.js");
    const io = captureIo();
    try {
      await runConfig({ json: true });
      const parsed = JSON.parse(stdout(io).trim());
      expect(parsed.data.exists).toBe(true);
      expect(parsed.data.api_key_set).toBe(true);
      // The full key must never appear in the masked field or the rest of the
      // config dump.
      expect(parsed.data.api_key_masked).not.toBe("k_test_abcdef1234567890");
      expect(parsed.data.api_key_masked).toContain("*");
      expect(JSON.stringify(parsed)).not.toContain("k_test_abcdef1234567890");
    } finally {
      io.restore();
    }
  });

  it("notes when MYOTP_API_KEY env var is set in human output", async () => {
    process.env.MYOTP_API_KEY = "k_from_env_value";
    const { runConfig } = await import("../../src/commands/config.js");
    const io = captureIo();
    try {
      await runConfig({});
      expect(stdout(io)).toContain("MYOTP_API_KEY is set");
      // The env value itself must not be echoed back.
      expect(stdout(io)).not.toContain("k_from_env_value");
    } finally {
      io.restore();
    }
  });
});

describe("config command — --reset", () => {
  it("removes the saved config file", async () => {
    const { writeConfig, configExists } = await import("../../src/lib/config.js");
    await writeConfig({ apiKey: "k" });
    expect(await configExists()).toBe(true);

    const { runConfig } = await import("../../src/commands/config.js");
    const io = captureIo();
    try {
      await runConfig({ reset: true });
      expect(await configExists()).toBe(false);
      expect(stdout(io)).toContain("removed");
    } finally {
      io.restore();
    }
  });

  it("returns a structured result when --reset hits a nonexistent config", async () => {
    const { runConfig } = await import("../../src/commands/config.js");
    const io = captureIo();
    try {
      await runConfig({ reset: true, json: true });
      const parsed = JSON.parse(stdout(io).trim());
      expect(parsed.ok).toBe(true);
      expect(parsed.data.removed).toBe(false);
    } finally {
      io.restore();
    }
  });
});

describe("config command — --set-key", () => {
  it("writes a new API key to the config file", async () => {
    const { runConfig } = await import("../../src/commands/config.js");
    const io = captureIo();
    try {
      await runConfig({ setKey: "k_new_value", json: true });
      const parsed = JSON.parse(stdout(io).trim());
      expect(parsed.data.api_key_masked).toContain("*");

      const { readConfig } = await import("../../src/lib/config.js");
      const cfg = await readConfig();
      expect(cfg.apiKey).toBe("k_new_value");
    } finally {
      io.restore();
    }
  });

  it("sets a custom base URL", async () => {
    const { runConfig } = await import("../../src/commands/config.js");
    const io = captureIo();
    try {
      await runConfig({ setBaseUrl: "https://staging.example.com/", json: true });
      const { readConfig } = await import("../../src/lib/config.js");
      const cfg = await readConfig();
      // Trailing slashes are normalized away.
      expect(cfg.baseUrl).toBe("https://staging.example.com");
    } finally {
      io.restore();
    }
  });
});

// We deliberately do NOT test commands/init.ts here. It uses interactive
// prompts (prompts library) and asks for terminal input. Testing it requires
// a TTY harness that isn't worth the complexity for this round; the 404
// fallback path inside init is exercised indirectly by the api.test.ts tests
// that confirm the client surfaces 404 as a MyOtpApiError that init can catch.
