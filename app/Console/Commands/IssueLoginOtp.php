<?php

namespace App\Console\Commands;

use App\Models\LoginOtp;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Security\FieldEncrypter;
use Illuminate\Console\Command;

/**
 * Prints a sign-in code to the console instead of sending it.
 *
 * The recovery path when both gateways are down, and the only one - there is no
 * password to fall back on. It deliberately requires shell access to the
 * server, which is a much higher bar than a password reset link, and every use
 * is a line in the operator's own terminal history.
 */
class IssueLoginOtp extends Command
{
    protected $signature = 'otp:issue {identifier : Email atau nomor HP akun}';

    protected $description = 'Terbitkan kode masuk dan tampilkan di terminal (untuk pemulihan darurat)';

    public function handle(OtpService $otp, FieldEncrypter $encrypter): int
    {
        $identifier = $otp->normalise($this->argument('identifier'));
        $user = $otp->findUser($identifier);

        if (! $user) {
            $this->error("Tidak ada akun dengan identitas '{$identifier}'.");

            return self::FAILURE;
        }

        if (! $user->is_active) {
            $this->error('Akun ini dinonaktifkan. Aktifkan lebih dulu sebelum menerbitkan kode.');

            return self::FAILURE;
        }

        // Written straight to the table rather than through OtpService::issue(),
        // which would also try to send it - the whole point here is that
        // sending is what is broken.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        LoginOtp::where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        LoginOtp::create([
            'user_id' => $user->id,
            'identifier' => $identifier,
            'channel' => $otp->channelFor($identifier),
            'code_hash' => LoginOtp::hashCode($code),
            'expires_at' => now()->addMinutes(LoginOtp::TTL_MINUTES),
        ]);

        $this->newLine();
        $this->info("Akun  : {$user->name} ({$user->role})");
        $this->info("Masuk : {$identifier}");
        $this->info("Kode  : {$code}");
        $this->comment('Berlaku '.LoginOtp::TTL_MINUTES.' menit, sekali pakai.');
        $this->newLine();

        return self::SUCCESS;
    }
}
