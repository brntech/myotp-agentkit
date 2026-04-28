import { z } from 'zod';
import {
  clearConfig,
  configExists,
  configPath,
  maskApiKey,
  readConfig,
  resolveBaseUrl,
  writeConfig,
} from '../lib/config.js';
import { fail } from '../lib/errors.js';
import { colors, emitJsonSuccess, logHuman } from '../lib/output.js';

const optionsSchema = z.object({
  reset: z.boolean().default(false),
  setKey: z.string().optional(),
  setBaseUrl: z.string().optional(),
  json: z.boolean().default(false),
  verbose: z.boolean().default(false),
});

export interface ConfigOptionsInput {
  reset?: boolean;
  setKey?: string;
  setBaseUrl?: string;
  json?: boolean;
  verbose?: boolean;
}

export async function runConfig(rawOpts: ConfigOptionsInput): Promise<void> {
  const parsed = optionsSchema.safeParse(rawOpts);
  if (!parsed.success) {
    fail({
      command: 'config',
      json: rawOpts.json === true,
      err: new Error(parsed.error.issues.map((i) => `${i.path.join('.')}: ${i.message}`).join('; ')),
    });
  }
  const opts = parsed.data;

  if (opts.reset) {
    const removed = await clearConfig();
    if (opts.json) {
      emitJsonSuccess('config', { reset: true, removed, path: configPath() });
      return;
    }
    if (removed) {
      logHuman(`${colors.green('OK')}  Saved config removed (${configPath()}).`);
    } else {
      logHuman(`${colors.dim('No config to remove at')} ${configPath()}.`);
    }
    return;
  }

  if (opts.setKey || opts.setBaseUrl) {
    const current = await readConfig();
    const next = { ...current };
    if (opts.setKey !== undefined) {
      const key = opts.setKey.trim();
      if (key.length === 0) {
        fail({ command: 'config', json: opts.json, err: new Error('--set-key cannot be empty.') });
      }
      next.apiKey = key;
    }
    if (opts.setBaseUrl !== undefined) {
      next.baseUrl = opts.setBaseUrl.trim().replace(/\/+$/, '');
    }
    await writeConfig(next);
    if (opts.json) {
      emitJsonSuccess('config', {
        path: configPath(),
        api_key_masked: next.apiKey ? maskApiKey(next.apiKey) : null,
        base_url: next.baseUrl ?? null,
        email: next.email ?? null,
        account_id: next.accountId ?? null,
      });
      return;
    }
    logHuman(`${colors.green('OK')}  Config saved at ${configPath()}.`);
    if (next.apiKey) {
      logHuman(`     ${colors.dim('api key :')} ${maskApiKey(next.apiKey)}`);
    }
    if (next.baseUrl) {
      logHuman(`     ${colors.dim('base url:')} ${next.baseUrl}`);
    }
    return;
  }

  // No flags: show current config.
  const exists = await configExists();
  const cfg = await readConfig();
  const baseUrl = resolveBaseUrl(undefined, cfg);

  if (opts.json) {
    emitJsonSuccess('config', {
      path: configPath(),
      exists,
      api_key_masked: cfg.apiKey ? maskApiKey(cfg.apiKey) : null,
      api_key_set: Boolean(cfg.apiKey),
      base_url: baseUrl,
      email: cfg.email ?? null,
      account_id: cfg.accountId ?? null,
      env_api_key_set: Boolean(process.env.MYOTP_API_KEY && process.env.MYOTP_API_KEY.trim()),
    });
    return;
  }

  logHuman('');
  logHuman(colors.bold('MyOTP config'));
  logHuman(`  ${colors.dim('path     :')} ${configPath()}`);
  logHuman(`  ${colors.dim('exists   :')} ${exists ? 'yes' : 'no'}`);
  logHuman(
    `  ${colors.dim('api key  :')} ${cfg.apiKey ? maskApiKey(cfg.apiKey) : colors.yellow('not set')}`
  );
  logHuman(`  ${colors.dim('base url :')} ${baseUrl}`);
  if (cfg.email) {
    logHuman(`  ${colors.dim('email    :')} ${cfg.email}`);
  }
  if (cfg.accountId) {
    logHuman(`  ${colors.dim('account  :')} ${cfg.accountId}`);
  }
  if (process.env.MYOTP_API_KEY && process.env.MYOTP_API_KEY.trim().length > 0) {
    logHuman(`  ${colors.dim('env var  :')} MYOTP_API_KEY is set (overrides config)`);
  }
  logHuman('');
  logHuman(colors.dim('Update with: npx myotp config --set-key <KEY>'));
  logHuman(colors.dim('Reset with : npx myotp config --reset'));
  logHuman('');
}
