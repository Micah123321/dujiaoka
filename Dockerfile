FROM node:22-bookworm-slim AS frontend
WORKDIR /build/frontend
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run typecheck && npm test && npm run build && npm run build:admin

FROM webdevops/php-nginx@sha256:a77a6fa44506f01ad9c61e7621fccf5a3036bf9e6fd3786bbf5a98d6b988aba6
WORKDIR /app
ENV WEB_DOCUMENT_ROOT=/app/public
COPY . /app
COPY --from=frontend /build/public/assets/modern /app/public/assets/modern
COPY --from=frontend /build/public/assets/admin /app/public/assets/admin
COPY --chmod=755 deploy/entrypoint.sh /opt/docker/provision/entrypoint.d/95-dujiaoka.sh
RUN composer validate --strict --no-check-all --check-lock --no-check-publish --no-plugins && composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-plugins --optimize-autoloader && composer check-platform-reqs --no-dev --no-plugins
RUN mkdir -p public/vendor/dcat-admin && cp -a vendor/dcat-x/laravel-admin/resources/dist/. public/vendor/dcat-admin/
COPY deploy/supervisor/queue.conf /opt/docker/etc/supervisor.d/dujiaoka-queue.conf
COPY deploy/cron/dujiaoka /opt/docker/etc/cron/dujiaoka
RUN docker-service-enable cron && chmod 0644 /opt/docker/etc/cron/dujiaoka
RUN mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache public/uploads && chown -R application:application /app && chmod -R u+rwX,g+rX,o-rwx storage bootstrap/cache && chmod -R u=rwX,go=rX public/uploads
STOPSIGNAL SIGTERM
CMD ["supervisord"]
