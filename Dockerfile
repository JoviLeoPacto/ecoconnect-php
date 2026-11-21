FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql \
 && a2dismod -f mpm_event mpm_worker mpm_prefork || true \
 && rm -f /etc/apache2/mods-enabled/mpm_* \
 && a2enmod mpm_prefork rewrite \
 && ls /etc/apache2/mods-enabled | grep mpm
# Railway gives the port in $PORT; honour .htaccess files
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf \
 && printf '<Directory /var/www/html>\n  AllowOverride All\n</Directory>\n' > /etc/apache2/conf-available/ecoconnect.conf \
 && a2enconf ecoconnect
ENV PORT=8080
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html
# Safety net: make sure only mpm_prefork is enabled when the container starts
CMD ["sh","-c","rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*; exec apache2-foreground"]
