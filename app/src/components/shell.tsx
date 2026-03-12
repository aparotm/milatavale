import Link from "next/link";
import { ReactNode } from "react";

import { logoutAction, stopImpersonationAction } from "@/app/actions";
import { AppUser } from "@/lib/types";

type ShellProps = {
  title: string;
  subtitle: string;
  user: AppUser;
  adminViewer?: AppUser | null;
  actions?: ReactNode;
  variant?: "frontend" | "admin";
  children: ReactNode;
};

export function AppShell({
  title,
  subtitle,
  user,
  adminViewer,
  actions,
  variant = "frontend",
  children,
}: ShellProps) {
  return (
    <div className={`appFrame ${variant === "admin" ? "appFrameAdmin" : "appFrameFrontend"}`}>
      {adminViewer ? (
        <div className="adminPreviewBar">
          <div className="adminPreviewMeta">
            <strong>Viendo como:</strong> {user.fullName} ({user.role})
            <span>Admin original: {adminViewer.fullName}</span>
          </div>
          <div className="adminPreviewActions">
            <Link className="ghostButton" href={`/panel/${user.role}`}>
              Ir al panel actual
            </Link>
            <form action={stopImpersonationAction}>
              <button className="secondaryButton" type="submit">
                Volver al admin
              </button>
            </form>
          </div>
        </div>
      ) : null}
      <header className={`topbar ${variant === "admin" ? "topbarAdmin" : "topbarFrontend"}`}>
        <Link className="brand" href={`/panel/${user.role}`}>
          <div className="brandMark">
            <span>MI LATA VALE</span>
          </div>
        </Link>

        {variant === "frontend" ? (
          <div className="topbarCenter">
            <Link href={`/panel/${user.role}`}>Panel</Link>
            <Link href="/">Inicio</Link>
          </div>
        ) : (
          <div className="topbarCenter topbarCenterAdmin">
            <span>Mi Lata Vale Admin</span>
          </div>
        )}

        <div className="topbarActions">
          {actions}
          <form action={logoutAction}>
            <button className="dangerButton" type="submit">
              Cerrar Sesión
            </button>
          </form>
        </div>
      </header>

      <main className={`contentWrap ${variant === "admin" ? "contentWrapAdmin" : "contentWrapFrontend"}`}>
        <header className={`pageHeader ${variant === "admin" ? "pageHeaderAdmin" : "pageHeaderFrontend"}`}>
          <div>
            <h2 className="pageTitle">{title}</h2>
            <p className="muted">{subtitle}</p>
          </div>

          <div className={`userCard ${variant === "admin" ? "userCardAdmin" : "userCardFrontend"}`}>
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
