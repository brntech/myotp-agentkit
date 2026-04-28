Rails.application.routes.draw do
  root "verification#index"

  get  "/verify",         to: "verification#verify_form", as: :verify_form
  post "/send",           to: "verification#send_otp",    as: :send_otp
  post "/verify",         to: "verification#verify",      as: :verify_otp
  get  "/success",        to: "verification#success",     as: :success
end
