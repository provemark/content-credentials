# syntax=docker/dockerfile:1

# Build stage — install deps. @contentauth/c2pa-node's postinstall downloads the
# prebuilt native binary for the build platform (needs network during build).
FROM node:20-slim AS build
WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev

# Runtime stage
FROM node:20-slim AS runtime
WORKDIR /app
COPY --from=build /app/node_modules ./node_modules
COPY server.js ./
ENV NODE_ENV=production \
    PORT=3000
EXPOSE 3000
CMD ["node", "server.js"]