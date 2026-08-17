import type { Metadata } from "next";
import { Toaster } from "sonner";
import { AuthProvider } from "@/lib/auth/auth-context";
import "./globals.css";

export const metadata: Metadata = {
  title: "Siakad YAPI",
  description: "Aplikasi sekolah YAPI: data siswa, prestasi, poin, dan tagihan.",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="id">
      <body>
        <AuthProvider>{children}</AuthProvider>
        <Toaster position="top-center" richColors />
      </body>
    </html>
  );
}
