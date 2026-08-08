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
FROM node:24-slim AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

# Runtime stage
#
# node:24-slim, not 20: Node 20 left maintenance in April 2026, so an image built
# on it stops receiving runtime security fixes — and `npm audit` cannot see that,
# because it audits packages and not the interpreter under them. 24 is the
# current LTS line, which buys the longest runway before this decision repeats.
FROM node:24-slim AS runtime
WORKDIR /app
COPY --from=build /app/node_modules ./node_modules
COPY server.js ./
ENV NODE_ENV=production \
    PORT=3000

# Drop root. This process holds the signing key; nothing it does needs uid 0, and
# the node images already ship an unprivileged `node` user. Ownership is set
# explicitly rather than relying on the COPY default, so the read-only root
# filesystem the compose file mounts cannot be the thing that surfaces a
# permission problem at runtime.
RUN chown -R node:node /app
USER node

EXPOSE 3000

# No curl, no wget, no openssl in this image (NOTES Step 21), so the check is
# node's own fetch. It reports the process's view of itself, which is what an
# orchestrator needs before routing to it.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD node -e "fetch('http://127.0.0.1:'+(process.env.PORT||3000)+'/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", "server.js"]