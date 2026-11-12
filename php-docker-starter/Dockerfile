# ------------------------------------------------------------
# Dockerfile for the PHP + Apache web application
# ------------------------------------------------------------
#
# A Dockerfile is a set of instructions Docker uses to BUILD
# a Docker IMAGE.
#
# Later, Docker Compose will create a running CONTAINER from
# this image.
# ------------------------------------------------------------

# FROM selects the starting/base image.
#
# php:8.3-apache already contains:
#   - Linux
#   - Apache web server
#   - PHP 8.3
#
# This saves us from manually installing PHP and Apache.
FROM php:8.3-apache


# RUN executes a command while Docker is building the image.
#
# Our PHP application needs to connect to MySQL.
#
# PDO is PHP's database access interface.
# pdo_mysql is the PDO driver specifically for MySQL.
RUN docker-php-ext-install pdo pdo_mysql


# COPY moves files from our computer into the Docker image.
#
# Here:
#   src/              = our local application source code
#   /var/www/html/    = Apache's default web directory
#
# After this instruction, index.php and style.css will exist
# inside the container at /var/www/html/.
COPY src/ /var/www/html/


# EXPOSE documents the port the application listens on
# inside the container.
#
# Apache listens on port 80 by default.
#
# EXPOSE does NOT make the application available on the host
# by itself. The actual host-to-container mapping is created
# in docker-compose.yml.
EXPOSE 80
