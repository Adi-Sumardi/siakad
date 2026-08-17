FROM node:22-alpine AS build

WORKDIR /app

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./

# Baked in at build time - Next.js inlines NEXT_PUBLIC_* vars into the client
# bundle, so this must be set before `next build`, not just at container
# runtime. Same-origin in production (Nginx proxies /api on the same host),
# so this stays empty - see src/lib/api.ts.
ENV NEXT_PUBLIC_API_URL=""
RUN npm run build

FROM node:22-alpine AS runtime
WORKDIR /app
ENV NODE_ENV=production
ENV PORT=3000

COPY --from=build /app/.next/standalone ./
COPY --from=build /app/.next/static ./.next/static
COPY --from=build /app/public ./public

EXPOSE 3000
CMD ["node", "server.js"]
