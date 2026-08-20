import type { Metadata } from "next";
import { Sora, Plus_Jakarta_Sans, Fraunces } from "next/font/google";
import { Toaster } from "sonner";
import { AuthProvider } from "@/lib/auth/auth-context";
import "./globals.css";

const fontDisplay = Sora({
  variable: "--font-display",
  subsets: ["latin"],
  weight: ["600", "700"],
});

const fontBody = Plus_Jakarta_Sans({
  variable: "--font-body",
  subsets: ["latin"],
  weight: ["400", "500", "600", "700"],
});

// Same pairing as PMB's brand-mark: this serif carries the "Siakad YAPI"
// wordmark and nothing else, set against the sans used everywhere else.
const fontBrand = Fraunces({
  variable: "--font-brand",
  subsets: ["latin"],
  weight: ["500", "600"],
  style: ["normal", "italic"],
});

export const metadata: Metadata = {
  title: "Siakad YAPI",
  description: "Aplikasi sekolah YAPI: data siswa, prestasi, poin, dan tagihan.",
  icons: {
    icon: [
      { url: "/images/logo-yapi.png", type: "image/png" },
      { url: "/favicon.png", type: "image/png" },
    ],
    shortcut: "/images/logo-yapi.png",
    apple: "/images/logo-yapi.png",
  },
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="id" className={`${fontDisplay.variable} ${fontBody.variable} ${fontBrand.variable}`}>
      <body>
        <AuthProvider>{children}</AuthProvider>
        <Toaster position="top-center" richColors />
      </body>
    </html>
  );
}
