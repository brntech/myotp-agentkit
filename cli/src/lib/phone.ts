/**
 * Normalize a user-supplied phone number into the digits-only format the
 * MyOTP API expects: 7-15 digits, no leading zero, no leading +.
 *
 * Accepts: "+14155551234", "14155551234", "+1 (415) 555-1234", "+1-415-555-1234"
 * Rejects: empty strings, anything shorter than 7 digits, anything starting with 0,
 * anything longer than 15 digits.
 */
export function normalizePhone(input: string): string {
  if (typeof input !== 'string') {
    throw new PhoneError('Phone number must be a string.');
  }

  const trimmed = input.trim();
  if (trimmed.length === 0) {
    throw new PhoneError('Phone number is empty.');
  }

  // Strip everything that is not a digit. This drops the leading + as well as
  // spaces, parens, dashes, dots and other formatting characters.
  const digits = trimmed.replace(/\D+/g, '');

  if (digits.length === 0) {
    throw new PhoneError(`"${input}" does not contain any digits.`);
  }

  if (digits.startsWith('0')) {
    throw new PhoneError(
      `Phone number "${input}" starts with 0. MyOTP expects an international number without the leading 0.`
    );
  }

  if (digits.length < 7) {
    throw new PhoneError(
      `Phone number "${input}" is too short (${digits.length} digits). Expected 7 to 15 digits.`
    );
  }

  if (digits.length > 15) {
    throw new PhoneError(
      `Phone number "${input}" is too long (${digits.length} digits). Expected 7 to 15 digits.`
    );
  }

  return digits;
}

export class PhoneError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'PhoneError';
  }
}
