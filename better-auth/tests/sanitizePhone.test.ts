import { describe, it, expect } from "vitest";
import { sanitizePhone } from "../src/index.js";

describe("sanitizePhone", () => {
  it("strips + sign", () => {
    expect(sanitizePhone("+14155551234")).toBe("14155551234");
  });

  it("strips parens, dashes, and spaces", () => {
    expect(sanitizePhone("+1 (415) 555-1234")).toBe("14155551234");
  });

  it("strips a leading 0 from international format", () => {
    expect(sanitizePhone("0044 7700 900123")).toBe("447700900123");
  });

  it("returns null for too-short input", () => {
    expect(sanitizePhone("123")).toBeNull();
  });

  it("returns null for too-long input", () => {
    expect(sanitizePhone("1234567890123456")).toBeNull();
  });

  it("returns null for empty input", () => {
    expect(sanitizePhone("")).toBeNull();
  });

  it("returns null for input with only non-digits", () => {
    expect(sanitizePhone("abc-def")).toBeNull();
  });

  it("accepts the boundary 7-digit case", () => {
    expect(sanitizePhone("1234567")).toBe("1234567");
  });

  it("accepts the boundary 15-digit case", () => {
    expect(sanitizePhone("123456789012345")).toBe("123456789012345");
  });

  it("rejects all-zero-prefixed numbers (would fail MyOTP API)", () => {
    expect(sanitizePhone("0000123")).toBeNull();
  });
});
