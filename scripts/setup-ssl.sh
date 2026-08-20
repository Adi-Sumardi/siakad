#!/bin/bash
# ==============================================================================
# Script Setup SSL (Let's Encrypt / Certbot) untuk siakad.yapinet.id
# ==============================================================================
set -e

DOMAIN="siakad.yapinet.id"
EMAIL="admin@yapinet.id"

echo "================================================================="
echo "  Pemeriksaan & Konfigurasi SSL untuk ${DOMAIN}"
echo "================================================================="

# Pastikan certbot & python3-certbot-nginx terpasang
if ! command -v certbot &> /dev/null; then
    echo "Certbot belum terpasang. Menginstall certbot..."
    sudo apt-get update
    sudo apt-get install -y certbot python3-certbot-nginx
fi

# Cek apakah konfigurasi Nginx sudah aktif
if [ ! -f "/etc/nginx/sites-enabled/siakad" ]; then
    echo "Konfigurasi Nginx untuk siakad belum aktif di /etc/nginx/sites-enabled/siakad."
    echo "Mengaktifkan konfigurasi..."
    if [ -f "/etc/nginx/sites-available/siakad" ]; then
        sudo ln -sf /etc/nginx/sites-available/siakad /etc/nginx/sites-enabled/siakad
        sudo nginx -t && sudo systemctl reload nginx
    else
        echo "Error: /etc/nginx/sites-available/siakad tidak ditemukan."
        exit 1
    fi
fi

echo "Memeriksa resolusi DNS untuk ${DOMAIN}..."
RESOLVED_IP=$(getent ahostsv4 "${DOMAIN}" | head -n 1 | awk '{print $1}' || true)
SERVER_IP=$(curl -s -4 ifconfig.me || curl -s -4 api.ipify.org || true)

echo "IP Server: ${SERVER_IP}"
echo "DNS IP   : ${RESOLVED_IP}"

if [ "$RESOLVED_IP" != "$SERVER_IP" ]; then
    echo "-----------------------------------------------------------------"
    echo "PERINGATAN: DNS record untuk ${DOMAIN} (${RESOLVED_IP:-tidak ditemukan})"
    echo "belum mengarah ke IP VPS ini (${SERVER_IP})."
    echo "Pastikan Anda sudah menambahkan A record di DNS provider:"
    echo "  Host: siakad"
    echo "  Type: A"
    echo "  Target: ${SERVER_IP}"
    echo "-----------------------------------------------------------------"
    read -p "Apakah Anda tetap ingin mencoba menjalankan certbot sekarang? (y/N): " -r CONFIRM
    if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
        echo "Setup SSL dibatalkan. Jalankan kembali script ini setelah DNS selesai dipropagasi."
        exit 0
    fi
fi

echo "Menjalankan Certbot untuk ${DOMAIN}..."
sudo certbot --nginx -d "${DOMAIN}" --agree-tos --redirect --register-unsafely-without-email || \
sudo certbot --nginx -d "${DOMAIN}" --agree-tos --redirect -m "${EMAIL}" --non-interactive

echo "Menguji konfigurasi Nginx..."
sudo nginx -t
sudo systemctl reload nginx

echo "================================================================="
echo "SSL untuk https://${DOMAIN} berhasil dikonfigurasi!"
echo "================================================================="
