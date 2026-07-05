"""
Sidecar de renderizado de informes PDF.

Patrón invertido del spec: Symfony fusiona los datos (vistas de PostgreSQL)
y los POSTea aquí ya listos; este servicio solo pinta. No toca la base de
datos para renderizar. La ingesta de Garmin es responsabilidad del sidecar
existente (garmin_informe.py), no de este módulo.

    POST /render  (cabecera X-Auth-Token)  →  application/pdf
"""

import base64
import io
import os
import threading

import matplotlib

matplotlib.use("Agg")  # sin display: solo generamos PNG en memoria
import matplotlib.pyplot as plt
import psycopg
from fastapi import BackgroundTasks, FastAPI, Header, HTTPException, Response
from jinja2 import Environment, FileSystemLoader, select_autoescape
from pydantic import BaseModel
from weasyprint import HTML

SIDECAR_TOKEN = os.environ.get("SIDECAR_TOKEN", "")

app = FastAPI(title="Sidecar de informes", docs_url=None, redoc_url=None)

jinja = Environment(
    loader=FileSystemLoader(os.path.join(os.path.dirname(__file__), "templates")),
    autoescape=select_autoescape(["html"]),
)


class RenderPayload(BaseModel):
    """Payload flexible: el contrato real lo define ReportDataBuilder (Symfony)."""

    atleta: str
    entrenador: str
    rango: dict
    generado: str
    resumen: dict
    dias: list
    actividades: list
    notas: str | None = None
    mesociclo: dict | None = None   # contexto del bloque (nº, objetivo, semana X/Y, pauta)
    semanas: list = []              # resumen semanal vs pauta


def _grafica_pasos(dias: list) -> str | None:
    """Barras de pasos vs objetivo, devuelta como data-URI PNG para el HTML."""
    if not dias:
        return None
    etiquetas = [d["day"][5:] for d in dias]  # MM-DD
    pasos = [d["steps"] for d in dias]
    metas = [d["goal"] for d in dias]
    colores = ["#8a9a7b" if d["goal_met"] else "#a6a69c" for d in dias]

    fig, ax = plt.subplots(figsize=(10, 2.8), dpi=150)
    ax.bar(etiquetas, pasos, color=colores, width=0.7)
    ax.step(range(len(metas)), metas, where="mid", color="#b8860b", linewidth=1.2, label="Objetivo")
    ax.set_ylabel("Pasos")
    ax.legend(loc="upper right", frameon=False, fontsize=8)
    ax.spines[["top", "right"]].set_visible(False)
    plt.xticks(rotation=70, fontsize=6)
    plt.tight_layout()

    buf = io.BytesIO()
    fig.savefig(buf, format="png")
    plt.close(fig)
    return "data:image/png;base64," + base64.b64encode(buf.getvalue()).decode()


@app.post("/render")
def render(payload: RenderPayload, x_auth_token: str = Header(default="")) -> Response:
    if not SIDECAR_TOKEN or x_auth_token != SIDECAR_TOKEN:
        raise HTTPException(status_code=401, detail="token inválido")

    html = jinja.get_template("informe.html").render(
        **payload.model_dump(),
        grafica_pasos=_grafica_pasos(payload.dias),
    )
    pdf = HTML(string=html).write_pdf()

    return Response(content=pdf, media_type="application/pdf")


@app.get("/health")
def health() -> dict:
    return {"status": "ok"}


# ---------------------------------------------------------------------------
# Disparo de la ingesta de Garmin.
# Lo llama Symfony (fire-and-forget) cada vez que la app Android sincroniza:
# así el dato de Garmin llega "a la vez" que el del móvil. Protecciones:
#   - lock: nunca dos ingestas solapadas
#   - cooldown: si la última corrida es reciente, se ignora el disparo
#     (la app sincroniza en cada apertura + cada 6h; Garmin no merece ese ritmo)
# ---------------------------------------------------------------------------

import garmin_sync  # noqa: E402  (import tardío: matplotlib ya configurado)

PG_DSN = os.environ.get("PG_DSN", "postgresql://health:health@db:5432/health")
COOLDOWN_MIN = int(os.environ.get("GARMIN_SYNC_COOLDOWN_MIN", "30"))

_garmin_lock = threading.Lock()


def _last_run_recent() -> bool:
    try:
        with psycopg.connect(PG_DSN, autocommit=True) as conn:
            row = conn.execute(
                "SELECT value_ts > now() - make_interval(mins => %s) "
                "FROM sync_state WHERE key = 'garmin_sidecar_last_run'",
                (COOLDOWN_MIN,),
            ).fetchone()
        return bool(row and row[0])
    except Exception:
        return False  # si no se puede comprobar, mejor sincronizar


def _run_garmin_sync() -> None:
    if not _garmin_lock.acquire(blocking=False):
        return
    try:
        garmin_sync.run()
    except Exception as e:  # nunca tumbar el proceso del sidecar
        print(f"[sync-garmin] error: {e}")
    finally:
        _garmin_lock.release()


@app.post("/sync-garmin", status_code=202)
def sync_garmin(
    background: BackgroundTasks,
    force: bool = False,
    x_auth_token: str = Header(default=""),
) -> dict:
    if not SIDECAR_TOKEN or x_auth_token != SIDECAR_TOKEN:
        raise HTTPException(status_code=401, detail="token inválido")

    if _garmin_lock.locked():
        return {"status": "en_curso"}
    if not force and _last_run_recent():
        return {"status": "cooldown", "minutos": COOLDOWN_MIN}

    background.add_task(_run_garmin_sync)
    return {"status": "lanzado"}
