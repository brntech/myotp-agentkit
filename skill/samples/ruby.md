# Ruby — MyOTP.App

Ruby 3.x with the standard library `net/http` and `json`. No gems needed.
Set `MYOTP_API_KEY` in your environment.

```ruby
require "net/http"
require "json"
require "uri"

BASE = URI("https://api.myotp.app")

def myotp_post(path, body)
  uri = URI("#{BASE}#{path}")
  http = Net::HTTP.new(uri.host, uri.port)
  http.use_ssl = true
  http.read_timeout = 10

  req = Net::HTTP::Post.new(uri)
  req["Content-Type"] = "application/json"
  req["X-API-Key"]    = ENV.fetch("MYOTP_API_KEY")
  req.body = body.to_json

  res = http.request(req)
  { status: res.code.to_i, body: JSON.parse(res.body) }
end

# channel: "sms" (default), "whatsapp", or "telegram"
def generate_otp(phone_number, channel: "sms")
  myotp_post("/generate_otp",
             phone_number: phone_number, channel: channel)
end

# verify_otp returns 200 even on failure — inspect body["status"].
def verify_otp(phone_number, otp)
  myotp_post("/verify_otp",
             phone_number: phone_number, otp: otp)
end

# Demo
sent = generate_otp("14155551234")
puts "message_id: #{sent[:body]["message_id"]}"

# ... user types in the code ...
result = verify_otp("14155551234", "123456")
if result[:body]["status"] == "success"
  puts "verified"
else
  puts "failed: #{result[:body]["reason"]}"
end
```
