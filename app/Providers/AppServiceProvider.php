<?php

namespace App\Providers;

use App\Services\Notification\MailGateway;
use App\Services\Notification\SendagoMailGateway;
use App\Services\Notification\SendagoWhatsAppGateway;
use App\Services\Notification\WhatsAppGateway;
use App\Services\Payment\BillingApiGateway;
use App\Services\Payment\PaymentGateway;
use Carbon\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bound to interfaces so a test can swap in a fake gateway without the
        // handoff code knowing which provider is behind it.
        $this->app->bind(MailGateway::class, SendagoMailGateway::class);
        $this->app->bind(WhatsAppGateway::class, SendagoWhatsAppGateway::class);
        // e-SPP Virtual Account is the one and only payment gateway in
        // production; the old Xendit/SendagoPay alternates were never used
        // for real bills and are removed.
        $this->app->bind(PaymentGateway::class, BillingApiGateway::class);
    }

    public function boot(): void
    {
        // Attribute assignment that no $fillable covers should fail loudly in
        // development rather than silently drop the value.
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        // Dates in emails read as "14 Agustus 2026" - Carbon's own locale, which
        // is what translatedFormat() consults.
        Carbon::setLocale('id');

        // Paces every queued App\Jobs\SendWhatsAppMessage - see that class for
        // why Sendago's unofficial-gateway number needs this.
        RateLimiter::for('whatsapp-messages', fn () => Limit::perMinute(
            (int) config('services.sendago.send_rate_per_minute', 60)
        ));
    }
}
