import { MyOtpApiError, MyOtpNetworkError } from './api.js';
import { PhoneError } from './phone.js';
import { colors, emitJsonError, logErrorHuman } from './output.js';

export interface FailOptions {
  command: string;
  json: boolean;
  err: unknown;
  /** Optional override for the human-friendly hint shown after the error. */
  hint?: string;
}

/**
 * Print an error in either JSON or human form and exit with code 1.
 */
export function fail(opts: FailOptions): never {
  const { command, json, err, hint } = opts;

  let message: string;
  let code = 'error';
  let status: number | undefined;
  let details: unknown;

  if (err instanceof MyOtpApiError) {
    message = err.message;
    code = `http_${err.status}`;
    status = err.status;
    details = err.body;
  } else if (err instanceof MyOtpNetworkError) {
    message = err.message;
    code = 'network_error';
    details = { endpoint: err.endpoint };
  } else if (err instanceof PhoneError) {
    message = err.message;
    code = 'invalid_phone';
  } else if (err instanceof Error) {
    message = err.message;
  } else {
    message = String(err);
  }

  if (json) {
    emitJsonError({ command, message, code, details });
  } else {
    logErrorHuman(`${colors.red('Error:')} ${message}`);
    if (status === 401) {
      logErrorHuman(
        colors.dim(
          'Hint: API key is invalid or missing. Run `npx myotp init` or `npx myotp config --set-key <KEY>`.'
        )
      );
    } else if (status === 403) {
      logErrorHuman(
        colors.dim(
          'Hint: account is not authorized for this operation. Common causes: insufficient balance, IP not on whitelist, or feature not on your plan.'
        )
      );
    } else if (code === 'network_error') {
      logErrorHuman(
        colors.dim('Hint: check your internet connection and that api.myotp.app is reachable.')
      );
    }
    if (hint) {
      logErrorHuman(colors.dim(`Hint: ${hint}`));
    }
  }

  process.exit(1);
}
