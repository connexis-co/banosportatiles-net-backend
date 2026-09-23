FROM wordpress:7.1-php8.4-apache
RUN apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends mariadb-client cron rsync openssl python3 >/dev/null \
 && rm -rf /var/lib/apt/lists/* && ln -s /usr/local/bin/php /usr/local/bin/php8.4
COPY clpctl /usr/local/bin/clpctl
RUN chmod 755 /usr/local/bin/clpctl
WORKDIR /root
ENTRYPOINT []
CMD ["sleep", "infinity"]
