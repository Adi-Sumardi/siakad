<?php

namespace App\Providers;

use App\Services\Notification\MailGateway;
use App\Services\Notification\SendagoMailGateway;
use App\Services\Notification\SendagoWhatsAppGateway;
use App\Services\Notification\WhatsAppGateway;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\XenditGateway;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bound to interfaces so a test can swap in a fake gateway without the
        // handoff code knowing which provider is behind it.
        $this->app->bind(MailGateway::class, SendagoMailGateway::class);
        $this->app->bind(WhatsAppGateway::class, SendagoWhatsAppGateway::class);
        $this->app->bind(PaymentGateway::class, function () {
            $driver = env('PAYMENT_GATEWAY', 'sendagopay');

            return match ($driver) {
                'xendit' => app(\App\Services\Payment\XenditGateway::class),
                default => app(\App\Services\Payment\SendagoPayGateway::class),
            };
        });
    }

    public function boot(): void
    {
        // Attribute assignment that no $fillable covers should fail loudly in
        // development rather than silently drop the value.
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        // Dates in emails read as "14 Agustus 2026" - Carbon's own locale, which
        // is what translatedFormat() consults.
        Carbon::setLocale('id');
    }
}
