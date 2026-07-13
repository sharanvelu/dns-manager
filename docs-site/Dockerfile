# DNS Manager docs site — static export served by unprivileged nginx.
#
# Build context must be the REPO ROOT (the site reads ../docs/content and
# ../VERSION at build time):
#
#   docker build -f docs-site/Dockerfile -t dns-manager-docs .

# ---- build stage ----
FROM node:22-alpine AS build

WORKDIR /build/docs-site

COPY docs-site/package.json docs-site/package-lock.json docs-site/.npmrc ./
RUN npm ci

# Shared content contract: markdown source + version file from the repo root.
COPY VERSION /build/VERSION
COPY docs/content /build/docs/content

COPY docs-site/ ./
RUN npm run build

# ---- runtime stage ----
FROM nginxinc/nginx-unprivileged:alpine

# Runs as the unprivileged nginx user (uid 101) and listens on 8080.
COPY docs-site/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /build/docs-site/out /usr/share/nginx/html

EXPOSE 8080
