FROM wordpress:6.4.3-php8.1-apache

ARG WOOCOMMERCE_VERSION=8.4.0

RUN set -eux; \
  apt-get update; \
  apt-get install -y --no-install-recommends ca-certificates curl less unzip; \
  curl -L -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar; \
  chmod +x /usr/local/bin/wp; \
  curl -L -o /usr/local/bin/mhsendmail https://github.com/mailhog/mhsendmail/releases/download/v0.2.0/mhsendmail_linux_amd64; \
  chmod +x /usr/local/bin/mhsendmail; \
  echo "sendmail_path = /usr/local/bin/mhsendmail --smtp-addr=mailhog:1025" > /usr/local/etc/php/conf.d/mailhog.ini; \
  curl -L -o /tmp/woocommerce.zip https://downloads.wordpress.org/plugin/woocommerce.${WOOCOMMERCE_VERSION}.zip; \
  unzip -q /tmp/woocommerce.zip -d /usr/src/wordpress/wp-content/plugins; \
  rm -f /tmp/woocommerce.zip; \
  apt-get clean; \
  rm -rf /var/lib/apt/lists/*
