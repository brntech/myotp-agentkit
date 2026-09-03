import { z } from 'zod';
import { MyOtpApiError, MyOtpClient, type RegisterResponse } from '../lib/api.js';
import {
  configPath,
  DEFAULT_BASE_URL,
  readConfig,
  resolveBaseUrl,
  writeConfig,
  maskApiKey,
} from '../lib/config.js';
import { fail } from '../lib/errors.js';
import { colors, emitJsonError, emitJsonSuccess, logErrorHuman, logHuman } from '../lib/output.js';

const optionsSchema = z.object({
  email: z.string().trim().email(),
  name: z.string().trim().min(1).max(64).optional(),
  baseUrl: z.string().optional(),
  json: z.boolean().default(false),
  verbose: z.boolean().default(false),
  force: z.boolean().default(false),
});

export interface RegisterOptionsInput {
  email?: string;
  name?: string;
  baseUrl?: string;
  json?: boolean;
  verbose?: boolean;
  force?: boolean;
}

function nestedDetailMessage(body: unknown): string | undefined {
  if (!body || typeof body !== 'object') return undefined;
  const detail = (body as Record<string, unknown>).detail;
  if (!detail || typeof detail !== 'object') return undefined;
  const message = (detail as Record<string, unknown>).message;
  return typeof message === 'string' && message.trim().length > 0 ? message.trim() : undefined;
}

function friendlyRegistrationError(err: MyOtpApiError): MyOtpApiError {
  let message: string;
  switch (err.status) {
    case 400:
      message = nestedDetailMessage(err.body) ??
        'invalid registration request; provide a valid email and a name of 64 characters or fewer';
      break;
    case 409:
      message = 'an account with this email already exists; log in at myotp.app or use its API key';
      break;
    case 429:
      message = 'too many registration attempts from this address; try again after 24 hours';
      break;
    case 503:
      message = 'account creation is temporarily unavailable; try again later';
      break;
    default:
      return err;
  }

  return new MyOtpApiError(message, {
    status: err.status,
    body: err.body,
    endpoint: err.endpoint,
  });
}

export async function runRegister(rawOpts: RegisterOptionsInput): Promise<void> {
  const parsed = optionsSchema.safeParse(rawOpts);
  if (!parsed.success) {
    fail({
      command: 'register',
      json: rawOpts.json === true,
      err: new Error(parsed.error.issues.map((i) => `${i.path.join('.')}: ${i.message}`).join('; ')),
    });
  }
  const opts = parsed.data;

  const cfg = await readConfig();
  if (cfg.apiKey && !opts.force) {
    fail({
      command: 'register',
      json: opts.json,
      err: new Error(
        `A MyOTP API key is already configured (${maskApiKey(cfg.apiKey)}). Registering would replace it. ` +
          'Re-run with --force to create a new account anyway, or use `myotp config` to inspect the current one.'
      ),
    });
  }
  const baseUrl = resolveBaseUrl(opts.baseUrl, cfg);
  const client = new MyOtpClient({ baseUrl });

  let account: RegisterResponse;
  try {
    account = await client.register({
      email: opts.email,
      ...(opts.name ? { name: opts.name } : {}),
    });
  } catch (err) {
    fail({
      command: 'register',
      json: opts.json,
      err: err instanceof MyOtpApiError ? friendlyRegistrationError(err) : err,
    });
  }
  if (
    typeof account?.api_key !== 'string' ||
    !/^[A-Za-z0-9_-]{32}$/.test(account.api_key) ||
    typeof account.account_id !== 'string' ||
    !account.account_id
  ) {
    fail({
      command: 'register',
      json: opts.json,
      err: new Error('The server returned a malformed registration response (missing api_key or account_id). Nothing was saved.'),
    });
  }

  let configSaved = true;
  let configErrorMessage = '';
  try {
    await writeConfig({
      apiKey: account.api_key,
      email: account.email,
      accountId: account.account_id,
      baseUrl: baseUrl === DEFAULT_BASE_URL ? undefined : baseUrl,
    });
  } catch (err) {
    configSaved = false;
    configErrorMessage = err instanceof Error ? err.message : String(err);
  }

  if (opts.json) {
    if (!configSaved) {
      emitJsonError({
        command: 'register',
        code: 'config_write_error',
        message: 'account created, but its API key could not be saved; preserve registration_response.api_key now',
        details: {
          config_path: configPath(),
          reason: configErrorMessage,
          registration_response: account,
        },
      });
      process.exitCode = 1;
      return;
    }
    emitJsonSuccess('register', account);
    return;
  }

  logHuman('');
  logHuman(`${colors.green('OK')}  Account created.`);
  logHuman(`  ${colors.dim('account id :')} ${account.account_id}`);
  logHuman(`  ${colors.dim('balance    :')} ${account.balance} credits`);
  logHuman(`  ${colors.dim(configSaved ? 'saved to   :' : 'not saved :')} ${configPath()}`);
  logHuman('');
  logHuman(`${colors.bold('API key (shown once):')} ${account.api_key}`);
  logHuman('');
  logHuman(`A confirmation email was sent to ${account.email}.`);
  logHuman('');
  logHuman(colors.bold('Next steps:'));
  logHuman('  1. See top-up options: myotp topup --quote');
  logHuman('  2. Click the link in the confirmation email to unlock card top-ups.');
  logHuman('');

  if (!configSaved) {
    logErrorHuman(
      `${colors.red('Error:')} Account created, but the API key could not be saved. Copy the key shown above now; registration cannot be retried for this email.`
    );
    logErrorHuman(colors.dim(`Reason: ${configErrorMessage}`));
    process.exitCode = 1;
  }
}
