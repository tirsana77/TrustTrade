# PHP + MySQL Docker Starter Project

This starter project demonstrates how to containerize a simple full-stack PHP application using Docker and Docker Compose.

## 1. Project Structure

```text
php-docker-starter/
│
├── Dockerfile
├── docker-compose.yml
├── .dockerignore
├── README.md
│
├── src/
│   ├── index.php
│   └── style.css
│
└── db/
    └── init.sql
```

## 2. Architecture

```text
Browser
   |
   | http://localhost:8080
   v
PHP + Apache Container
   |
   | Docker internal network
   v
MySQL Container
   |
   v
Docker Volume: mysql_data
```

The project contains two services:

- `web`: PHP 8.3 + Apache
- `db`: MySQL 8.4

Docker Compose creates a private network between the two containers automatically.

---

# 3. Dockerfile Explained

The Dockerfile builds the image used by the `web` service.

```dockerfile
FROM php:8.3-apache
```

`FROM` selects the base image. We start with an image that already contains PHP and Apache.

```dockerfile
RUN docker-php-ext-install pdo pdo_mysql
```

`RUN` executes a command while the image is being built. This installs the PHP extensions needed to connect to MySQL.

```dockerfile
COPY src/ /var/www/html/
```

`COPY` moves the application files from the local `src` folder into Apache's default web directory inside the image.

```dockerfile
EXPOSE 80
```

`EXPOSE` documents that Apache listens on port 80 inside the container.

Important: `EXPOSE 80` does not automatically expose the application to your computer. The actual port mapping is configured in `docker-compose.yml`.

---

# 4. Docker Compose Explained

The root section is:

```yaml
services:
```

A service usually becomes one running container.

This project defines:

```yaml
services:
  web:
  db:
```

So the application has two main containers.

## 4.1 Web Service

```yaml
web:
  build: .
```

`build: .` means Docker Compose should build an image using the Dockerfile in the current directory.

```yaml
container_name: php_web
```

This gives the running container a readable name.

```yaml
ports:
  - "8080:80"
```

Port mappings use:

```text
HOST_PORT:CONTAINER_PORT
```

Therefore:

```text
8080:80
```

means:

```text
localhost:8080
        |
        v
container port 80
        |
        v
Apache
```

The application will therefore be available at:

```text
http://localhost:8080
```

## 4.2 Web Environment Variables

```yaml
environment:
  DB_HOST: db
  DB_NAME: studentdb
  DB_USER: appuser
  DB_PASSWORD: apppassword
```

Docker Compose passes these values into the PHP container.

PHP reads them with:

```php
getenv('DB_HOST')
```

The important line is:

```yaml
DB_HOST: db
```

`db` is the Docker Compose service name.

Inside the PHP container, `localhost` means the PHP container itself. The database is running in another container, so PHP connects to hostname `db`.

## 4.3 depends_on

```yaml
depends_on:
  db:
    condition: service_healthy
```

This tells Docker Compose that the web application depends on the database.

The web service waits until MySQL's health check succeeds.

## 4.4 Database Image

```yaml
db:
  image: mysql:8.4
```

We do not need to create a Dockerfile for MySQL. Docker downloads the official MySQL 8.4 image.

## 4.5 Database Environment Variables

```yaml
environment:
  MYSQL_ROOT_PASSWORD: rootpassword
  MYSQL_DATABASE: studentdb
  MYSQL_USER: appuser
  MYSQL_PASSWORD: apppassword
```

The official MySQL image understands these variables and creates the database and user during initialization.

These passwords are intentionally simple for classroom use. Do not use them in a production deployment.

## 4.6 Docker Volumes

```yaml
volumes:
  - mysql_data:/var/lib/mysql
```

This stores MySQL data in a Docker-managed volume.

Containers are disposable. Volumes provide persistent storage.

If the MySQL container is removed and recreated, the database can still remain in the `mysql_data` volume.

The second mount is:

```yaml
- ./db/init.sql:/docker-entrypoint-initdb.d/init.sql:ro
```

This makes our local SQL file available inside the MySQL container.

The official MySQL image automatically executes `.sql` files placed in:

```text
/docker-entrypoint-initdb.d/
```

during first-time initialization.

`:ro` means read-only.

## 4.7 Health Check

```yaml
healthcheck:
```

A container can be running before MySQL is ready to accept connections.

The health check runs:

```text
mysqladmin ping
```

until MySQL responds.

The settings:

```yaml
interval: 5s
timeout: 5s
retries: 10
```

mean:

- test every 5 seconds
- allow each test up to 5 seconds
- fail after 10 unsuccessful attempts

## 4.8 Named Volume Declaration

At the bottom:

```yaml
volumes:
  mysql_data:
```

declares the Docker-managed volume used by the database service.

---

# 5. Build and Run

Open PowerShell or the VS Code terminal in the project folder.

Run:

```powershell
docker compose up --build
```

Docker will:

1. Read `docker-compose.yml`.
2. Build the PHP image from the Dockerfile.
3. Download MySQL if necessary.
4. Create the Docker network.
5. Create the `mysql_data` volume.
6. Start MySQL.
7. Run the database initialization script on first startup.
8. Wait for MySQL to become healthy.
9. Start the PHP application.

Open:

```text
http://localhost:8080
```

---

# 6. Run in the Background

```powershell
docker compose up -d --build
```

`-d` means detached mode.

---

# 7. Check Running Containers

```powershell
docker compose ps
```

or:

```powershell
docker ps
```

You should see:

```text
php_web
mysql_db
```

---

# 8. View Images

```powershell
docker images
```

Remember:

```text
Dockerfile -> docker build -> Image -> docker run -> Container
```

An image is the reusable template.

A container is a running instance of an image.

---

# 9. View Logs

All services:

```powershell
docker compose logs
```

PHP only:

```powershell
docker compose logs web
```

MySQL only:

```powershell
docker compose logs db
```

Follow logs continuously:

```powershell
docker compose logs -f
```

---

# 10. Enter the PHP Container

```powershell
docker exec -it php_web bash
```

Inside the container:

```bash
cd /var/www/html
ls
```

You should see:

```text
index.php
style.css
```

Exit:

```bash
exit
```

---

# 11. Enter MySQL

```powershell
docker exec -it mysql_db mysql -u appuser -papppassword studentdb
```

Then:

```sql
SHOW TABLES;
```

and:

```sql
SELECT * FROM messages;
```

Exit:

```sql
exit;
```

---

# 12. Stop the Application

```powershell
docker compose down
```

This removes the containers but keeps the named volume.

Start again:

```powershell
docker compose up
```

Previously stored messages should still exist.

---

# 13. Delete the Database Volume Too

```powershell
docker compose down -v
```

The `-v` option removes the named volume.

The next startup will initialize a fresh database.

---

# 14. Important Learning Points

By completing this starter project you are practicing:

- creating a Dockerfile
- building a Docker image
- creating Docker containers
- using Docker Compose
- connecting multiple containers
- Docker DNS and service names
- port mapping
- environment variables
- database initialization
- health checks
- persistent volumes
- reading logs
- entering running containers
- container lifecycle management

## Core mental model

```text
Dockerfile
    |
    v
Docker Image
    |
    v
Docker Container
```

For this application:

```text
                 Docker Compose
                       |
          +------------+-------------+
          |                          |
          v                          v
   PHP/Apache Image            MySQL Image
          |                          |
          v                          v
   php_web Container          mysql_db Container
          |                          |
          +------Docker Network------+
                                     |
                                     v
                              mysql_data Volume
```
