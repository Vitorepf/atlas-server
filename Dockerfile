FROM debian:bookworm-slim AS whisper-builder

RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    ca-certificates \
    cmake \
    curl \
    git \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt

RUN git clone --depth 1 https://github.com/ggerganov/whisper.cpp.git \
    && cd whisper.cpp \
    && cmake -B build -DGGML_NATIVE=OFF -DBUILD_SHARED_LIBS=OFF \
    && cmake --build build --config Release -j --target whisper-cli \
    && bash ./models/download-ggml-model.sh large-v3-turbo

FROM php:8.4-cli-bookworm AS app

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    ffmpeg \
    git \
    imagemagick \
    libgomp1 \
    libpq-dev \
    libreoffice \
    poppler-utils \
    python3 \
    python3-venv \
    python3-pip \
    tesseract-ocr \
    tesseract-ocr-eng \
    tesseract-ocr-osd \
    tesseract-ocr-por \
    unzip \
    wget \
    && docker-php-ext-install pcntl pdo_pgsql opcache \
    && rm -rf /var/lib/apt/lists/*

RUN wget -q https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O /usr/local/bin/yt-dlp \
    && chmod +x /usr/local/bin/yt-dlp \
    && yt-dlp --version

# OPcache config — bytecode cache em shared memory + JIT pra hot paths.
# Sem isso, cada request HTTP recompila os ~15-18k arquivos PHP do Atlas;
# com isso, primeira request paga o custo, demais reusam memória.
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=whisper-builder /opt/whisper.cpp/build/bin/whisper-cli /usr/local/bin/whisper-cli
COPY --from=whisper-builder /opt/whisper.cpp/models/ggml-large-v3-turbo.bin /opt/whisper-models/ggml-large-v3-turbo.bin

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .

# semantic_rag: venv Linux NATIVO fora do bind-mount (/app traz o venv do macOS
# do host no runtime, cujo binário não roda no Linux). Aqui o container ganha
# embedding vetorial REAL local (fastembed, soberania local-first); o PHP acha
# via ATLAS_PYTHON_VENV_ROOT=/opt/atlas-python (docker-compose). O warm baixa o
# modelo no build p/ runtime offline; se a rede do build falhar, não derruba a
# imagem (primeiro embed em runtime baixa uma vez).
RUN python3 -m venv /opt/atlas-python/semantic_rag/.venv \
    && /opt/atlas-python/semantic_rag/.venv/bin/pip install -q --no-cache-dir --upgrade pip \
    && /opt/atlas-python/semantic_rag/.venv/bin/pip install -q --no-cache-dir "/app/runtimes/python/semantic_rag[local]" \
    && /opt/atlas-python/semantic_rag/.venv/bin/python -c "from atlas_semantic_rag.embeddings import resolve_embedder; e=resolve_embedder(); e.embed(['warmup']); print('semantic_rag ready:', e.name, e.model, e.dim)" || echo "[semantic_rag] warm adiado para runtime (build sem rede)"

RUN mkdir -p \
    bootstrap/cache \
    storage/app/private \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/transcriptions \
    storage/framework/views \
    storage/logs \
    /var/atlas/storage \
    && composer dump-autoload --optimize \
    && php artisan package:discover --ansi

EXPOSE 3737

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
  CMD wget --no-verbose --tries=1 --spider http://127.0.0.1:3737/health || exit 1

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=3737"]
