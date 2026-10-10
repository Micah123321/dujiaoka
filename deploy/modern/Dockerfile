FROM webdevops/php-nginx@sha256:a77a6fa44506f01ad9c61e7621fccf5a3036bf9e6fd3786bbf5a98d6b988aba6
WORKDIR /app
ENV WEB_DOCUMENT_ROOT=/app/public
COPY . /app
RUN composer validate --strict --no-check-all --check-lock --no-check-publish --no-plugins && composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-plugins --optimize-autoloader && composer check-platform-reqs --no-dev --no-plugins
RUN mkdir -p public/vendor/dcat-admin && cp -a vendor/dcat-x/laravel-admin/resources/dist/. public/vendor/dcat-admin/
COPY deploy/supervisor/queue.conf /opt/docker/etc/supervisor.d/dujiaoka-queue.conf
COPY deploy/cron/dujiaoka /opt/docker/etc/cron/dujiaoka
RUN docker-service-enable cron && chmod 0644 /opt/docker/etc/cron/dujiaoka
RUN mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache public/uploads && chown -R application:application /app && chmod -R u+rwX,g+rX,o+rX /app && chmod -R u+rwX,g+rX,o-rwx storage bootstrap/cache public/uploads
STOPSIGNAL SIGTERM
CMD ["supervisord"]
