FROM php:7.4-zts

LABEL version=0.0.1
LABEL maintainer="Eboubaker Bekkouche"

# Tell scrapper to always output to downloads directory, which the user should mount it as a volume
ENV SCRAPPER_DOCKERIZED=1

RUN apt update && \
    apt install -y software-properties-common && \
    apt update && \
    add-apt-repository ppa:jonathonf/ffmpeg-4 && \
    apt install -y ffmpeg
RUN apt install -y git unzip
RUN mv /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini
RUN pear config-set php_ini /usr/local/etc/php/php.ini
RUN pecl install parallel
RUN docker-php-ext-install opcache

RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
    php composer-setup.php && \
    php -r "unlink('composer-setup.php');" && \
    mv composer.phar /usr/local/bin/composer

WORKDIR /app
COPY composer* /app/
COPY src /app/src
COPY LICENSE.txt /app/
RUN composer install --no-dev

RUN rm -rf /tmp/**
ENTRYPOINT ["php", "src/scrap.php"]
