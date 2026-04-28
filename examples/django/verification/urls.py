from django.urls import path

from . import views

urlpatterns = [
    path("", views.phone_form, name="phone_form"),
    path("send/", views.send_otp, name="send_otp"),
    path("verify/", views.verify_form, name="verify_form"),
    path("verify/submit/", views.verify_otp, name="verify_otp"),
    path("success/", views.success, name="success"),
]
