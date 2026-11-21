FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite
# Railway gives the port in $PORT; honour .htaccess files
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /var/www/html>\n  AllowOverride All\n</Directory>\n' > /etc/apache2/conf-available/ecoconnect.conf \
 && a2enconf ecoconnect
ENV PORT=8080
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html
