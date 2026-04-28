class VerificationController < ApplicationController
  VALID_CHANNELS = %w[sms whatsapp telegram].freeze

  # GET /
  def index
  end

  # POST /send
  def send_otp
    phone = PhoneSanitizer.call(params[:phone])
    channel = VALID_CHANNELS.include?(params[:channel]) ? params[:channel] : "sms"

    if phone.blank?
      @error = "Phone number is required."
      render :index, status: :bad_request
      return
    end

    result = MyotpClient.generate_otp(phone_number: phone, channel: channel)

    # message_id is opaque to the browser (a UUID). Rails signs and encrypts
    # the session cookie with secret_key_base, so it cannot be tampered with.
    session[:message_id] = result.fetch("message_id")
    session[:phone] = phone
    session[:channel] = channel
    redirect_to verify_form_path
  rescue MyotpClient::Error => e
    @error = e.message
    render :index, status: (e.status >= 400 ? e.status : :internal_server_error)
  end

  # GET /verify
  def verify_form
    return redirect_to root_path if session[:message_id].blank?

    @phone = session[:phone]
    @channel = session[:channel]
  end

  # POST /verify
  def verify
    return redirect_to root_path if session[:message_id].blank?

    @phone = session[:phone]
    @channel = session[:channel]
    otp = params[:otp].to_s.strip

    if otp.empty?
      @error = "Enter the code you received."
      render :verify_form, status: :bad_request
      return
    end

    result = MyotpClient.verify_otp(otp: otp, message_id: session[:message_id])

    if result["status"] != "success"
      @error = result["message"] || "Verification failed."
      render :verify_form, status: :bad_request
      return
    end

    verified_phone = session.delete(:phone)
    session.delete(:message_id)
    session.delete(:channel)
    session[:verified_phone] = verified_phone
    redirect_to success_path
  rescue MyotpClient::Error => e
    @error = e.message
    render :verify_form, status: (e.status >= 400 ? e.status : :internal_server_error)
  end

  # GET /success
  def success
    @phone = session.delete(:verified_phone)
    redirect_to(root_path) and return if @phone.blank?
  end
end
