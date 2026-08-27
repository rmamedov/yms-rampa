#!/usr/bin/env bash
# Деплой YMS «Рампа» на дроплет.
#
# Фронтенди збираються ЛОКАЛЬНО і їдуть на сервер готовими: на дроплеті
# 1 vCPU і 2 ГБ RAM, продакшн-збірка Angular там або впала б з OOM, або
# тривала б десятки хвилин.
#
# Наявний сайт на сервері (Сільпо PWA) не зачіпається: YMS живе на власних
# піддоменах і у власних конфігах nginx.
set -euo pipefail

HOST="${YMS_HOST:-root@104.248.132.130}"
KEY="${YMS_SSH_KEY:-$HOME/.ssh/id_droplet}"
IP="${YMS_IP:-104.248.132.130}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

SSH=(ssh -i "$KEY" -o StrictHostKeyChecking=accept-new "$HOST")
RSYNC_SSH="ssh -i $KEY -o StrictHostKeyChecking=accept-new"

SERVICES=(store-service booking-service partner-service identity-staff-service
          identity-partner-service notification-service analytics-service)
APPS=(supplier-web driver-web store-web admin-web)

log() { echo -e "\n\033[1;36m==> $*\033[0m"; }

log "Збірка фронтендів локально"
cd "$REPO/web"
for app in "${APPS[@]}"; do
    echo "  → $app"
    npx nx build "$app" --configuration=production >/dev/null
done

log "Відвантаження сервісів"
for svc in "${SERVICES[@]}"; do
    echo "  → $svc"
    rsync -az --delete -e "$RSYNC_SSH" \
        --exclude 'vendor/' --exclude 'var/' --exclude 'tests/' --exclude '.env.local' \
        "$REPO/services/$svc/" "$HOST:/var/www/yms/services/$svc/"
done

log "Відвантаження фронтендів"
for app in "${APPS[@]}"; do
    src="$REPO/web/dist/apps/$app/browser"
    [ -d "$src" ] || src="$REPO/web/dist/apps/$app"
    echo "  → $app (з $src)"
    rsync -az --delete -e "$RSYNC_SSH" "$src/" "$HOST:/var/www/yms/web/$app/"
done

log "Встановлення залежностей на сервері"
for svc in "${SERVICES[@]}"; do
    echo "  → $svc"
    "${SSH[@]}" "cd /var/www/yms/services/$svc && \
        COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --optimize-autoloader --quiet && \
        mkdir -p var && chown -R www-data:www-data var"
done

log "Прогрів кешу Symfony"
for svc in "${SERVICES[@]}"; do
    "${SSH[@]}" "cd /var/www/yms/services/$svc && APP_ENV=prod php bin/console cache:clear --no-interaction -q || true"
done

log "Права"
"${SSH[@]}" 'chown -R www-data:www-data /var/www/yms && find /var/www/yms -type d -exec chmod 755 {} +'

log "nginx"
rsync -az -e "$RSYNC_SSH" "$REPO/infra/nginx-yms.conf" "$HOST:/etc/nginx/sites-available/yms"
"${SSH[@]}" 'ln -sf /etc/nginx/sites-available/yms /etc/nginx/sites-enabled/yms && nginx -t && systemctl reload nginx'

log "Перевірка"
for host in yms driver store admin; do
    code=$(curl -s -o /dev/null -w '%{http_code}' "http://${host}.${IP}.sslip.io/" || echo "000")
    printf '  %-8s HTTP %s\n' "$host" "$code"
done
api=$(curl -s -o /dev/null -w '%{http_code}' "http://yms.${IP}.sslip.io/api/supplier/v1/cities" || echo "000")
echo "  api      HTTP $api"

log "Готово"
