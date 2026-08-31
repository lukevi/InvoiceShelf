FROM dunglas/frankenphp:1.11.2-php8.4

ARG PHPGROUPID=1001
ARG PHPUSERID=1001
ENV PHPGROUP=laravel
ENV PHPUSER=laravel

RUN install-php-extensions \
	pdo_mysql \
	gd \
	intl \
	zip \
	pcntl \
	exif \
	imagick \
	bcmath

RUN apt-get update \
	&& apt-get install -y --no-install-recommends \
	git \
	openssh-client \
	unzip \
	sqlite3 \
	nodejs \
	npm \
	chromium \
	ghostscript \
	default-mysql-client \
	&& rm -rf /var/lib/apt/lists/*

# Run app as a non-root user.
RUN groupadd -g ${PHPGROUPID} ${PHPGROUP} \
	&& useradd -u ${PHPUSERID} -ms /bin/bash -g ${PHPGROUP} ${PHPUSER} \
	&& mkdir -p /config/caddy /data/caddy \
	&& chown -R ${PHPUSER}:${PHPGROUP} /config/caddy /data/caddy

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

USER ${PHPUSER}
RUN echo "alias ar='php artisan'" >> /home/${PHPUSER}/.bashrc

ENV SERVER_NAME=:8080
WORKDIR /app
