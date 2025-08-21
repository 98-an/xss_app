FROM php:8.0-apache

# Copie ton code (assure-toi que src/ contient bien index.php, etc.)
COPY ./src/ /var/www/html

# Droits corrects pour Apache
RUN chown -R www-data:www-data /var/www/html

# Port interne exposé par Apache
EXPOSE 80
