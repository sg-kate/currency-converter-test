# Runtime for the site. Core, plugins and themes are installed by Composer into
# the bind-mounted project, so this image only carries PHP and Apache.
FROM php:8.3-apache

RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends \
		libfreetype6-dev \
		libjpeg62-turbo-dev \
		libpng-dev \
		libwebp-dev \
		libzip-dev \
		unzip \
	; \
	docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
	docker-php-ext-install -j"$(nproc)" \
		bcmath \
		exif \
		gd \
		mysqli \
		opcache \
		zip \
	; \
	a2enmod rewrite; \
	rm -rf /var/lib/apt/lists/*

COPY docker/config/php/php.ini /usr/local/etc/php/conf.d/zz-project.ini
COPY docker/config/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

CMD ["apache2-foreground"]
