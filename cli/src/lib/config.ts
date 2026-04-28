import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';

export interface CliConfig {
  apiKey?: string;
  email?: string;
  accountId?: string;
  baseUrl?: string;
  /** ISO timestamp of when the config was last written. */
  updatedAt?: string;
}

export interface ResolvedApiKey {
  apiKey: string;
  source: 'flag' | 'env' | 'config';
}

export const DEFAULT_BASE_URL = 'https://api.myotp.app';
export const ONBOARDING_BASE_URL = 'https://api.myotp.app';

export function configDir(): string {
  return path.join(os.homedir(), '.myotp');
}

export function configPath(): string {
  return path.join(configDir(), 'config.json');
}

export async function configExists(): Promise<boolean> {
  try {
    await fs.access(configPath());
    return true;
  } catch {
    return false;
  }
}

export async function readConfig(): Promise<CliConfig> {
  try {
    const raw = await fs.readFile(configPath(), 'utf8');
    const parsed = JSON.parse(raw) as unknown;
    if (parsed && typeof parsed === 'object') {
      return parsed as CliConfig;
    }
    return {};
  } catch (err) {
    if ((err as NodeJS.ErrnoException).code === 'ENOENT') {
      return {};
    }
    throw err;
  }
}

export async function writeConfig(config: CliConfig): Promise<void> {
  const dir = configDir();
  await fs.mkdir(dir, { recursive: true, mode: 0o700 });

  const next: CliConfig = {
    ...config,
    updatedAt: new Date().toISOString(),
  };

  const file = configPath();
  // Write then chmod. On Windows, chmod is largely advisory but this stays a no-op
  // rather than crashing.
  await fs.writeFile(file, JSON.stringify(next, null, 2) + '\n', { mode: 0o600 });
  try {
    await fs.chmod(file, 0o600);
  } catch {
    // best effort
  }
}

export async function clearConfig(): Promise<boolean> {
  try {
    await fs.unlink(configPath());
    return true;
  } catch (err) {
    if ((err as NodeJS.ErrnoException).code === 'ENOENT') {
      return false;
    }
    throw err;
  }
}

export async function resolveApiKey(flagValue: string | undefined): Promise<ResolvedApiKey | null> {
  if (flagValue && flagValue.trim().length > 0) {
    return { apiKey: flagValue.trim(), source: 'flag' };
  }

  const fromEnv = process.env.MYOTP_API_KEY;
  if (fromEnv && fromEnv.trim().length > 0) {
    return { apiKey: fromEnv.trim(), source: 'env' };
  }

  const cfg = await readConfig();
  if (cfg.apiKey && cfg.apiKey.trim().length > 0) {
    return { apiKey: cfg.apiKey.trim(), source: 'config' };
  }

  return null;
}

export function resolveBaseUrl(flagValue: string | undefined, cfg: CliConfig): string {
  if (flagValue && flagValue.trim().length > 0) {
    return flagValue.trim().replace(/\/+$/, '');
  }
  if (process.env.MYOTP_BASE_URL && process.env.MYOTP_BASE_URL.trim().length > 0) {
    return process.env.MYOTP_BASE_URL.trim().replace(/\/+$/, '');
  }
  if (cfg.baseUrl && cfg.baseUrl.trim().length > 0) {
    return cfg.baseUrl.trim().replace(/\/+$/, '');
  }
  return DEFAULT_BASE_URL;
}

export function maskApiKey(key: string): string {
  if (key.length <= 8) return '****';
  const visible = 4;
  return `${key.slice(0, visible)}${'*'.repeat(Math.max(4, key.length - visible * 2))}${key.slice(-visible)}`;
}
