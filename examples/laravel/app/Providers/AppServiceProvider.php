<?php

namespace App\Providers;

use App\Services\MyOtpClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind the MyOTP client as a singleton so the underlying HTTP client
        // is reused across requests in the same worker process.
        $this->app->singleton(MyOtpClient::class, function ($app) {
            return new MyOtpClient(
                apiKey: (string) config('services.myotp.key'),
                baseUrl: (string) config('services.myotp.base_url'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
