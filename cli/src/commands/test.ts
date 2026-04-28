import { z } from 'zod';
import { MyOtpClient } from '../lib/api.js';
import { resolveApiKey, readConfig, resolveBaseUrl } from '../lib/config.js';
import { normalizePhone } from '../lib/phone.js';
import { fail } from '../lib/errors.js';
import { colors, emitJsonSuccess, logHuman } from '../lib/output.js';

const optionsSchema = z.object({
  channel: z.enum(['sms', 'whatsapp', 'telegram']).default('sms'),
  apiKey: z.string().optional(),
  baseUrl: z.string().optional(),
  brand: z.string().optional(),
  otpLength: z.coerce.number().int().min(3).max(8).optional(),
  json: z.boolean().default(false),
  verbose: z.boolean().default(false),
  returnOtp: z.boolean().default(false),
});

export interface TestOptionsInput {
  channel?: string;
  apiKey?: string;
  baseUrl?: string;
  brand?: string;
  otpLength?: string | number;
  json?: boolean;
  verbose?: boolean;
  returnOtp?: boolean;
}

export async function runTest(rawPhone: string, rawOpts: TestOptionsInput): Promise<void> {
  const parsed = optionsSchema.safeParse(rawOpts);
  if (!parsed.success) {
    fail({
      command: 'test',
      json: rawOpts.json === true,
      err: new Error(parsed.error.issues.map((i) => `${i.path.join('.')}: ${i.message}`).join('; ')),
    });
  }
  const opts = parsed.data;

  let phone: string;
  try {
    phone = normalizePhone(rawPhone);
  } catch (err) {
    fail({ command: 'test', json: opts.json, err });
  }

  const resolved = await resolveApiKey(opts.apiKey);
  if (!resolved) {
    fail({
      command: 'test',
      json: opts.json,
      err: new Error('No API key configured.'),
      hint: 'Run `npx myotp init` to create an account, or `npx myotp config --set-key <KEY>` to save an existing key.',
    });
  }

  const cfg = await readConfig();
  const baseUrl = resolveBaseUrl(opts.baseUrl, cfg);
  const client = new MyOtpClient({ baseUrl, apiKey: resolved.apiKey });

  if (opts.verbose && !opts.json) {
    logHuman(colors.dim(`Sending ${opts.channel} OTP to ${phone} via ${baseUrl}`));
  }

  try {
    const res = await client.generateOtp({
      phone_number: phone,
      channel: opts.channel,
      ...(opts.brand ? { brand: opts.brand } : {}),
      ...(opts.otpLength !== undefined ? { otp_length: opts.otpLength } : {}),
      ...(opts.returnOtp ? { return_otp: 'true' as const } : {}),
    });

    if (opts.json) {
      emitJsonSuccess('test', {
        phone,
        channel: opts.channel,
        message_id: res.message_id,
        status: res.status,
        date_sent: res.date_sent,
        expires_at: res.expires_at,
        cost: res.cost,
        ...(res.otp ? { otp: res.otp } : {}),
      });
      return;
    }

    logHuman('');
    logHuman(`${colors.green('OK')}  ${colors.bold('OTP queued for delivery')}`);
    logHuman(`     ${colors.dim('phone     :')} ${phone}`);
    logHuman(`     ${colors.dim('channel   :')} ${opts.channel}`);
    logHuman(`     ${colors.dim('message_id:')} ${res.message_id}`);
    logHuman(`     ${colors.dim('status    :')} ${res.status}`);
    logHuman(`     ${colors.dim('expires   :')} ${res.expires_at}`);
    logHuman(`     ${colors.dim('cost      :')} ${res.cost} credits`);
    if (res.otp) {
      logHuman(`     ${colors.dim('otp       :')} ${colors.yellow(res.otp)} ${colors.dim('(returned because --return-otp was set)')}`);
    }
    logHuman('');
    logHuman(colors.dim(`Verify with: npx myotp verify +${phone} <code>`));
  } catch (err) {
    fail({ command: 'test', json: opts.json, err });
  }
}
