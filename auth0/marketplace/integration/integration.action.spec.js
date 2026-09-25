const { makeEventMock } = require("../__mocks__/event-phone-message");

const { onExecuteSendPhoneMessage } = require("./integration.action");

const KEY = "k".repeat(32);

const jsonResponse = (status, body) => ({
  ok: status >= 200 && status < 300,
  status,
  statusText: "",
  body: null,
  text: async () => (typeof body === "string" ? body : JSON.stringify(body)),
});

const makeEvent = (options = {}, secrets = {}, configuration = {}) => {
  const event = makeEventMock();
  event.secrets = { MYOTP_API_KEY: KEY, ...secrets };
  event.configuration = configuration;
  event.message_options = {
    ...event.message_options,
    action: "second-factor-authentication",
    message_type: "sms",
    recipient: "+14155550123",
    code: "482913",
    ...options,
  };
  return event;
};

const sentBody = () => JSON.parse(global.fetch.mock.calls[0][1].body);

// A fetch that never answers and rejects the way fetch does when its signal aborts.
function hangUntilAbort(url, init) {
  return new Promise((resolve, reject) => {
    init.signal.addEventListener("abort", () => {
      const e = new Error("aborted");
      e.name = "AbortError";
      reject(e);
    });
  });
}

describe("MyOTP Send Phone Message Action", () => {
  let originalFetch;

  beforeEach(() => {
    originalFetch = global.fetch;
    global.fetch = jest.fn(async () => jsonResponse(200, { message_id: "m1", status: "accepted" }));
  });

  afterEach(() => {
    global.fetch = originalFetch;
    jest.useRealTimers();
    jest.clearAllMocks();
  });

  describe("delivery", () => {
    it("posts Auth0's code to /generate_otp with the API key", async () => {
      await onExecuteSendPhoneMessage(makeEvent());

      expect(global.fetch).toHaveBeenCalledTimes(1);
      const [url, init] = global.fetch.mock.calls[0];
      expect(url).toBe("https://api.myotp.app/generate_otp");
      expect(init.method).toBe("POST");
      expect(init.headers["X-API-Key"]).toBe(KEY);
      expect(init.headers["Content-Type"]).toBe("application/json");
      expect(sentBody()).toEqual({
        phone_number: "14155550123",
        channel: "sms",
        otp_code: "482913",
        force_send: true,
      });
    });

    it("works for enrollment as well as challenges", async () => {
      await onExecuteSendPhoneMessage(makeEvent({ action: "enrollment", code: "1234" }));
      expect(sentBody().otp_code).toBe("1234");
    });

    it("strips formatting from the recipient", async () => {
      await onExecuteSendPhoneMessage(makeEvent({ recipient: "+44 7700 900123" }));
      expect(sentBody().phone_number).toBe("447700900123");
    });

    it("takes the channel from configuration, then secrets, defaulting to sms", async () => {
      await onExecuteSendPhoneMessage(makeEvent({}, {}, { MYOTP_CHANNEL: "whatsapp" }));
      expect(sentBody().channel).toBe("whatsapp");

      global.fetch.mockClear();
      await onExecuteSendPhoneMessage(makeEvent({}, { MYOTP_CHANNEL: "Telegram" }));
      expect(sentBody().channel).toBe("telegram");
    });

    it("never sends to any host but api.myotp.app", async () => {
      await onExecuteSendPhoneMessage(
        makeEvent(
          {},
          { MYOTP_BASE_URL: "http://collector.invalid" },
          { MYOTP_BASE_URL: "http://collector.invalid" }
        )
      );
      expect(global.fetch.mock.calls[0][0]).toBe("https://api.myotp.app/generate_otp");
    });
  });

  describe("refusals", () => {
    it("refuses voice messages without calling MyOTP", async () => {
      await expect(onExecuteSendPhoneMessage(makeEvent({ message_type: "voice" }))).rejects.toThrow(
        /no voice channel/
      );
      expect(global.fetch).not.toHaveBeenCalled();
    });

    it("refuses a missing API key", async () => {
      await expect(onExecuteSendPhoneMessage(makeEvent({}, { MYOTP_API_KEY: "" }))).rejects.toThrow(
        "MyOTP configuration error: MYOTP_API_KEY secret is missing"
      );
      expect(global.fetch).not.toHaveBeenCalled();
    });

    it("refuses an unknown channel", async () => {
      await expect(
        onExecuteSendPhoneMessage(makeEvent({}, {}, { MYOTP_CHANNEL: "fax" }))
      ).rejects.toThrow(/MYOTP_CHANNEL must be one of sms, whatsapp, telegram/);
      expect(global.fetch).not.toHaveBeenCalled();
    });

    it("refuses a non-numeric code and a 3-digit code on telegram", async () => {
      await expect(onExecuteSendPhoneMessage(makeEvent({ code: "ab12cd" }))).rejects.toThrow(
        /numeric code/
      );
      await expect(
        onExecuteSendPhoneMessage(makeEvent({ code: "123" }, {}, { MYOTP_CHANNEL: "telegram" }))
      ).rejects.toThrow(/4 to 8 digits/);
      expect(global.fetch).not.toHaveBeenCalled();
    });

    it("masks an invalid recipient in the error", async () => {
      await expect(onExecuteSendPhoneMessage(makeEvent({ recipient: "+1\n555" }))).rejects.toThrow(
        "MyOTP configuration error: recipient **55 is not a valid E.164 number"
      );
    });
  });

  describe("errors from MyOTP", () => {
    it("throws with the status and MyOTP's message", async () => {
      global.fetch.mockResolvedValueOnce(
        jsonResponse(403, {
          error: {
            http_code: 403,
            message:
              "Access from this IP address is not allowed. We saw your request coming from 203.0.113.9.",
          },
        })
      );
      await expect(onExecuteSendPhoneMessage(makeEvent())).rejects.toThrow(
        "MyOTP responded 403: Access from this IP address is not allowed. We saw your request coming from 203.0.113.9."
      );
    });

    it("throws on 5xx and on network errors", async () => {
      global.fetch.mockResolvedValueOnce(jsonResponse(502, "Bad Gateway"));
      await expect(onExecuteSendPhoneMessage(makeEvent())).rejects.toThrow(
        "MyOTP responded 502: Bad Gateway"
      );

      global.fetch.mockRejectedValueOnce(new Error("fetch failed"));
      await expect(onExecuteSendPhoneMessage(makeEvent())).rejects.toThrow(
        "MyOTP request failed: fetch failed"
      );
    });

    it("redacts the number, code, key and hex tokens from error text", async () => {
      const echo = `phone 14155550123 masked *********23 code 482913 key ${KEY} tok deadbeefdeadbeefdeadbeefdeadbeef ok`;
      global.fetch.mockResolvedValueOnce(jsonResponse(400, { error: { message: echo } }));
      await expect(onExecuteSendPhoneMessage(makeEvent())).rejects.toThrow(
        "MyOTP responded 400: phone [redacted] masked [redacted] code [redacted] key [redacted] tok [redacted] ok"
      );

      global.fetch.mockRejectedValueOnce(new Error(`connect to 14155550123 with ${KEY}`));
      await expect(onExecuteSendPhoneMessage(makeEvent())).rejects.toThrow(
        "MyOTP request failed: connect to [redacted] with [redacted]"
      );
    });

    it("strips control characters and caps provider text", async () => {
      global.fetch.mockResolvedValueOnce(
        jsonResponse(400, { error: { message: `bad\r\nfake log line ${"z".repeat(500)}` } })
      );
      const err = await onExecuteSendPhoneMessage(makeEvent()).catch((e) => e);
      expect(/[\r\n]/.test(err.message)).toBe(false);
      expect(err.message).toMatch(/^MyOTP responded 400: bad fake log line z+$/);
      expect(err.message.length).toBeLessThanOrEqual("MyOTP responded 400: ".length + 300);
    });

    it("keeps a huge error body out of the log", async () => {
      global.fetch.mockResolvedValueOnce(jsonResponse(500, "x".repeat(50000)));
      const err = await onExecuteSendPhoneMessage(makeEvent()).catch((e) => e);
      expect(err.message).toMatch(/^MyOTP responded 500: x+$/);
      expect(err.message.length).toBeLessThanOrEqual("MyOTP responded 500: ".length + 300);
    });

    it("gives up after 8 seconds", async () => {
      jest.useFakeTimers();
      global.fetch.mockImplementationOnce(hangUntilAbort);
      const pending = onExecuteSendPhoneMessage(makeEvent());
      jest.advanceTimersByTime(8000);
      await expect(pending).rejects.toThrow("MyOTP request failed: timed out after 8000ms");
    });

    it("gives up after 8 seconds when the body stalls after the headers", async () => {
      jest.useFakeTimers();
      const state = { readStarted: false, released: false };
      global.fetch.mockResolvedValueOnce({
        ok: true,
        status: 200,
        statusText: "",
        body: {
          getReader: () => ({
            read: () => {
              state.readStarted = true;
              return new Promise(() => {});
            },
            cancel: async () => {},
            releaseLock: () => {
              state.released = true;
            },
          }),
        },
      });
      const pending = onExecuteSendPhoneMessage(makeEvent()).catch((e) => e);
      await jest.advanceTimersByTimeAsync(8000);
      const err = await pending;
      expect(state.readStarted).toBe(true);
      expect(state.released).toBe(true);
      expect(err.message).toBe("MyOTP response body read failed: timed out after 8000ms");
    });

    it("reads at most 8 KB of a streamed body, then cancels the stream", async () => {
      const state = { reads: 0, cancelled: false, released: false };
      const chunks = [
        new TextEncoder().encode("x".repeat(6000)),
        new TextEncoder().encode("y".repeat(6000)),
      ];
      const extra = new TextEncoder().encode("z".repeat(6000));
      global.fetch.mockResolvedValueOnce({
        ok: false,
        status: 500,
        statusText: "",
        body: {
          getReader: () => ({
            read: async () => {
              state.reads += 1;
              const value = chunks.shift() || extra;
              return { value, done: false };
            },
            cancel: async () => {
              state.cancelled = true;
            },
            releaseLock: () => {
              state.released = true;
            },
          }),
        },
      });
      await expect(onExecuteSendPhoneMessage(makeEvent())).rejects.toThrow(
        /^MyOTP responded 500: x+/
      );
      expect(state.reads).toBe(2);
      expect(state.cancelled).toBe(true);
      expect(state.released).toBe(true);
    });
  });
});
