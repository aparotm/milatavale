import Link from "next/link";
import { ReactNode } from "react";

import { logoutAction } from "@/app/actions";
import { AppUser } from "@/lib/types";

type ShellProps = {
  title: string;
  subtitle: string;
  user: AppUser;
  actions?: ReactNode;
  children: ReactNode;
};

export function AppShell({
  title,
  subtitle,
  user,
  actions,
  children,
}: ShellProps) {
  return (
    <div className="appFrame">
      <header className="topbar">
        <Link className="brand" href={`/panel/${user.role}`}>
          <div className="brandMark">
            <span>MI LATA VALE</span>
          </div>
        </Link>

        <div className="topbarCenter">
          <Link href={`/panel/${user.role}`}>Panel</Link>
          <Link href="/">Inicio</Link>
        </div>

        <div className="topbarActions">
          {actions}
          <form action={logoutAction}>
            <button className="dangerButton" type="submit">
              Cerrar Sesión
            </button>
          </form>
        </div>
      </header>

      <main className="contentWrap">
        <header className="pageHeader">
          <div>
            <h2 className="pageTitle">{title}</h2>
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
