import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { promises as fs } from "node:fs";
import os from "node:os";
import path from "node:path";

// Each test gets a unique temp dir as $HOME so config files don't collide
// or leak across runs.
let tmpHome: string;
let homedirSpy: ReturnType<typeof vi.spyOn>;

beforeEach(async () => {
  tmpHome = await fs.mkdtemp(path.join(os.tmpdir(), "myotp-cli-test-"));
  homedirSpy = vi.spyOn(os, "homedir").mockReturnValue(tmpHome);
  // Clean any leaked env vars between tests.
  delete process.env.MYOTP_API_KEY;
  delete process.env.MYOTP_BASE_URL;
});

afterEach(async () => {
  homedirSpy.mockRestore();
  await fs.rm(tmpHome, { recursive: true, force: true });
});

// Import lazily so the os.homedir() mock is in place when config.ts captures
// the path. Static `import` would run before beforeEach.
async function loadConfig() {
  return await import("../../src/lib/config.js");
}

describe("configPath / configDir", () => {
  it("places config under ~/.myotp/config.json", async () => {
    const cfg = await loadConfig();
    expect(cfg.configDir()).toBe(path.join(tmpHome, ".myotp"));
    expect(cfg.configPath()).toBe(path.join(tmpHome, ".myotp", "config.json"));
  });
});

describe("readConfig", () => {
  it("returns an empty object when the file does not exist", async () => {
    const cfg = await loadConfig();
    await expect(cfg.readConfig()).resolves.toEqual({});
  });

  it("reads and parses an existing config file", async () => {
    const cfg = await loadConfig();
    await fs.mkdir(cfg.configDir(), { recursive: true });
    await fs.writeFile(
      cfg.configPath(),
      JSON.stringify({ apiKey: "k_123", email: "a@b.com" })
    );
    const result = await cfg.readConfig();
    expect(result.apiKey).toBe("k_123");
    expect(result.email).toBe("a@b.com");
  });

  it("recovers from a malformed JSON file by throwing", async () => {
    const cfg = await loadConfig();
    await fs.mkdir(cfg.configDir(), { recursive: true });
    await fs.writeFile(cfg.configPath(), "{this is not json");
    // The current implementation surfaces JSON.parse failures rather than
    // silently swallowing them. Document that behavior.
    await expect(cfg.readConfig()).rejects.toThrow();
  });
});

describe("writeConfig", () => {
  it("creates the config dir and file with mode 0o600 on POSIX", async () => {
    const cfg = await loadConfig();
    await cfg.writeConfig({ apiKey: "k_123" });

    const stat = await fs.stat(cfg.configPath());
    // On POSIX, check the permission bits. On Windows, mode is mostly advisory,
    // so just confirm the file exists.
    if (process.platform !== "win32") {
      const mode = stat.mode & 0o777;
      expect(mode).toBe(0o600);
    }

    const written = JSON.parse(await fs.readFile(cfg.configPath(), "utf8")) as Record<string, unknown>;
    expect(written.apiKey).toBe("k_123");
    expect(typeof written.updatedAt).toBe("string");
  });

  it("preserves existing values and updates updatedAt on subsequent writes", async () => {
    const cfg = await loadConfig();
    await cfg.writeConfig({ apiKey: "k_123" });
    const first = JSON.parse(await fs.readFile(cfg.configPath(), "utf8")) as { updatedAt: string };
    // ensure timestamps are different
    await new Promise((r) => setTimeout(r, 5));
    await cfg.writeConfig({ apiKey: "k_123", email: "a@b.com" });
    const second = JSON.parse(await fs.readFile(cfg.configPath(), "utf8")) as {
      apiKey: string;
      email: string;
      updatedAt: string;
    };
    expect(second.email).toBe("a@b.com");
    expect(second.apiKey).toBe("k_123");
    expect(second.updatedAt).not.toBe(first.updatedAt);
  });
});

describe("clearConfig", () => {
  it("removes the file and returns true", async () => {
    const cfg = await loadConfig();
    await cfg.writeConfig({ apiKey: "k_123" });
    await expect(cfg.clearConfig()).resolves.toBe(true);
    await expect(cfg.configExists()).resolves.toBe(false);
  });

  it("returns false when nothing to remove", async () => {
    const cfg = await loadConfig();
    await expect(cfg.clearConfig()).resolves.toBe(false);
  });
});

describe("resolveApiKey — precedence", () => {
  it("returns the flag value first, even when env and file are set", async () => {
    process.env.MYOTP_API_KEY = "from_env";
    const cfg = await loadConfig();
    await cfg.writeConfig({ apiKey: "from_file" });
    const r = await cfg.resolveApiKey("from_flag");
    expect(r).toEqual({ apiKey: "from_flag", source: "flag" });
  });

  it("falls back to env when the flag is missing/empty", async () => {
    process.env.MYOTP_API_KEY = "from_env";
    const cfg = await loadConfig();
    await cfg.writeConfig({ apiKey: "from_file" });
    const r = await cfg.resolveApiKey(undefined);
    expect(r).toEqual({ apiKey: "from_env", source: "env" });
  });

  it("falls back to the config file when the flag and env are missing", async () => {
    const cfg = await loadConfig();
    await cfg.writeConfig({ apiKey: "from_file" });
    const r = await cfg.resolveApiKey(undefined);
    expect(r).toEqual({ apiKey: "from_file", source: "config" });
  });

  it("returns null when nothing is configured", async () => {
    const cfg = await loadConfig();
    const r = await cfg.resolveApiKey(undefined);
    expect(r).toBeNull();
  });

  it("treats whitespace-only flag and env as missing", async () => {
    process.env.MYOTP_API_KEY = "   ";
    const cfg = await loadConfig();
    const r = await cfg.resolveApiKey("   ");
    expect(r).toBeNull();
  });

  it("trims whitespace from the chosen value", async () => {
    process.env.MYOTP_API_KEY = "  from_env  ";
    const cfg = await loadConfig();
    const r = await cfg.resolveApiKey(undefined);
    expect(r?.apiKey).toBe("from_env");
  });
});

describe("resolveBaseUrl", () => {
  it("returns the flag value first", async () => {
    const cfg = await loadConfig();
    expect(cfg.resolveBaseUrl("https://x.example.com/", { baseUrl: "https://from-config" })).toBe(
      "https://x.example.com"
    );
  });

  it("strips trailing slashes", async () => {
    const cfg = await loadConfig();
    expect(cfg.resolveBaseUrl("https://x.example.com////", {})).toBe("https://x.example.com");
  });

  it("falls back to MYOTP_BASE_URL env var", async () => {
    process.env.MYOTP_BASE_URL = "https://env.example.com";
    const cfg = await loadConfig();
    expect(cfg.resolveBaseUrl(undefined, {})).toBe("https://env.example.com");
  });

  it("falls back to the config file value", async () => {
    const cfg = await loadConfig();
    expect(cfg.resolveBaseUrl(undefined, { baseUrl: "https://cfg.example.com/" })).toBe(
      "https://cfg.example.com"
    );
  });

  it("returns the default when nothing is set", async () => {
    const cfg = await loadConfig();
    expect(cfg.resolveBaseUrl(undefined, {})).toBe(cfg.DEFAULT_BASE_URL);
  });
});

describe("maskApiKey", () => {
  it("masks short keys completely", async () => {
    const cfg = await loadConfig();
    expect(cfg.maskApiKey("short")).toBe("****");
  });

  it("shows the first and last 4 characters of a longer key", async () => {
    const cfg = await loadConfig();
    const masked = cfg.maskApiKey("k_abcd1234efgh5678ijkl");
    expect(masked.startsWith("k_ab")).toBe(true);
    expect(masked.endsWith("ijkl")).toBe(true);
    expect(masked).toContain("*");
  });
});
