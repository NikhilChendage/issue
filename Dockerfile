FROM php:8.2-fpm

RUN apt-get update && apt-get install -y nginx \
    && docker-php-ext-install pdo pdo_mysql

WORKDIR /var/www/html
COPY . /var/www/html

RUN mkdir -p /var/www/html/uploads && chmod -R 777 /var/www/html/uploads


COPY start.sh /start.sh
RUN chmod +x /start.sh

CMD ["/start.sh"]
