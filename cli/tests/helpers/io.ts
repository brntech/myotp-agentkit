import { vi } from "vitest";

export interface CapturedIo {
  stdout: string[];
  stderr: string[];
  /** All process.exit calls. The first one throws to abort execution. */
  exits: number[];
  restore: () => void;
}

/**
 * Replace stdout/stderr writers and process.exit so we can inspect what a CLI
 * command emitted without actually printing or terminating the test process.
 *
 * `process.exit(code)` is replaced with a function that throws an `ExitError`
 * with the captured code so command code stops at the first exit (mirroring
 * production semantics).
 */
export class ExitError extends Error {
  code: number;
  constructor(code: number) {
    super(`process.exit(${code})`);
    this.name = "ExitError";
    this.code = code;
  }
}

export function captureIo(): CapturedIo {
  const captured: CapturedIo = {
    stdout: [],
    stderr: [],
    exits: [],
    restore: () => undefined,
  };
  const stdoutSpy = vi
    .spyOn(process.stdout, "write")
    .mockImplementation(((chunk: string | Uint8Array) => {
      captured.stdout.push(typeof chunk === "string" ? chunk : Buffer.from(chunk).toString("utf8"));
      return true;
    }) as never);
  const stderrSpy = vi
    .spyOn(process.stderr, "write")
    .mockImplementation(((chunk: string | Uint8Array) => {
      captured.stderr.push(typeof chunk === "string" ? chunk : Buffer.from(chunk).toString("utf8"));
      return true;
    }) as never);
  const exitSpy = vi.spyOn(process, "exit").mockImplementation(((code?: number) => {
    captured.exits.push(code ?? 0);
    throw new ExitError(code ?? 0);
  }) as never);
  captured.restore = () => {
    stdoutSpy.mockRestore();
    stderrSpy.mockRestore();
    exitSpy.mockRestore();
  };
  return captured;
}

/** Concatenate captured stdout into a single string. */
export function stdout(io: CapturedIo): string {
  return io.stdout.join("");
}

/** Concatenate captured stderr into a single string. */
export function stderr(io: CapturedIo): string {
  return io.stderr.join("");
}
