"""
Bootstrap de autenticación Garmin (UNA sola vez, interactivo).

Hace el login completo con email + contraseña + MFA vía garth y persiste el
blob de tokens en garmin_auth_token. A partir de aquí, garmin_sync.py solo
carga/refresca sin volver a pedir el MFA (~1 año de validez del OAuth1).

Uso:  docker compose exec sidecar python bootstrap_garmin.py
"""

import getpass
import os
from datetime import datetime, timedelta, timezone

import garth
import psycopg

PG_DSN = os.environ.get("PG_DSN")
if not PG_DSN:
    raise RuntimeError("Falta la variable de entorno PG_DSN (debe definirla docker-compose).")


def main() -> None:
    email = input("Email de Garmin Connect: ").strip()
    password = getpass.getpass("Contraseña: ")

    # garth gestiona el MFA: pedirá el código por consola si la cuenta lo tiene.
    garth.login(email, password)
    blob = garth.client.dumps()

    with psycopg.connect(PG_DSN, autocommit=True) as conn:
        conn.execute(
            """
            INSERT INTO garmin_auth_token (id, account_label, oauth1_token,
                                           mfa_completed_at, oauth1_expires_at)
            VALUES (1, %s, %s, now(), %s)
            ON CONFLICT (id) DO UPDATE SET
                account_label = EXCLUDED.account_label,
                oauth1_token = EXCLUDED.oauth1_token,
                mfa_completed_at = now(),
                oauth1_expires_at = EXCLUDED.oauth1_expires_at
            """,
            (email, blob, datetime.now(timezone.utc) + timedelta(days=365)),
        )

    print(f"OK: tokens guardados para {garth.client.username}. Ya puedes lanzar la ingesta.")


if __name__ == "__main__":
    main()
