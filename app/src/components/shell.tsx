import Link from "next/link";
import { ReactNode } from "react";

import { logoutAction } from "@/app/actions";
import { DemoUser } from "@/lib/types";

type ShellProps = {
  title: string;
  subtitle: string;
  user: DemoUser;
  children: ReactNode;
};

export function AppShell({ title, subtitle, user, children }: ShellProps) {
  return (
    <div className="shell">
      <aside className="sidebar">
        <div>
          <p className="eyebrow">Mi Lata Vale</p>
          <h1>Prototype</h1>
          <p className="muted">
            Replica inicial del plugin WordPress con usuarios de prueba.
          </p>
        </div>

        <nav className="nav">
          <Link href={`/panel/${user.role}`}>Resumen</Link>
          <Link href="/">Inicio</Link>
          <Link href="/login">Cambiar usuario</Link>
        </nav>

        <form action={logoutAction}>
          <button className="secondaryButton" type="submit">
            Cerrar sesión
          </button>
        </form>
      </aside>

      <main className="main">
        <header className="pageHeader">
          <div>
            <p className="eyebrow">{user.role}</p>
            <h2>{title}</h2>
            <p className="muted">{subtitle}</p>
          </div>

          <div className="userCard">
            <strong>{user.fullName}</strong>
            <span>{user.rut}</span>
            <span>{user.localName ?? "Sin local"}</span>
          </div>
        </header>

        {children}
      </main>
    </div>
  );
}
