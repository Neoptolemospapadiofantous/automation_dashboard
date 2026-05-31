# Laravel Forge — site Deploy Script
#
# Paste this into your Forge site's "Deploy Script" box (Site -> Apps).
# Forge runs it on the server every time the deploy hook is triggered
# (e.g. by bin/deploy.sh) or on push if auto-deploy is enabled.
#
# $FORGE_SITE_PATH and $FORGE_PHP are provided by Forge.

cd $FORGE_SITE_PATH

# Pull the deployed branch. Set this to your working branch in Forge
# (Site -> Apps -> Git Repository -> Branch), e.g. claude/hopeful-goodall-UKgrU
# or main once you merge.
git pull origin $FORGE_SITE_BRANCH

# PHP dependencies (production, optimized autoloader).
$FORGE_COMPOSER install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Frontend assets.
npm ci
npm run build

# Laravel: run migrations and refresh caches.
$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan view:cache

# Restart the queue worker so broadcasts keep flowing after new code ships.
# (Configure the worker under Forge -> Server -> Daemons or Site -> Queue.)
$FORGE_PHP artisan queue:restart

# Zero-downtime reload of PHP-FPM (Forge replaces this token automatically).
( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock
