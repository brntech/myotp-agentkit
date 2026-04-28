import prompts from 'prompts';
import { z } from 'zod';
import { MyOtpApiError, MyOtpClient } from '../lib/api.js';
import {
  configExists,
  configPath,
  ONBOARDING_BASE_URL,
  readConfig,
  resolveBaseUrl,
  writeConfig,
} from '../lib/config.js';
import { fail } from '../lib/errors.js';
import { normalizePhone } from '../lib/phone.js';
import { colors, emitJsonSuccess, logHuman } from '../lib/output.js';

const optionsSchema = z.object({
  email: z.string().email().optional(),
  phone: z.string().optional(),
  company: z.string().optional(),
  baseUrl: z.string().optional(),
  force: z.boolean().default(false),
  json: z.boolean().default(false),
  verbose: z.boolean().default(false),
});

export interface InitOptionsInput {
  email?: string;
  phone?: string;
  company?: string;
  baseUrl?: string;
  force?: boolean;
  json?: boolean;
  verbose?: boolean;
}

const SIGNUP_FALLBACK_MESSAGE =
  'Programmatic onboarding is in development. Visit https://myotp.app/sign-up to create an account, then run `npx myotp config --set-key <KEY>`.';

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

  // Existing config check.
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

  // Gather required fields.
  let email = opts.email;
  let rawPhone = opts.phone;
  let company = opts.company;

  if (opts.json) {
    if (!email || !rawPhone || !company) {
      fail({
        command: 'init',
        json: true,
        err: new Error(
          'In --json mode, --email, --phone, and --company must all be provided as flags.'
        ),
      });
    }
  } else {
    if (!email || !rawPhone || !company) {
      logHuman('');
      logHuman(colors.bold('MyOTP onboarding'));
      logHuman(colors.dim('Tell us a few things to create your account.'));
      logHuman('');
    }
    if (!email) {
      const res = await prompts(
        {
          type: 'text',
          name: 'value',
          message: 'Work email',
          validate: (v: string) =>
            /.+@.+\..+/.test(v.trim()) ? true : 'Please enter a valid email address.',
        },
        {
          onCancel: () => {
            logHuman(colors.dim('Aborted.'));
            process.exit(130);
          },
        }
      );
      email = String(res.value).trim();
    }
    if (!rawPhone) {
      const res = await prompts(
        {
          type: 'text',
          name: 'value',
          message: 'Mobile phone (international format, e.g. +14155551234)',
          validate: (v: string) => {
            try {
              normalizePhone(v);
              return true;
            } catch (err) {
              return (err as Error).message;
            }
          },
        },
        {
          onCancel: () => {
            logHuman(colors.dim('Aborted.'));
            process.exit(130);
          },
        }
      );
      rawPhone = String(res.value).trim();
    }
    if (!company) {
      const res = await prompts(
        {
          type: 'text',
          name: 'value',
          message: 'Company or project name',
          validate: (v: string) => (v.trim().length > 0 ? true : 'Required.'),
        },
        {
          onCancel: () => {
            logHuman(colors.dim('Aborted.'));
            process.exit(130);
          },
        }
      );
      company = String(res.value).trim();
    }
  }

  let phone: string;
  try {
    phone = normalizePhone(rawPhone!);
  } catch (err) {
    fail({ command: 'init', json: opts.json, err });
  }

  const cfg = await readConfig();
  const baseUrl = resolveBaseUrl(opts.baseUrl, { ...cfg, baseUrl: cfg.baseUrl ?? ONBOARDING_BASE_URL });
  const client = new MyOtpClient({ baseUrl });

  if (!opts.json) {
    logHuman('');
    logHuman(colors.dim(`Registering account at ${baseUrl}/v1/agent/register ...`));
  }

  let registerRes: Awaited<ReturnType<MyOtpClient['register']>> | null = null;
  try {
    registerRes = await client.register({
      email: email!,
      phone,
      company_name: company!,
      source: 'cli',
    });
  } catch (err) {
    if (err instanceof MyOtpApiError && err.status === 404) {
      // Onboarding API not shipped yet -- fall back to manual sign-up.
      if (opts.json) {
        emitJsonSuccess('init', {
          status: 'fallback',
          message: SIGNUP_FALLBACK_MESSAGE,
          signup_url: 'https://myotp.app/sign-up',
          set_key_command: 'npx myotp config --set-key <KEY>',
        });
        return;
      }
      logHuman('');
      logHuman(colors.yellow(SIGNUP_FALLBACK_MESSAGE));
      logHuman('');
      return;
    }
    fail({ command: 'init', json: opts.json, err });
  }

  // Some onboarding API designs return the API key right away (after email/phone
  // verification flows are complete on their side). Others return only an
  // account_id and a follow-up step. Handle both.
  if (registerRes && registerRes.api_key) {
    await writeConfig({
      apiKey: registerRes.api_key,
      email: email,
      accountId: registerRes.account_id,
      baseUrl: baseUrl === 'https://api.myotp.app' ? undefined : baseUrl,
    });
    if (opts.json) {
      emitJsonSuccess('init', {
        status: 'active',
        account_id: registerRes.account_id,
        config_path: configPath(),
        api_key_set: true,
      });
      return;
    }
    logHuman('');
    logHuman(`${colors.green('OK')}  ${colors.bold('Account ready.')} API key saved to ${configPath()}.`);
    logHuman('');
    logHuman(colors.dim('Try it now:'));
    logHuman(`  npx myotp test +${phone}`);
    logHuman('');
    return;
  }

  // Account created but not yet active: prompt for OTP codes.
  if (opts.json) {
    emitJsonSuccess('init', {
      status: 'pending_verification',
      account_id: registerRes?.account_id,
      next: registerRes?.next ?? 'Check your email and SMS for verification codes.',
    });
    return;
  }

  logHuman('');
  logHuman(`${colors.green('OK')}  Account created. Verification codes have been sent to your email and phone.`);
  logHuman(colors.dim('Enter the codes below to activate your account.'));
  logHuman('');

  const emailCodeRes = await prompts(
    {
      type: 'text',
      name: 'value',
      message: 'Email verification code',
      validate: (v: string) => (/^\d{3,8}$/.test(v.trim()) ? true : '3-8 digits.'),
    },
    {
      onCancel: () => {
        logHuman(colors.dim('Aborted. You can finish verification at https://myotp.app/dashboard.'));
        process.exit(130);
      },
    }
  );
  const phoneCodeRes = await prompts(
    {
      type: 'text',
      name: 'value',
      message: 'Phone verification code',
      validate: (v: string) => (/^\d{3,8}$/.test(v.trim()) ? true : '3-8 digits.'),
    },
    {
      onCancel: () => {
        logHuman(colors.dim('Aborted. You can finish verification at https://myotp.app/dashboard.'));
        process.exit(130);
      },
    }
  );

  // The onboarding endpoints used here mirror the strategy doc. If they are not
  // yet live, we fall through to the same friendly fallback message rather
  // than crashing.
  let verifiedKey: string | undefined;
  try {
    const emailVerify = await client.verifyEmail({
      email: email!,
      code: String(emailCodeRes.value).trim(),
    });
    if (emailVerify && emailVerify.api_key) {
      verifiedKey = emailVerify.api_key;
    }
    await client.verifyPhone({
      phone,
      code: String(phoneCodeRes.value).trim(),
    });
  } catch (err) {
    if (err instanceof MyOtpApiError && err.status === 404) {
      logHuman('');
      logHuman(colors.yellow(SIGNUP_FALLBACK_MESSAGE));
      logHuman('');
      return;
    }
    fail({ command: 'init', json: opts.json, err });
  }

  if (verifiedKey) {
    await writeConfig({
      apiKey: verifiedKey,
      email,
      accountId: registerRes?.account_id,
      baseUrl: baseUrl === 'https://api.myotp.app' ? undefined : baseUrl,
    });
    logHuman('');
    logHuman(`${colors.green('OK')}  Account active. API key saved to ${configPath()}.`);
    logHuman('');
    logHuman(colors.dim('Send your first OTP:'));
    logHuman(`  npx myotp test +${phone}`);
    logHuman('');
  } else {
    logHuman('');
    logHuman(colors.yellow('Verification submitted, but no API key was returned. Check your dashboard at https://myotp.app/dashboard.'));
    logHuman('');
  }
}
