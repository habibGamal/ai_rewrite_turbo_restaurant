# Optional test runner for machines without Node/WSL (and for CI). Slim on purpose:
# Node 24 + only Chromium's headless shell (~110 MB) instead of the ~1 GB Playwright image.
FROM node:24-bookworm-slim
WORKDIR /e2e
COPY package.json package-lock.json* ./
RUN npm install --no-audit --no-fund \
    && npx playwright-core install --with-deps --only-shell chromium \
    && rm -rf /root/.npm
COPY . .
ENTRYPOINT ["npx", "e2e"]
CMD ["run"]
