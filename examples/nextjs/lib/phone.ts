/**
 * MyOTP requires phone numbers as digits-only, in international format,
 * with no leading "+" and no leading "0". Example: "14155551234".
 * The API rejects anything else with a 400.
 */
export function sanitisePhone(input: string): string {
  return input.replace(/\D+/g, "").replace(/^0+/, "");
}
