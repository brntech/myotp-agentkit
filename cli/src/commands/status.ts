import { z } from 'zod';
import { MyOtpClient } from '../lib/api.js';
import { maskApiKey, readConfig, resolveApiKey, resolveBaseUrl } from '../lib/config.js';
import { fail } from '../lib/errors.js';
import { colors, emitJsonSuccess, logHuman } from '../lib/output.js';

const optionsSchema = z.object({
  apiKey: z.string().optional(),
  baseUrl: z.string().optional(),
  json: z.boolean().default(false),
  verbose: z.boolean().default(false),
});

export interface StatusOptionsInput {
  apiKey?: string;
  baseUrl?: string;
  json?: boolean;
  verbose?: boolean;
}

export async function runStatus(rawOpts: StatusOptionsInput): Promise<void> {
  const parsed = optionsSchema.safeParse(rawOpts);
  if (!parsed.success) {
    fail({
      command: 'status',
      json: rawOpts.json === true,
      err: new Error(parsed.error.issues.map((i) => `${i.path.join('.')}: ${i.message}`).join('; ')),
    });
  }
  const opts = parsed.data;

  const resolved = await resolveApiKey(opts.apiKey);
  if (!resolved) {
    fail({
      command: 'status',
      json: opts.json,
      err: new Error('No API key configured.'),
      hint: 'Run `npx myotp init` to create an account, or `npx myotp config --set-key <KEY>` to save an existing key.',
    });
  }

  const cfg = await readConfig();
  const baseUrl = resolveBaseUrl(opts.baseUrl, cfg);
  const client = new MyOtpClient({ baseUrl, apiKey: resolved.apiKey });

  try {
    const me = await client.me();

    // The public /me endpoint currently returns only { email }. Account
    // metrics (balance, plan, message count, trial status) live behind a
    // separate endpoint that has not yet shipped. We surface what is
    // available today and note the rest as unknown so agents can detect it.
    if (opts.json) {
      emitJsonSuccess('status', {
        email: me.email,
        api_key_source: resolved.source,
        api_key_masked: maskApiKey(resolved.apiKey),
        base_url: baseUrl,
        balance: null,
        plan: null,
        messages_sent: null,
        trial_status: null,
        notes:
          'Detailed account metrics are not exposed by the public API yet. Sign in at https://myotp.app to view balance, plan, and trial status.',
      });
      return;
    }

    logHuman('');
    logHuman(colors.bold('MyOTP account'));
    logHuman(`  ${colors.dim('email     :')} ${me.email}`);
    logHuman(`  ${colors.dim('api key   :')} ${maskApiKey(resolved.apiKey)} ${colors.dim(`(from ${resolved.source})`)}`);
    logHuman(`  ${colors.dim('base url  :')} ${baseUrl}`);
    logHuman('');
    logHuman(
      colors.dim(
        'Balance, plan, message count, and trial status are not yet exposed by the public API.'
      )
    );
    logHuman(colors.dim('See https://myotp.app/dashboard for full account details.'));
    logHuman('');
  } catch (err) {
    fail({ command: 'status', json: opts.json, err });
  }
}
