#!/usr/bin/env node
import { Command, Option } from 'commander';
import { runInit } from './commands/init.js';
import { runTest } from './commands/test.js';
import { runVerify } from './commands/verify.js';
import { runStatus } from './commands/status.js';
import { runConfig } from './commands/config.js';
import { colors, logErrorHuman, logHuman } from './lib/output.js';

const VERSION = '0.1.0';

const program = new Command();

program
  .name('myotp')
  .description('MyOTP.App command line. Send and verify OTPs from your terminal or AI agent.')
  .version(VERSION, '-v, --version', 'show version number');

// Global options that apply to every command. Each command also accepts them so
// they can appear before or after the subcommand name.
const globalOptions = (cmd: Command): Command =>
  cmd
    .addOption(new Option('--json', 'machine-readable JSON output').default(false))
    .addOption(new Option('--verbose', 'show extra detail in human output').default(false))
    .addOption(new Option('--api-key <key>', 'API key (overrides env and config)'))
    .addOption(new Option('--base-url <url>', 'override API base URL (default https://api.myotp.app)'));

globalOptions(
  program
    .command('init')
    .description('Create a MyOTP account and save the API key locally')
    .option('--email <email>', 'work email')
    .option('--phone <phone>', 'mobile phone in international format')
    .option('--company <name>', 'company or project name')
    .option('--force', 'overwrite an existing config without prompting', false)
    .action(async (opts) => {
      await runInit(opts);
    })
);

globalOptions(
  program
    .command('test')
    .description('Send a test OTP to a phone number')
    .argument('<phone>', 'destination phone number, e.g. +14155551234')
    .option('-c, --channel <channel>', 'sms | whatsapp | telegram', 'sms')
    .option('--brand <brand>', 'sender brand override')
    .option('--otp-length <n>', 'OTP length (3-8)')
    .option('--return-otp', 'include the OTP code in the response (testing only)', false)
    .action(async (phone: string, opts) => {
      await runTest(phone, opts);
    })
);

globalOptions(
  program
    .command('verify')
    .description('Verify an OTP code that was sent to a phone number')
    .argument('<phone>', 'destination phone number, e.g. +14155551234')
    .argument('<code>', 'the OTP code the recipient entered')
    .option('--message-id <id>', 'verify by message_id instead of phone_number')
    .action(async (phone: string, code: string, opts) => {
      await runVerify(phone, code, opts);
    })
);

globalOptions(
  program
    .command('status')
    .description('Show account info: email, balance, plan, message count, trial status')
    .action(async (opts) => {
      await runStatus(opts);
    })
);

program
  .command('config')
  .description('Show or modify the saved CLI config')
  .option('--reset', 'delete the saved config file', false)
  .option('--set-key <key>', 'save an API key into the config')
  .option('--set-base-url <url>', 'save a custom API base URL into the config')
  .option('--json', 'machine-readable JSON output', false)
  .option('--verbose', 'show extra detail in human output', false)
  .action(async (opts) => {
    await runConfig(opts);
  });

program
  .command('help')
  .description('Show CLI usage')
  .action(() => {
    program.outputHelp();
  });

// Customize help footer.
program.addHelpText(
  'after',
  `\nExamples:\n` +
    `  $ npx myotp init\n` +
    `  $ npx myotp test +14155551234\n` +
    `  $ npx myotp test +14155551234 --channel whatsapp\n` +
    `  $ npx myotp verify +14155551234 123456\n` +
    `  $ npx myotp status --json\n` +
    `  $ npx myotp config --reset\n` +
    `\nEnvironment variables:\n` +
    `  MYOTP_API_KEY    API key (overrides config file)\n` +
    `  MYOTP_BASE_URL   API base URL (defaults to https://api.myotp.app)\n` +
    `\nDocs: https://myotp.app/api-reference/\n`
);

// Friendlier handling of unknown commands.
program.showHelpAfterError('(run `npx myotp help` for usage)');

program.parseAsync(process.argv).catch((err: unknown) => {
  // Anything that bubbles up here is a bug, not a user-facing API or input
  // error -- those are caught in each command and rendered properly.
  if (err instanceof Error) {
    logErrorHuman(`${colors.red('Internal error:')} ${err.message}`);
    if (process.env.MYOTP_DEBUG) {
      logErrorHuman(err.stack ?? '');
    }
  } else {
    logErrorHuman(`${colors.red('Internal error:')} ${String(err)}`);
  }
  process.exit(1);
});

// If no args were supplied, show help instead of nothing.
if (process.argv.length <= 2) {
  program.outputHelp();
  logHuman('');
}
