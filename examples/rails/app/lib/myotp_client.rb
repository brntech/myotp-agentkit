# Server-side client for the MyOTP.App REST API.
#
# The API key is read from Rails configuration (loaded from env at boot in
# config/application.rb) so it never leaks to the browser-bound asset pipeline.
#
# API reference: https://api.myotp.app
require "net/http"
require "uri"
require "json"

module MyotpClient
  Error = Class.new(StandardError) do
    attr_reader :status

    def initialize(status, message)
      super(message)
      @status = status
    end
  end

  module_function

  def generate_otp(phone_number:, channel: "sms")
    post("/generate_otp", phone_number: phone_number, channel: channel)
  end

  def verify_otp(otp:, message_id: nil, phone_number: nil)
    payload = { otp: otp }
    payload[:message_id] = message_id if message_id
    payload[:phone_number] = phone_number if phone_number
    post("/verify_otp", payload)
  end

  def post(path, payload)
    api_key = Rails.configuration.x.myotp.api_key.to_s
    raise Error.new(500, "MYOTP_API_KEY is not configured") if api_key.empty?

    base_url = Rails.configuration.x.myotp.base_url.to_s.chomp("/")
    uri = URI.parse("#{base_url}#{path}")

    http = Net::HTTP.new(uri.host, uri.port)
    http.use_ssl = (uri.scheme == "https")
    http.read_timeout = 15
    http.open_timeout = 10

    request = Net::HTTP::Post.new(uri.request_uri)
    request["Content-Type"] = "application/json"
    request["X-API-Key"] = api_key
    request.body = payload.to_json

    response = http.request(request)
    data = parse_json(response.body)

    if response.is_a?(Net::HTTPSuccess)
      data
    else
      message = data["message"] || data["error"] || "MyOTP error #{response.code}"
      raise Error.new(response.code.to_i, message)
    end
  end

  def parse_json(body)
    return {} if body.nil? || body.empty?
    JSON.parse(body)
  rescue JSON::ParserError
    {}
  end
end
