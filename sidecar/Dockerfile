FROM python:3.12-slim

# Dependencias nativas de WeasyPrint (pango/cairo)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpango-1.0-0 libpangocairo-1.0-0 libcairo2 libgdk-pixbuf-2.0-0 \
        fonts-liberation \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY . .

# Usuario sin privilegios para ejecutar el proceso (evita correr como root)
RUN useradd --create-home --shell /usr/sbin/nologin sidecar \
    && chown -R sidecar:sidecar /app
ENV HOME=/app \
    MPLCONFIGDIR=/app/.cache/matplotlib
USER sidecar

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
