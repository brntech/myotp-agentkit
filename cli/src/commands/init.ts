import prompts from 'prompts';
import { z } from 'zod';
import { MyOtpApiError, MyOtpClient } from '../lib/api.js';
import {
  configExists,
  configPath,
  DEFAULT_BASE_URL,
  readConfig,
  resolveBaseUrl,
  writeConfig,
} from '../lib/config.js';
import { fail } from '../lib/errors.js';
import { colors, emitJsonSuccess, logHuman } from '../lib/output.js';

const SIGNUP_URL = 'https://myotp.app/sign-up/';
const DASHBOARD_URL = 'https://myotp.app/login/';

const optionsSchema = z.object({
  key: z.string().optional(),
  baseUrl: z.string().optional(),
  force: z.boolean().default(false),
  json: z.boolean().default(false),
  verbose: z.boolean().default(false),
});

export interface InitOptionsInput {
  key?: string;
  baseUrl?: string;
  force?: boolean;
  json?: boolean;
  verbose?: boolean;
}

export async function runInit(rawOpts: InitOptionsInput): Promise<void> {
  const parsed = optionsSchema.safeParse(rawOpts);
  if (!parsed.success) {
    fail({
      command: 'init',
      json: rawOpts.json === true,
      err: new Error(parsed.error.issues.map((i) => `${i.path.join('.')}: ${i.message}`).join('; ')),
    });
  }
  const opts = parsed.data;

  if (await configExists()) {
    if (!opts.force) {
      if (opts.json) {
        fail({
          command: 'init',
          json: true,
          err: new Error('Existing config found. Pass --force to overwrite.'),
          hint: configPath(),
        });
      }
      const { ok } = await prompts(
        {
          type: 'confirm',
          name: 'ok',
          message: `An existing config was found at ${configPath()}. Overwrite?`,
          initial: false,
        },
        {
          onCancel: () => {
            logHuman(colors.dim('Aborted.'));
            process.exit(130);
          },
        }
      );
      if (!ok) {
        logHuman(colors.dim('Init cancelled. Existing config preserved.'));
        return;
      }
    }
  }

  const cfg = await readConfig();
  const baseUrl = resolveBaseUrl(opts.baseUrl, { ...cfg, baseUrl: cfg.baseUrl ?? DEFAULT_BASE_URL });

  let apiKey = opts.key?.trim();

  if (!apiKey) {
    if (opts.json) {
      fail({
        command: 'init',
        json: true,
        err: new Error('In --json mode, --key <API_KEY> must be provided. Sign up at ' + SIGNUP_URL + ' first.'),
      });
    }
    logHuman('');
    logHuman(colors.bold('MyOTP setup'));
    logHuman('');
    logHuman('Signup is human-driven and takes about 60 seconds:');
    logHuman('  1. Open ' + colors.cyan(SIGNUP_URL));
    logHuman('  2. Verify your email and phone');
    logHuman('  3. Get an API key from ' + colors.cyan(DASHBOARD_URL));
    logHuman(colors.dim('     (15 free trial credits, no card required)'));
    logHuman('');

    const res = await prompts(
      {
        type: 'password',
        name: 'value',
        message: 'Paste your API key',
        validate: (v: string) => (v.trim().length > 0 ? true : 'API key is required.'),
      },
      {
        onCancel: () => {
          logHuman(colors.dim('Aborted.'));
          process.exit(130);
        },
      }
    );
    apiKey = String(res.value).trim();
  }

  if (!apiKey) {
    fail({ command: 'init', json: opts.json, err: new Error('No API key provided.') });
  }

  const client = new MyOtpClient({ baseUrl, apiKey });

  if (!opts.json) {
    logHuman('');
    logHuman(colors.dim('Validating key against ' + baseUrl + '/me ...'));
  }

  let email: string | undefined;
  try {
    const me = await client.me();
    email = me?.email;
  } catch (err) {
    if (err instanceof MyOtpApiError && err.status === 401) {
      fail({
        command: 'init',
        json: opts.json,
        err: new Error('API key was rejected (401). Double-check the key in your dashboard at ' + DASHBOARD_URL),
      });
    }
    if (err instanceof MyOtpApiError && err.status === 403) {
      fail({
        command: 'init',
        json: opts.json,
        err: new Error('API key valid but request was blocked (403). Most likely your IP is not whitelisted on the key. Add this machine\'s IP (or "*" for development) in the dashboard.'),
      });
    }
    fail({ command: 'init', json: opts.json, err });
  }

  await writeConfig({
    apiKey,
    email,
    baseUrl: baseUrl === DEFAULT_BASE_URL ? undefined : baseUrl,
  });

  if (opts.json) {
    emitJsonSuccess('init', {
      status: 'active',
      email,
      config_path: configPath(),
      api_key_set: true,
    });
    return;
  }

  logHuman('');
  logHuman(`${colors.green('OK')}  Saved to ${configPath()}${email ? ` (${email})` : ''}.`);
  logHuman('');
  logHuman(colors.dim('Try it now:'));
  logHuman('  npx myotp test +14155551234');
  logHuman('');
}
