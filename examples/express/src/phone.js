// MyOTP requires phone numbers in international format with no leading "+"
// or "0" — digits only. Example: "14155551234". The API rejects anything else.
function sanitisePhone(input) {
  return String(input || "").replace(/\D+/g, "").replace(/^0+/, "");
}

module.exports = { sanitisePhone };
