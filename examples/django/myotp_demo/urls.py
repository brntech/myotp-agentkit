from django.urls import include, path

urlpatterns = [
    path("", include("verification.urls")),
]
