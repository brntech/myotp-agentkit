import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { z } from 'zod';
import { MyOtpClient, type TopupQuoteResponse } from '../lib/api.js';
import { readConfig, resolveApiKey, resolveBaseUrl } from '../lib/config.js';
import { fail } from '../lib/errors.js';
import { colors, emitJsonSuccess, logHuman } from '../lib/output.js';

const CREDITS_ERROR = 'credits must be an integer between 25 and 50000';

const optionsSchema = z.object({
  apiKey: z.string().optional(),
  baseUrl: z.string().optional(),
  credits: z.union([z.string(), z.number()]).optional(),
  quote: z.boolean().default(false),
  method: z.enum(['usdc', 'card']).default('usdc'),
  json: z.boolean().default(false),
  verbose: z.boolean().default(false),
});

export interface TopupOptionsInput {
  apiKey?: string;
  baseUrl?: string;
  credits?: string | number;
  quote?: boolean;
  method?: string;
  json?: boolean;
  verbose?: boolean;
}

function parseCredits(value: string | number | undefined, json: boolean): number {
  const credits = value === undefined ? 100 : typeof value === 'number' ? value : Number(value);
  if (
    (typeof value === 'string' && value.trim().length === 0) ||
    !Number.isInteger(credits) ||
    credits < 25 ||
    credits > 50_000
  ) {
    fail({ command: 'topup', json, err: new Error(CREDITS_ERROR) });
  }
  return credits;
}

function printQuote(quote: TopupQuoteResponse): void {
  logHuman('');
  logHuman(colors.bold('Top-up quote'));
  logHuman(`  ${colors.dim('credits    :')} ${quote.credits.toLocaleString('en-US')}`);
  logHuman(`  ${colors.dim('amount     :')} $${quote.amount_usd} ${quote.currency.toUpperCase()}`);
  logHuman(`  ${colors.dim('price      :')} $${quote.price_per_credit_usd.toFixed(2)} per credit`);
  logHuman(`  ${colors.dim('methods    :')} ${quote.methods.join(', ')}`);
  logHuman(
    `  ${colors.dim('per call   :')} ${quote.min_credits.toLocaleString('en-US')} to ${quote.max_credits.toLocaleString('en-US')} credits`
  );
  logHuman(`  ${colors.dim('card cap   :')} $100 per account per rolling 24 hours`);
  logHuman(`  ${colors.dim('USDC cap   :')} uncapped (maximum ${quote.max_credits.toLocaleString('en-US')} credits per call)`);
  logHuman('');
}

/**
 * Resolve how to run `npx` without a shell. On Windows the `npx` on PATH is a .cmd shim, which
 * spawn() cannot execute directly, so run npm's own npx-cli.js with the current node binary.
 * Argv stays discrete either way, so the API key and JSON body are never shell-parsed.
 */
export function npxInvocation(args: string[]): { command: string; args: string[] } {
  if (process.platform === 'win32') {
    const npxCli = path.join(path.dirname(process.execPath), 'node_modules', 'npm', 'bin', 'npx-cli.js');
    if (existsSync(npxCli)) return { command: process.execPath, args: [npxCli, ...args] };
    return { command: 'npx.cmd', args };
  }
  return { command: 'npx', args };
}

function waitForWallet(args: string[]): Promise<number | null> {
  return new Promise((resolve, reject) => {
    const inv = npxInvocation(args);
    const child = spawn(inv.command, inv.args, { stdio: 'inherit' });
    child.once('error', reject);
    child.once('close', (code) => resolve(code));
  });
}

export async function runTopup(rawOpts: TopupOptionsInput): Promise<void> {
  const parsed = optionsSchema.safeParse(rawOpts);
  if (!parsed.success) {
    fail({
      command: 'topup',
      json: rawOpts.json === true,
      err: new Error(parsed.error.issues.map((i) => `${i.path.join('.')}: ${i.message}`).join('; ')),
    });
  }
  const opts = parsed.data;
  const credits = parseCredits(opts.credits, opts.json);

  const cfg = await readConfig();
  const baseUrl = resolveBaseUrl(opts.baseUrl, cfg);

  let apiKey = '';
  if (!opts.quote) {
    const resolved = await resolveApiKey(opts.apiKey);
    if (!resolved) {
      fail({
        command: 'topup',
        json: false,
        err: new Error('No API key configured.'),
        hint: 'Run `npx @myotp/cli init` first, or set MYOTP_API_KEY in your environment.',
      });
    }
    apiKey = resolved.apiKey;
  }

  const client = new MyOtpClient({ baseUrl });
  if (opts.json && !opts.quote) {
    fail({ command: 'topup', json: true, err: new Error('--json is only available with --quote; payment runs interactively') });
  }

  let quote: TopupQuoteResponse;
  try {
    quote = await client.getTopupQuote(credits);
  } catch (err) {
    fail({ command: 'topup', json: opts.quote && opts.json, err });
  }

  if (opts.quote && opts.json) {
    emitJsonSuccess('topup', {
      ...quote,
      cap_rules: {
        card: '$100 per account per rolling 24 hours',
        usdc: 'uncapped',
      },
    });
    return;
  }

  printQuote(quote);
  if (opts.quote) {
    return;
  }

  const url = `${baseUrl}/v1/topup`;
  const body = JSON.stringify({ credits });
  const isUsdc = opts.method === 'usdc';
  const tool = isUsdc ? 'mppx@0.9.2 (USDC wallet)' : '@stripe/link-cli (card wallet)';
  const args = isUsdc
    ? [
        '-y',
        'mppx@0.9.2',
        url,
        '-X',
        'POST',
        '-H',
        `x-api-key: ${apiKey}`,
        '-H',
        'content-type: application/json',
        '-d',
        body,
      ]
    : [
        '-y',
        '@stripe/link-cli',
        'mpp',
        'pay',
        url,
        '-X',
        'POST',
        '-d',
        body,
        '-H',
        `x-api-key: ${apiKey}`,
        '--context',
        'MyOTP credits',
      ];

  logHuman(`Paying $${quote.amount_usd} ${quote.currency.toUpperCase()} with ${tool}...`);

  let exitCode: number | null;
  try {
    exitCode = await waitForWallet(args);
  } catch (err) {
    fail({
      command: 'topup',
      json: false,
      err,
      hint: isUsdc
        ? 'Wallet not set up? Run `npx mppx account create`, then try again.'
        : 'Wallet not set up? Run `npx @stripe/link-cli auth login`, then try again.',
    });
  }

  if (exitCode !== 0) {
    fail({
      command: 'topup',
      json: false,
      err: new Error(`${isUsdc ? 'mppx' : '@stripe/link-cli'} exited with code ${exitCode ?? 'unknown'}.`),
      hint: isUsdc
        ? 'Wallet not set up? Run `npx mppx account create`, then try again.'
        : 'Wallet not set up? Run `npx @stripe/link-cli auth login`, then try again.',
    });
  }
}
