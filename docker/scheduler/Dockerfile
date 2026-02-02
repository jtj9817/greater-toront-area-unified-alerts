FROM sail-8.5/app

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update -qq \
    && apt-get install -y -qq cron \
    && rm -rf /var/lib/apt/lists/*

# Application code
COPY . .

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

RUN mkdir -p storage/logs bootstrap/cache \
    && chown -R sail:sail storage bootstrap/cache vendor

# Scheduler cron + tooling
COPY docker/scheduler/laravel-scheduler /etc/cron.d/laravel-scheduler
COPY docker/scheduler/entrypoint.sh /usr/local/bin/scheduler-entrypoint
COPY docker/scheduler/healthcheck.sh /usr/local/bin/scheduler-healthcheck

RUN chmod 0644 /etc/cron.d/laravel-scheduler \
    && chmod +x /usr/local/bin/scheduler-entrypoint /usr/local/bin/scheduler-healthcheck \
    && touch /var/log/cron.log

ENTRYPOINT ["/usr/local/bin/scheduler-entrypoint"]
CMD ["cron", "-f"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=120s --retries=3 CMD ["/usr/local/bin/scheduler-healthcheck"]
