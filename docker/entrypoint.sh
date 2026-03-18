#!/bin/sh
set -eu

cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
fi

php -r '
$path = ".env";
$contents = file_get_contents($path);
$values = [
    "APP_URL" => getenv("APP_URL") ?: "http://localhost:8000",
    "DB_CONNECTION" => getenv("DB_CONNECTION") ?: "mysql",
    "DB_HOST" => getenv("DB_HOST") ?: "db",
    "DB_PORT" => getenv("DB_PORT") ?: "3306",
    "DB_DATABASE" => getenv("DB_DATABASE") ?: "speciosa_api",
    "DB_USERNAME" => getenv("DB_USERNAME") ?: "speciosa",
    "DB_PASSWORD" => getenv("DB_PASSWORD") ?: "speciosa",
];

foreach ($values as $key => $value) {
    $pattern = "/^{$key}=.*$/m";
    $line = $key . "=" . $value;

    if (preg_match($pattern, $contents)) {
        $contents = preg_replace($pattern, $line, $contents);
    } else {
        $contents .= PHP_EOL . $line;
    }
}

file_put_contents($path, $contents);
';

composer install --no-interaction --prefer-dist

php artisan key:generate --force
php artisan config:clear

until php artisan migrate --force --seed; do
    sleep 2
done

exec php artisan serve --host=0.0.0.0 --port=8000
