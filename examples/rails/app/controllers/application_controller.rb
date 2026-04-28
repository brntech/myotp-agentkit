class ApplicationController < ActionController::Base
  # Rails enables CSRF protection by default. Forms rendered with the
  # `form_with` helper inject an authenticity token automatically; the
  # framework verifies it on every non-GET request.
  protect_from_forgery with: :exception
end
