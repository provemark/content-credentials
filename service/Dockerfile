# syntax=docker/dockerfile:1

# Build stage — install deps. @contentauth/c2pa-node's postinstall downloads the
# prebuilt native binary for the build platform (needs network during build).
#
# npm ci, not npm install: install reads only package.json, so every transitive
# dependency is re-resolved to whatever is newest-satisfying at build time and
# the lockfile is ignored. That makes builds irreproducible, and it means a
# security pin recorded in package-lock.json never reaches the image. ci
# installs exactly the locked tree and fails loudly if the lockfile and
# package.json have drifted apart.
FROM node:20-slim AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

# Runtime stage
FROM node:20-slim AS runtime
WORKDIR /app
COPY --from=build /app/node_modules ./node_modules
COPY server.js ./
ENV NODE_ENV=production \
    PORT=3000
EXPOSE 3000
CMD ["node", "server.js"]