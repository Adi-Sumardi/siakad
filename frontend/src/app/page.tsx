"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import {
  Award,
  Megaphone,
  Receipt,
  ArrowRight,
  Mail,
  MessageCircle,
  ShieldCheck,
  CheckCircle2,
} from "lucide-react";
import { BrandMark } from "@/components/brand-mark";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";

const UNITS = [
  "RA Sakinah",
  "Playgroup Sakinah",
  "TK Islam Al Azhar 13",
  "SD Islam Al Azhar 13",
  "SMP Islam Al Azhar 12",
  "SMP Islam Al Azhar 55",
  "SMA Islam Al Azhar 33",
  "SMA Islam Al Azhar 48",
];

function RotatingUnit() {
  const [index, setIndex] = useState(0);

  useEffect(() => {
    const id = setInterval(() => setIndex((i) => (i + 1) % UNITS.length), 2400);
    return () => clearInterval(id);
  }, []);

  return (
    // Serif italic against the sans headline, same contrast PMB uses for its
    // own rotating line - the cue that this word is the one that changes.
    <span
      className="relative inline-grid justify-items-center text-primary italic"
      style={{ fontFamily: "var(--font-brand)" }}
    >
      {UNITS.map((unit, i) => (
        <span
          key={unit}
          aria-hidden={i !== index}
          className={cn(
            "[grid-area:1/1] transition-opacity duration-700",
            i === index ? "opacity-100" : "opacity-0",
          )}
        >
          {unit}
        </span>
      ))}
    </span>
  );
}

const FEATURES = [
  {
    icon: Receipt,
    title: "Tagihan & pembayaran",
    desc: "SPP bulanan lewat Virtual Account Bank Muamalat (khusus per anak), lengkap dengan riwayat pembayaran dan kuitansi.",
  },
  {
    icon: Award,
    title: "Poin & prestasi",
    desc: "Catatan poin kedisiplinan semester berjalan, plus prestasi yang sudah diverifikasi sekolah.",
  },
  {
    icon: Megaphone,
    title: "Informasi sekolah",
    desc: "Pengumuman dari sekolah, unit, sampai kelas anak Anda - tanpa harus mencari-cari di grup chat.",
  },
];

const LOGIN_STEPS = [
  {
    icons: [Mail, MessageCircle],
    title: "Masukkan email atau WhatsApp",
    desc: "Alamat email atau nomor WhatsApp yang sudah terdaftar di sekolah.",
  },
  {
    icons: [ShieldCheck],
    title: "Terima kode OTP",
    desc: "Kode 6 digit sekali pakai otomatis terkirim ke kanal yang Anda pilih.",
  },
  {
    icons: [CheckCircle2],
    title: "Masuk ke akun",
    desc: "Masukkan kodenya, dan Anda langsung masuk - tidak ada kata sandi untuk dibuat atau diingat.",
  },
];

const UNIT_FOOTER_LIST = UNITS.join(" · ");

export default function LandingPage() {
  return (
    <div className="min-h-svh relative overflow-x-hidden">
      {/* Decorative background - visibly blue, not a near-white tint */}
      <div className="pointer-events-none fixed inset-0 overflow-hidden -z-10">
        <div className="absolute inset-0 bg-linear-to-b from-[#DCE6FB] via-[#F0F5FE] to-white" />
        <div className="absolute -top-24 -left-32 size-104 rounded-full bg-[#2856E0]/45 blur-3xl" />
        <div className="absolute top-20 -right-24 size-96 rounded-full bg-[#13286B]/35 blur-3xl" />
        <div className="absolute top-112 left-1/4 size-88 rounded-full bg-[#2856E0]/30 blur-3xl" />
      </div>

      <header className="flex items-center justify-between px-5 sm:px-8 py-5 max-w-5xl mx-auto">
        <BrandMark />
        <Link
          href="/login"
          className="inline-flex items-center rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 transition-colors"
        >
          Masuk
        </Link>
      </header>

      <main className="max-w-5xl mx-auto px-5 sm:px-8">
        {/* Hero */}
        <section className="py-14 sm:py-20 flex flex-col items-center text-center gap-5">
          <h1
            className="text-3xl sm:text-4xl font-semibold max-w-2xl leading-tight flex flex-col items-center gap-1"
            style={{ fontFamily: "var(--font-heading)" }}
          >
            <span>Satu portal untuk keluarga besar</span>
            <RotatingUnit />
          </h1>
          <p className="text-muted-foreground max-w-md text-sm sm:text-base">
            Tagihan, poin, prestasi, dan informasi sekolah anak Anda - dalam satu akun. Masuk cukup
            dengan email atau nomor WhatsApp, tanpa kata sandi.
          </p>
          <Link href="/login">
            <Button size="lg" className="mt-1 w-fit">
              Masuk ke akun Anda
              <ArrowRight />
            </Button>
          </Link>
        </section>

        {/* Features */}
        <section className="pb-16 sm:pb-24 grid sm:grid-cols-3 gap-4">
          {FEATURES.map((feature) => (
            <div key={feature.title} className="rounded-2xl border bg-card/90 backdrop-blur-sm p-5 flex flex-col gap-3">
              <span className="flex size-8 items-center justify-center rounded-lg bg-accent text-accent-foreground shrink-0">
                <feature.icon className="size-4" />
              </span>
              <h3 className="text-sm font-semibold" style={{ fontFamily: "var(--font-heading)" }}>
                {feature.title}
              </h3>
              <p className="text-xs text-muted-foreground leading-relaxed">{feature.desc}</p>
            </div>
          ))}
        </section>
      </main>

      {/* Cara Masuk - passwordless is unfamiliar to many parents, so it gets its own explainer */}
      <section className="border-y bg-canvas/70">
        <div className="max-w-5xl mx-auto px-5 sm:px-8 py-14 sm:py-20">
          <div className="flex flex-col items-center text-center gap-2 mb-11">
            <Badge variant="primary">
              <ShieldCheck className="size-3.5" />
              Tanpa kata sandi
            </Badge>
            <h2 className="text-xl sm:text-2xl font-semibold" style={{ fontFamily: "var(--font-heading)" }}>
              Cara masuk
            </h2>
            <p className="max-w-md text-sm text-muted-foreground">
              Tidak ada kata sandi untuk diingat - wali murid, guru, maupun admin masuk dengan cara yang sama.
            </p>
          </div>

          <div className="grid sm:grid-cols-3 gap-8 sm:gap-6">
            {LOGIN_STEPS.map((step, i) => (
              <div key={step.title} className="flex sm:flex-col items-start sm:items-center gap-4 sm:text-center">
                <span
                  className={cn(
                    "flex size-9 shrink-0 items-center justify-center rounded-full border font-semibold text-sm",
                    i === LOGIN_STEPS.length - 1
                      ? "bg-primary text-primary-foreground border-primary"
                      : "bg-background text-primary border-border",
                  )}
                  style={{ fontFamily: "var(--font-heading)" }}
                >
                  {i + 1}
                </span>
                <div className="flex flex-col sm:items-center gap-1.5">
                  <div className="flex items-center gap-1.5 text-muted-foreground sm:justify-center">
                    {step.icons.map((Icon, iconIdx) => (
                      <Icon key={iconIdx} className="size-4" />
                    ))}
                  </div>
                  <h3 className="text-sm font-semibold" style={{ fontFamily: "var(--font-heading)" }}>
                    {step.title}
                  </h3>
                  <p className="text-xs text-muted-foreground leading-relaxed sm:max-w-56">{step.desc}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      <footer className="bg-card/60 py-8 flex flex-col items-center gap-3 text-center text-xs text-muted-foreground">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src="/images/logo-yapi.png" alt="Yayasan Asrama Pelajar Islam - Al Azhar" className="h-8 w-auto opacity-80" />
        <p>Powered By Yayasan Asrama Pelajar Islam - Al Azhar</p>
        <p className="max-w-lg px-5">{UNIT_FOOTER_LIST}</p>
      </footer>
    </div>
  );
}
