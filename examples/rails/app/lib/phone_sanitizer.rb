# MyOTP requires phone numbers in international format with no leading "+"
# or "0" -- digits only. Example: "14155551234". The API rejects anything else
# with a 400.
module PhoneSanitizer
  module_function

  def call(value)
    value.to_s.gsub(/\D+/, "").sub(/\A0+/, "")
  end
end
