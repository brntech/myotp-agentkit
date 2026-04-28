require "active_support/core_ext/integer/time"

Rails.application.configure do
  config.cache_classes = false
  config.eager_load = false

  config.consider_all_requests_local = true
  config.server_timing = true

  config.action_controller.perform_caching = false

  config.public_file_server.enabled = true
  config.public_file_server.headers = { "Cache-Control" => "public, max-age=#{2.days.to_i}" }

  config.active_support.deprecation = :log
  config.active_support.disallowed_deprecation = :raise
  config.active_support.disallowed_deprecation_warnings = []

  config.assets.debug = true
  config.assets.quiet = true
end
