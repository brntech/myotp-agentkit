import { z } from 'zod';
import { MyOtpClient } from '../lib/api.js';
import { resolveApiKey, readConfig, resolveBaseUrl } from '../lib/config.js';
import { normalizePhone } from '../lib/phone.js';
import { fail } from '../lib/errors.js';
import { colors, emitJsonSuccess, logHuman } from '../lib/output.js';

const optionsSchema = z.object({
  apiKey: z.string().optional(),
  baseUrl: z.string().optional(),
  messageId: z.string().optional(),
  json: z.boolean().default(false),
  verbose: z.boolean().default(false),
});

export interface VerifyOptionsInput {
  apiKey?: string;
  baseUrl?: string;
  messageId?: string;
  json?: boolean;
  verbose?: boolean;
}

export async function runVerify(
  rawPhone: string,
  rawCode: string,
  rawOpts: VerifyOptionsInput
): Promise<void> {
  const parsed = optionsSchema.safeParse(rawOpts);
  if (!parsed.success) {
    fail({
      command: 'verify',
      json: rawOpts.json === true,
      err: new Error(parsed.error.issues.map((i) => `${i.path.join('.')}: ${i.message}`).join('; ')),
    });
  }
  const opts = parsed.data;

  const code = (rawCode ?? '').trim();
  if (!/^\d{3,8}$/.test(code)) {
    fail({
      command: 'verify',
      json: opts.json,
      err: new Error(`OTP code "${rawCode}" must be 3 to 8 digits.`),
    });
  }

  let phone: string;
  try {
    phone = normalizePhone(rawPhone);
  } catch (err) {
    fail({ command: 'verify', json: opts.json, err });
  }

  const resolved = await resolveApiKey(opts.apiKey);
  if (!resolved) {
    fail({
      command: 'verify',
      json: opts.json,
      err: new Error('No API key configured.'),
      hint: 'Run `npx myotp init` first, or set MYOTP_API_KEY in your environment.',
    });
  }

  const cfg = await readConfig();
  const baseUrl = resolveBaseUrl(opts.baseUrl, cfg);
  const client = new MyOtpClient({ baseUrl, apiKey: resolved.apiKey });

  if (opts.verbose && !opts.json) {
    logHuman(colors.dim(`Verifying OTP for ${phone} via ${baseUrl}`));
  }

  try {
    const res = await client.verifyOtp({
      otp: code,
      ...(opts.messageId ? { message_id: opts.messageId } : { phone_number: phone }),
    });

    if (opts.json) {
      emitJsonSuccess('verify', {
        phone,
        verified: res.status === 'success',
        status: res.status,
        message: res.message,
        ...(res.reason ? { reason: res.reason } : {}),
      });
      return;
    }

    if (res.status === 'success') {
      logHuman('');
      logHuman(`${colors.green('OK')}  ${colors.bold('OTP verified')}`);
      logHuman(`     ${colors.dim('phone:')} ${phone}`);
      logHuman('');
    } else {
      logHuman('');
      logHuman(`${colors.red('FAIL')} ${colors.bold('OTP not verified')}`);
      logHuman(`     ${colors.dim('phone  :')} ${phone}`);
      logHuman(`     ${colors.dim('reason :')} ${res.reason ?? 'unknown'}`);
      logHuman(`     ${colors.dim('message:')} ${res.message}`);
      logHuman('');
      // Verification failure is a "soft" failure -- the API call worked but
      // the code was wrong. Use exit code 2 so scripts can distinguish from
      // a hard error.
      process.exit(2);
    }
  } catch (err) {
    fail({ command: 'verify', json: opts.json, err });
  }
}
