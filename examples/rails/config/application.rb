require_relative "boot"

# Pull in only the Rails frameworks we actually need. ActiveRecord/ActiveJob
# /ActionMailer/ActionCable are skipped -- this demo has no DB and no jobs.
require "rails"
require "action_controller/railtie"
require "action_view/railtie"
require "sprockets/railtie"

Bundler.require(*Rails.groups)

module MyotpDemo
  class Application < Rails::Application
    config.load_defaults 7.1

    # Don't auto-load anything from /lib for this tiny demo.
    config.autoload_lib(ignore: %w[assets tasks]) if config.respond_to?(:autoload_lib)

    # MyOTP API config -- read from env at app boot, available to controllers
    # via Rails.configuration.x.myotp.api_key etc.
    config.x.myotp.api_key = ENV["MYOTP_API_KEY"].to_s
    config.x.myotp.base_url = ENV.fetch("MYOTP_BASE_URL", "https://api.myotp.app")
  end
end
