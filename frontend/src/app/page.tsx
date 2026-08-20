"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Award, Megaphone, Receipt, ArrowRight } from "lucide-react";
import { BrandMark } from "@/components/brand-mark";
import { Button } from "@/components/ui/button";
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
    desc: "SPP bulanan, riwayat pembayaran, dan kuitansi - untuk semua anak dalam satu keluarga sekaligus.",
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

export default function LandingPage() {
  return (
    <div className="min-h-svh bg-white relative overflow-x-hidden">
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

      <footer className="border-t bg-card/60 py-8 flex flex-col items-center gap-3 text-center text-xs text-muted-foreground">
        <p>Powered By Yayasan Asrama Pelajar Islam - Al Azhar</p>
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src="/images/logo-yapi.png" alt="Yayasan Asrama Pelajar Islam - Al Azhar" className="h-8 w-auto opacity-80" />
      </footer>
    </div>
  );
}
