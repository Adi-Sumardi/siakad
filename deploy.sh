#!/usr/bin/env bash
# ==============================================================================
# Script Deployment Otomatis - SIAKAD YAPI
# Domain   : siakad.yapinet.id
# Target   : 103.94.239.109 (Ubuntu 22.04)
# User     : odoo-yapi
# SSH Key  : /Users/yapi/Adi/SSH/y4p1.pem
# ==============================================================================
set -e

# Warna output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# Konfigurasi Server
SERVER_IP="103.94.239.109"
SERVER_PORT="22"
SERVER_USER="odoo-yapi"
DOMAIN="siakad.yapinet.id"
REMOTE_DIR="/home/odoo-yapi/siakad"

# Lokasi SSH Key (Default di ~/Adi/SSH/y4p1.pem atau /Users/yapi/Adi/SSH/y4p1.pem)
SSH_KEY_DEFAULT="${HOME}/Adi/SSH/y4p1.pem"
if [ ! -f "$SSH_KEY_DEFAULT" ] && [ -f "/Users/yapi/Adi/SSH/y4p1.pem" ]; then
    SSH_KEY_DEFAULT="/Users/yapi/Adi/SSH/y4p1.pem"
fi
SSH_KEY="${SSH_KEY:-$SSH_KEY_DEFAULT}"

LOCAL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Helper SSH Command
ssh_cmd() {
    ssh -i "$SSH_KEY" -p "$SERVER_PORT" -o StrictHostKeyChecking=accept-new "${SERVER_USER}@${SERVER_IP}" "$@"
}

log_info() {
    echo -e "${CYAN}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

show_help() {
    echo "================================================================="
    echo -e "${BOLD}SIAKAD Deployment Script${NC}"
    echo "================================================================="
    echo "Penggunaan:"
    echo "  ./deploy.sh             : Melakukan full deploy (setup, sync, build, up)"
    echo "  ./deploy.sh update      : Menjalankan update cepat (sync & refresh container)"
    echo "  ./deploy.sh --setup-env : Hanya setup Docker & Host Nginx di server"
    echo "  ./deploy.sh --ssl       : Setup / Perbarui sertifikat SSL Let's Encrypt"
    echo "  ./deploy.sh --status    : Cek status container & health di server"
    echo "  ./deploy.sh --logs      : Menampilkan log realtime container di server"
    echo "  ./deploy.sh --help      : Menampilkan panduan ini"
    echo "================================================================="
    exit 0
}

# Cek argumen
if [ "$1" == "--help" ] || [ "$1" == "-h" ]; then
    show_help
elif [ "$1" == "update" ]; then
    exec "$LOCAL_DIR/update.sh"
elif [ "$1" == "--ssl" ]; then
    log_info "Menjalankan script SSL di server..."
    ssh_cmd "bash -s" < "$LOCAL_DIR/scripts/setup-ssl.sh"
    exit 0
elif [ "$1" == "--status" ]; then
    log_info "Memeriksa status layanan di server..."
    ssh_cmd "cd ${REMOTE_DIR} && docker compose -f docker-compose.prod.yml ps && echo '--- ODOO STATUS ---' && sudo systemctl status odoo18 --no-pager -n 2"
    exit 0
elif [ "$1" == "--logs" ]; then
    log_info "Menampilkan logs (Ctrl+C untuk keluar)..."
    ssh_cmd "cd ${REMOTE_DIR} && docker compose -f docker-compose.prod.yml logs -f --tail=100"
    exit 0
fi

# ==============================================================================
# 1. Validasi Pra-Syarat Lokal
# ==============================================================================
echo "================================================================="
echo -e "${BOLD}${BLUE}  MEMULAI DEPLOYMENT SIAKAD YAPI -> ${DOMAIN}${NC}"
echo "================================================================="

if [ ! -f "$SSH_KEY" ]; then
    log_error "SSH Key tidak ditemukan di: $SSH_KEY"
    log_error "Silakan pastikan file y4p1.pem berada di folder ~/Adi/SSH/y4p1.pem atau set env SSH_KEY=/path/to/key"
    exit 1
fi

chmod 600 "$SSH_KEY" 2>/dev/null || true
log_info "Menggunakan SSH Key: $SSH_KEY"

# Test koneksi SSH
log_info "Menguji koneksi SSH ke ${SERVER_USER}@${SERVER_IP}..."
if ! ssh_cmd "echo 'SSH Connection OK'" > /dev/null 2>&1; then
    log_error "Gagal terhubung ke server melalui SSH. Periksa IP, User, Port, dan Key."
    exit 1
fi
log_success "Koneksi SSH berhasil."

# Verifikasi status Odoo sebelum menyentuh apa pun
log_info "Memeriksa status aplikasi Odoo eksisting..."
ODOO_STATUS=$(ssh_cmd "sudo systemctl is-active odoo18 || echo 'inactive'")
if [ "$ODOO_STATUS" == "active" ]; then
    log_success "Aplikasi Odoo 18 terdeteksi aktif & aman."
else
    log_warn "Status Odoo 18 di server: $ODOO_STATUS (Layanan tetap tidak akan diganggu)."
fi

# ==============================================================================
# 2. Setup Server: Docker, Docker Compose, & Nginx Host
# ==============================================================================
log_info "Memeriksa & mempersiapkan dependensi server (Docker & Nginx Host)..."

ssh_cmd "bash -s" << 'EOF'
set -e

# 1. Cek & Install Docker jika belum ada
if ! command -v docker &> /dev/null; then
    echo "[SERVER] Menginstall Docker Engine & Docker Compose Plugin..."
    sudo apt-get update -y
    sudo apt-get install -y ca-certificates curl gnupg lsb-release
    
    sudo mkdir -p /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg --yes
    
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
    
    sudo apt-get update -y
    sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    
    sudo systemctl enable docker
    sudo systemctl start docker
    
    # Tambahkan user odoo-yapi ke grup docker
    sudo usermod -aG docker odoo-yapi
    echo "[SERVER] Docker berhasil diinstall."
else
    echo "[SERVER] Docker sudah terpasang."
    # Pastikan user ada di docker group
    sudo usermod -aG docker odoo-yapi 2>/dev/null || true
fi

# 2. Cek rsync
if ! command -v rsync &> /dev/null; then
    sudo apt-get update -y && sudo apt-get install -y rsync
fi
EOF

if [ "$1" == "--setup-env" ]; then
    log_success "Setup dependensi server selesai."
    exit 0
fi

# ==============================================================================
# 3. Konfigurasi Reverse Proxy Nginx Host untuk siakad.yapinet.id
# ==============================================================================
log_info "Mengonfigurasi Nginx Host untuk ${DOMAIN} -> 127.0.0.1:8091..."

# Salin file konfigurasi Nginx host
scp -i "$SSH_KEY" -P "$SERVER_PORT" "$LOCAL_DIR/scripts/nginx-siakad.conf" "${SERVER_USER}@${SERVER_IP}:/tmp/nginx-siakad.conf"

ssh_cmd "bash -s" << 'EOF'
set -e
sudo cp /tmp/nginx-siakad.conf /etc/nginx/sites-available/siakad
rm -f /tmp/nginx-siakad.conf

# Buat symlink ke sites-enabled jika belum ada
if [ ! -L /etc/nginx/sites-enabled/siakad ]; then
    sudo ln -sf /etc/nginx/sites-available/siakad /etc/nginx/sites-enabled/siakad
fi

# Uji konfigurasi Nginx tanpa mengganggu Odoo
echo "[SERVER] Menguji sintaks Nginx..."
sudo nginx -t
sudo systemctl reload nginx
echo "[SERVER] Nginx Host reload sukses."
EOF
log_success "Nginx Host berhasil dikonfigurasi untuk ${DOMAIN}."

# ==============================================================================
# 4. Sinkronisasi Kode Proyek ke Server
# ==============================================================================
log_info "Menyiapkan direktori proyek di server (${REMOTE_DIR})..."
ssh_cmd "mkdir -p ${REMOTE_DIR}"

log_info "Melakukan sinkronisasi file proyek via rsync..."
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

log_success "Sinkronisasi kode selesai."

# ==============================================================================
# 5. Konfigurasi .env & Kredensial Produksi di Server
# ==============================================================================
log_info "Memeriksa file .env produksi di server..."

ssh_cmd "bash -s" << 'EOF'
set -e
cd /home/odoo-yapi/siakad

if [ ! -f .env ]; then
    echo "[SERVER] Membuat file .env produksi baru dari template..."
    cp scripts/env.prod.example .env
    
    # Generate random APP_KEY
    RANDOM_KEY="base64:$(openssl rand -base64 32)"
    sed -i "s|^APP_KEY=.*|APP_KEY=${RANDOM_KEY}|" .env

    # Generate random DB Password
    RANDOM_DB_PASS=$(openssl rand -base64 18 | tr -dc 'a-zA-Z0-9' | head -c 16)
    sed -i "s/DB_PASSWORD=/DB_PASSWORD=${RANDOM_DB_PASS}/" .env
    
    # Generate random PMB handoff secret
    RANDOM_HANDOFF=$(openssl rand -hex 32)
    sed -i "s/PMB_HANDOFF_SECRET=/PMB_HANDOFF_SECRET=${RANDOM_HANDOFF}/" .env
    
    echo "[SERVER] .env berhasil dibuat dengan password database dan APP_KEY aman."
else
    echo "[SERVER] File .env sudah ada, mempertahankan konfigurasi yang ada."
    
    # Pastikan APP_KEY terisi jika kosong
    APP_KEY_VAL=$(grep '^APP_KEY=' .env | cut -d '=' -f2)
    if [ -z "$APP_KEY_VAL" ]; then
        RANDOM_KEY="base64:$(openssl rand -base64 32)"
        sed -i "s|^APP_KEY=.*|APP_KEY=${RANDOM_KEY}|" .env
        echo "[SERVER] APP_KEY berhasil digenerate."
    fi
fi

# Pastikan permission folder storage & bootstrap/cache sesuai
mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache
rm -f bootstrap/cache/*.php
chmod -R 775 storage bootstrap/cache
chmod +x update.sh scripts/*.sh 2>/dev/null || true
EOF

# ==============================================================================
# 6. Build & Jalankan Docker Container
# ==============================================================================
log_info "Membangun dan menjalankan container Docker Compose di server..."

ssh_cmd "bash -s" << 'EOF'
set -e
cd /home/odoo-yapi/siakad

echo "[SERVER] Memulai Docker Compose build..."
docker compose -f docker-compose.prod.yml build

echo "[SERVER] Menjalankan layanan dengan Docker Compose..."
docker compose -f docker-compose.prod.yml up -d

echo "[SERVER] Menunggu container postgres siap..."
sleep 5

# ==============================================================================
# 7. Sinkronisasi Aset Publik Laravel ke Named Volume
# ==============================================================================
echo "[SERVER] Menyalin aset statis Laravel ke named volume siakad-laravel-public..."
LARAVEL_IMAGE=$(docker compose -f docker-compose.prod.yml images -q laravel)
PUBLIC_VOL=$(docker volume ls -q | grep "siakad-laravel-public" | head -n 1 || echo "siakad_siakad-laravel-public")

if [ -n "$LARAVEL_IMAGE" ] && [ -n "$PUBLIC_VOL" ]; then
    docker run --rm -v "${PUBLIC_VOL}:/target" "$LARAVEL_IMAGE" cp -rf /var/www/public/. /target/ 2>/dev/null || true
fi

# ==============================================================================
# 8. Migrasi Database & Caching
# ==============================================================================
echo "[SERVER] Menjalankan migrasi database..."
docker compose -f docker-compose.prod.yml exec -T laravel php artisan migrate --force

echo "[SERVER] Mengoptimalkan cache Laravel..."
docker compose -f docker-compose.prod.yml exec -T laravel php artisan config:cache
docker compose -f docker-compose.prod.yml exec -T laravel php artisan route:cache
docker compose -f docker-compose.prod.yml exec -T laravel php artisan view:cache || true
docker compose -f docker-compose.prod.yml exec -T laravel php artisan queue:restart || true

echo "[SERVER] Me-refresh Nginx upstream..."
docker compose -f docker-compose.prod.yml restart nginx
EOF

# ==============================================================================
# 9. Verifikasi Akhir
# ==============================================================================
log_info "Memeriksa status akhir container..."
ssh_cmd "cd ${REMOTE_DIR} && docker compose -f docker-compose.prod.yml ps"

# Verifikasi ulang status Odoo untuk memastikan Odoo tetap berjalan normal
log_info "Memverifikasi integritas Odoo 18..."
ODOO_FINAL_STATUS=$(ssh_cmd "sudo systemctl is-active odoo18 || echo 'inactive'")
if [ "$ODOO_FINAL_STATUS" == "active" ]; then
    log_success "Verifikasi Odoo: Layanan Odoo 18 tetap berjalan NORMAL & AMAN."
else
    log_warn "Peringatan status Odoo: $ODOO_FINAL_STATUS"
fi

echo "================================================================="
echo -e "${BOLD}${GREEN}  DEPLOYMENT SIAKAD SELESAI!${NC}"
echo "================================================================="
echo -e "Aplikasi siap diakses pada:"
echo -e "  - HTTP : http://${DOMAIN} (atau http://${SERVER_IP} via Host Header)"
echo ""
echo -e "${YELLOW}Catatan Selanjutnya:${NC}"
echo -e "1. Tambahkan A record di DNS domain yapinet.id:"
echo -e "     Host : ${BOLD}siakad${NC}"
echo -e "     Type : ${BOLD}A${NC}"
echo -e "     Value: ${BOLD}${SERVER_IP}${NC}"
echo -e "2. Setelah DNS aktif, pasang SSL Let's Encrypt dengan menjalankan:"
echo -e "     ${BOLD}./deploy.sh --ssl${NC}"
echo "================================================================="
