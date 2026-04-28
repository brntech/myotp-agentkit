import { describe, expect, it } from "vitest";
import { normalizePhone, PhoneError } from "../../src/lib/phone.js";

describe("normalizePhone — happy path", () => {
  it("strips a leading + sign", () => {
    expect(normalizePhone("+14155551234")).toBe("14155551234");
  });

  it("returns digits-only input unchanged when already valid", () => {
    expect(normalizePhone("14155551234")).toBe("14155551234");
  });

  it("strips parentheses, dashes, dots, and spaces", () => {
    expect(normalizePhone("+1 (415) 555-1234")).toBe("14155551234");
    expect(normalizePhone("+1.415.555.1234")).toBe("14155551234");
    expect(normalizePhone("  +1-415-555-1234  ")).toBe("14155551234");
  });

  it("handles a UK number with country code", () => {
    expect(normalizePhone("+44 7911 123 456")).toBe("447911123456");
  });

  it("accepts the minimum length (7 digits)", () => {
    expect(normalizePhone("1234567")).toBe("1234567");
  });

  it("accepts the maximum length (15 digits)", () => {
    expect(normalizePhone("123456789012345")).toBe("123456789012345");
  });
});

describe("normalizePhone — rejection cases", () => {
  it("rejects an empty string", () => {
    expect(() => normalizePhone("")).toThrow(PhoneError);
    expect(() => normalizePhone("   ")).toThrow(/empty/);
  });

  it("rejects a string with no digits", () => {
    expect(() => normalizePhone("abcdef")).toThrow(/does not contain any digits/);
  });

  it("rejects a number that starts with 0 after stripping formatting", () => {
    expect(() => normalizePhone("07911 123 456")).toThrow(/leading 0/);
    expect(() => normalizePhone("(0)7911123456")).toThrow(/leading 0/);
  });

  it("rejects a number that is too short", () => {
    expect(() => normalizePhone("12345")).toThrow(/too short/);
  });

  it("rejects a number that is too long", () => {
    expect(() => normalizePhone("1234567890123456")).toThrow(/too long/);
  });

  it("throws PhoneError, not a generic Error", () => {
    let err: unknown;
    try {
      normalizePhone("0");
    } catch (e) {
      err = e;
    }
    expect(err).toBeInstanceOf(PhoneError);
    expect((err as Error).name).toBe("PhoneError");
  });
});
