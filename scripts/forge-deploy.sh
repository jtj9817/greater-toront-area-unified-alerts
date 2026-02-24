#!/bin/bash

# Forge deployment script tracked in version control
# In Laravel Forge UI (Site -> Deployment -> Deploy Script), you can simply use:
# bash scripts/forge-deploy.sh

cd /home/forge/$FORGE_SITE_NAME || exit 1

echo "Pulling latest changes..."
git pull origin $FORGE_SITE_BRANCH

echo "Installing PHP dependencies..."
$FORGE_COMPOSER install --no-interaction --prefer-dist --optimize-autoloader --no-dev

echo "Installing Node.js dependencies..."
# The application uses React and Inertia.js, so compiling assets is mandatory
pnpm install --frozen-lockfile

echo "Building frontend assets..."
pnpm run build

echo "Reloading PHP-FPM..."
( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock

if [ -f artisan ]; then
    echo "Running database migrations..."
    $FORGE_PHP artisan migrate --force

    echo "Caching configurations..."
    $FORGE_PHP artisan optimize:clear
    $FORGE_PHP artisan config:cache
    $FORGE_PHP artisan route:cache
    $FORGE_PHP artisan view:cache
    $FORGE_PHP artisan event:cache

    echo "Linking storage..."
    $FORGE_PHP artisan storage:link

    echo "Restarting queue workers..."
    $FORGE_PHP artisan queue:restart
fi

echo "Deployment complete!"
