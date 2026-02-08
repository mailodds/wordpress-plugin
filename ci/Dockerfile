FROM docker.io/library/php:8.3-cli

WORKDIR /app

# Copy plugin source and test files
COPY includes/ /app/includes/
COPY mailodds.php /app/mailodds.php
COPY ci/wp_stubs.php /app/ci/wp_stubs.php
COPY ci/smoke_test.php /app/ci/smoke_test.php

CMD ["php", "/app/ci/smoke_test.php"]
