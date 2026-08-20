#!/usr/bin/env bash
# ==============================================================================
# Script Update Cepat - SIAKAD YAPI
# Dapat dijalankan dari lokal (macOS) maupun langsung di dalam server VPS.
# ==============================================================================
set -e

# Warna output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# Deteksi apakah script berjalan di Server atau Lokal
if [ -f "/home/odoo-yapi/siakad/docker-compose.prod.yml" ] && [ "$1" == "--remote" ] || [ "$HOSTNAME" == "akunting" ]; then
    RUN_ON_SERVER=true
else
    RUN_ON_SERVER=false
fi

# ==============================================================================
# ALUR 1: Jika dijalankan di SERVER
# ==============================================================================
if [ "$RUN_ON_SERVER" = true ] || [ "$1" == "--server-only" ]; then
    echo "================================================================="
    echo -e "${BOLD}${CYAN}  MEMULAI PROSES UPDATE SIAKAD DI SERVER${NC}"
    echo "================================================================="

    cd /home/odoo-yapi/siakad || exit 1

    echo -e "${CYAN}[1/6] Membangun ulang image Docker yang diperbarui...${NC}"
    docker compose -f docker-compose.prod.yml build laravel nextjs queue scheduler

    echo -e "${CYAN}[2/6] Memperbarui dan merestart container...${NC}"
    docker compose -f docker-compose.prod.yml up -d --remove-orphans

    echo -e "${CYAN}[3/6] Menyinkronkan aset statis ke volume siakad-laravel-public...${NC}"
    LARAVEL_IMAGE=$(docker compose -f docker-compose.prod.yml images -q laravel)
    PUBLIC_VOL=$(docker volume ls -q | grep "siakad-laravel-public" | head -n 1 || echo "siakad_siakad-laravel-public")
    if [ -n "$LARAVEL_IMAGE" ] && [ -n "$PUBLIC_VOL" ]; then
        docker run --rm -v "${PUBLIC_VOL}:/target" "$LARAVEL_IMAGE" cp -rf /var/www/public/. /target/ 2>/dev/null || true
    fi

    echo -e "${CYAN}[4/6] Menjalankan migrasi database...${NC}"
    docker compose -f docker-compose.prod.yml exec -T laravel php artisan migrate --force

    echo -e "${CYAN}[5/6] Me-refresh cache Laravel & restart queue worker...${NC}"
    docker compose -f docker-compose.prod.yml exec -T laravel php artisan config:cache
    docker compose -f docker-compose.prod.yml exec -T laravel php artisan route:cache
    docker compose -f docker-compose.prod.yml exec -T laravel php artisan view:cache || true
    docker compose -f docker-compose.prod.yml exec -T laravel php artisan queue:restart || true
    docker compose -f docker-compose.prod.yml restart nginx

    echo -e "${CYAN}[6/6] Memeriksa status container & Odoo...${NC}"
    docker compose -f docker-compose.prod.yml ps
    
    ODOO_STATUS=$(sudo systemctl is-active odoo18 || echo 'inactive')
    echo -e "Status Odoo 18: ${GREEN}${ODOO_STATUS}${NC}"

    echo "================================================================="
    echo -e "${BOLD}${GREEN}  UPDATE SELESAI & APLIKASI TELAH AKTIF!${NC}"
    echo "================================================================="
    exit 0
fi

# ==============================================================================
# ALUR 2: Jika dijalankan di KOMPUTER LOKAL (macOS)
# ==============================================================================
SERVER_IP="103.94.239.109"
SERVER_PORT="22"
SERVER_USER="odoo-yapi"
REMOTE_DIR="/home/odoo-yapi/siakad"

SSH_KEY_DEFAULT="${HOME}/Adi/SSH/y4p1.pem"
if [ ! -f "$SSH_KEY_DEFAULT" ] && [ -f "/Users/yapi/Adi/SSH/y4p1.pem" ]; then
    SSH_KEY_DEFAULT="/Users/yapi/Adi/SSH/y4p1.pem"
fi
SSH_KEY="${SSH_KEY:-$SSH_KEY_DEFAULT}"
LOCAL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "================================================================="
echo -e "${BOLD}${CYAN}  SIAKAD UPDATE (Lokal -> ${SERVER_IP})${NC}"
echo "================================================================="

if [ ! -f "$SSH_KEY" ]; then
    echo -e "${RED}[ERROR] SSH Key tidak ditemukan di: $SSH_KEY${NC}"
    exit 1
fi
chmod 600 "$SSH_KEY" 2>/dev/null || true

echo -e "${CYAN}[1/3] Menyinkronkan perubahan file ke server...${NC}"
rsync -avz --delete \
    -e "ssh -i ${SSH_KEY} -p ${SERVER_PORT} -o StrictHostKeyChecking=accept-new" \
    --exclude=".git/" \
    --exclude="node_modules/" \
    --exclude="frontend/node_modules/" \
    --exclude="frontend/.next/" \
    --exclude="vendor/" \
    --exclude=".env" \
    --exclude=".env.local" \
    --exclude="frontend/.env.local" \
    --exclude="storage/framework/cache/*" \
    --exclude="storage/framework/sessions/*" \
    --exclude="storage/framework/views/*" \
    --exclude="storage/logs/*" \
    --exclude="bootstrap/cache/*.php" \
    --exclude=".phpunit.result.cache" \
    --exclude=".DS_Store" \
    "${LOCAL_DIR}/" "${SERVER_USER}@${SERVER_IP}:${REMOTE_DIR}/"

echo -e "${CYAN}[2/3] Memastikan script executable di server...${NC}"
ssh -i "$SSH_KEY" -p "$SERVER_PORT" "${SERVER_USER}@${SERVER_IP}" "chmod +x ${REMOTE_DIR}/update.sh ${REMOTE_DIR}/deploy.sh ${REMOTE_DIR}/scripts/*.sh 2>/dev/null || true"

echo -e "${CYAN}[3/3] Menjalankan update build & migration di server...${NC}"
ssh -i "$SSH_KEY" -p "$SERVER_PORT" -t "${SERVER_USER}@${SERVER_IP}" "bash ${REMOTE_DIR}/update.sh --server-only"

echo "================================================================="
echo -e "${BOLD}${GREEN}  UPDATE BERHASIL DISELESAIKAN!${NC}"
echo "================================================================="
