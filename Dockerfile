FROM php:7.4-zts

LABEL version=0.1.1
LABEL maintainer="Eboubaker Bekkouche"

# Tell scraper to always output to downloads directory, which the user should mount it as a volume
ENV SCRAPER_DOCKERIZED=1

RUN apt-get update && \
    apt-get install -y ca-certificates apt-utils && \
    apt-get install -y gpg software-properties-common && \
    apt-get update && \
    add-apt-repository ppa:jonathonf/ffmpeg-4 && \
    apt-get install -y ffmpeg
RUN apt-get install -y git unzip
RUN mv /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini
RUN pear config-set php_ini /usr/local/etc/php/php.ini
RUN pecl install parallel
RUN docker-php-ext-install opcache

RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
    php composer-setup.php && \
    php -r "unlink('composer-setup.php');" && \
    mv composer.phar /usr/local/bin/composer

COPY composer* /app/
COPY src /app/src
COPY LICENSE.txt /app/

RUN mkdir /downloads
WORKDIR /app
RUN composer install --no-dev
RUN rm -rf /tmp/**

RUN apt-get remove -y apt-utils software-properties-common git unzip
RUN apt autoremove --purge -y
RUN apt-get clean

ENTRYPOINT ["php", "src/scrap.php"]
