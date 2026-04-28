import pc from 'picocolors';

export interface OutputOptions {
  json: boolean;
  verbose: boolean;
}

export interface CommandContext {
  output: OutputOptions;
}

export function printJson(value: unknown): void {
  process.stdout.write(JSON.stringify(value, null, 2) + '\n');
}

export function emitJsonError(opts: {
  command: string;
  message: string;
  code?: string;
  details?: unknown;
}): void {
  printJson({
    ok: false,
    command: opts.command,
    error: {
      code: opts.code ?? 'error',
      message: opts.message,
      details: opts.details,
    },
  });
}

export function emitJsonSuccess(command: string, data: unknown): void {
  printJson({
    ok: true,
    command,
    data,
  });
}

export const colors = {
  bold: pc.bold,
  dim: pc.dim,
  green: pc.green,
  red: pc.red,
  yellow: pc.yellow,
  cyan: pc.cyan,
  magenta: pc.magenta,
  blue: pc.blue,
};

export function logHuman(message: string): void {
  process.stdout.write(message + '\n');
}

export function logErrorHuman(message: string): void {
  process.stderr.write(message + '\n');
}
